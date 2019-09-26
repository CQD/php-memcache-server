#!/usr/bin/env php
<?php

$host = $argv[1] ?? "127.0.0.1";
$port = (int) ($argv[2] ?? 11211) ?: 11211;

$version = '0.0.1';

$datastore = [];

/////////////////////////////////////////////////////////////

set_time_limit(0);

$sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP) or socketFatalStop("socket_create() failed");
socket_set_option($sock, SOL_SOCKET, SO_REUSEADDR, 1);

socket_bind($sock, $host, $port) or socketFatalStop("socket_bind() failed");
socket_listen($sock) or socketFatalStop('socket_listen() failed');
socket_set_block($sock);

say(sprintf("Server started at %s:%s", $host, $port));
while(true){
    $msgSock = socket_accept($sock) or socketFatalStop("socket_accept() failed");
    socket_set_block($msgSock);

    while ($buf = socket_read($msgSock, 2048, PHP_NORMAL_READ)) {
        say("buf: " . json_encode($buf));

        // 清掉前一個指令的 \r\n 的 \n
        $lf = socket_read($msgSock, 1, PHP_BINARY_READ);
        if ("\n" !== $lf) {
            socket_write($msgSock, "CLIENT_ERROR command delimiter is not \\r\\n\r\n");
        }

        $buf = trim($buf);
        if (!$buf) {
            continue;
        }

        if ('exit' === $buf || 'quit' === $buf || false === $buf || null === $buf) {
            socket_write($msgSock, "BYE\r\n");
            break;
        }

        $msg = runCmd($buf, $msgSock);
        socket_write($msgSock, $msg);
        socket_write($msgSock, "\r\n");
    }
    socket_close($msgSock);
}

socket_close($sock);

/////////////////////////////////////////////////////////////

function runCmd($cmd, $sock)
{
    $args = explode(' ', $cmd, 2);
    $cmd = $args[0] ?: '???';
    $args = isset($args[1]) ? ltrim($args[1]) : '';
    $args = array_filter(explode(' ', $args), function($n){ return '' !== $n;});

    switch (strtolower($cmd)) {
    case 'set':
    case 'add':
    case 'replace':
    case 'append':
    case 'prepend':
        return cmdSet($args, $sock, $cmd);
    case 'get':
    case 'gets':
        return cmdGet($args, $sock);
    case 'delete':
        return cmdDel($args, $sock);

    case 'incr':
    case 'decr':
        return cmdIncr($args, $sock, $cmd);

    case 'version':
        return "VERSION {$GLOBALS['version']}";

    // unknown command
    default:
        return "ERROR";
    }
}

function cmdSet($args, $sock, $cmd)
{
    list($key, $flags, $exp, $bytes) = $args;
    foreach (['flags', 'exp', 'bytes'] as $fieldName) {
        if (!is_numeric($$fieldName)) {
            throw new \Exception("$fieldName should be numeric");
        }
    }

    say("reading bites: $bytes");

    $data = '';
    $currlen = 0;
    $targetSize = $bytes + 2;
    do {
        $data .= socket_read($sock, $targetSize - $currlen, PHP_BINARY_READ);
    } while(($currlen = strlen($data)) < $targetSize);

    if ("\r" !== $data[$targetSize - 2] && "\n" !== $data[$targetSize - 1]) {
        return 'CLIENT_ERROR data not terminated properly with \r\n';
    }

    $keyExists = isset($GLOBALS['datastore'][$key]);
    if (
        ('add'     === $cmd &&  $keyExists) ||
        ('replace' === $cmd && !$keyExists) ||
        ('append'  === $cmd && !$keyExists) ||
        ('prepend' === $cmd && !$keyExists)
    ) {
        return 'NOT_STORED';
    }

    $data = substr($data, 0 , -2);

    if ('prepend' === $cmd) {
        $data = $data . $GLOBALS['datastore'][$key]['data'];
    } elseif ('append' === $cmd) {
        $data = $GLOBALS['datastore'][$key]['data'] . $data;
    }

    say("data is:" . json_encode($data));
    $GLOBALS['datastore'][$key] = [
        'flags' => $flags,
        'data' => $data,
        'exp' => $exp,
        'ctime' => time(),
    ];

    return "STORED";
}

function cmdGet($keys, $sock)
{
    foreach($keys as $key){
        clearExpired($key);
        $data = $GLOBALS['datastore'][$key]['data'] ?? null;
        if (null === $data) {
            say("$key not in store");
            continue;
        }
        say("value for $key is: " . json_encode($data));
        socket_write($sock, sprintf("VALUE %s %s %s\r\n", $key, '0', strlen($data)));
        socket_write($sock, $data);
        socket_write($sock, "\r\n");
    }

    return "END";
}

function cmdDel($args, $sock)
{
    $key = $args[0];
    $keyExists = isset($GLOBALS['datastore'][$key]);
    unset($GLOBALS['datastore'][$key]);
    return $keyExists ? "DELETED" : 'NOT_FOUND';
}

function cmdIncr($args, $sock, $cmd)
{
    if (count($args) !== 2) {
        return 'ERROR';
    }

    list($key, $value) = $args;
    $origValue = $GLOBALS['datastore'][$key]['data'] ?? null;

    if (null === $origValue) {
        return 'NOT_FOUND';
    } elseif (!is_numeric($value)) {
        return 'CLIENT_ERROR invalid numeric delta argument';
    } elseif (!is_numeric($origValue)) {
        return 'CLIENT_ERROR cannot increment or decrement non-numeric value';
    }

    if ('incr' === $cmd) {
        $newValue = $origValue + $value;
    } else {
        $newValue = $origValue - $value;
    }

    $newValue = (0 > $newValue) ? 0 : $newValue;

    $GLOBALS['datastore'][$key]['data'] = $newValue;
    return $newValue;
}

function clearExpired($key)
{
    $exp = $GLOBALS['datastore'][$key]['exp'] ?? null;
    if (!$exp) {
        return;
    }

    $birth = $GLOBALS['datastore'][$key]['ctime'];
    $now = time();
    if (
        ($exp > 86400 * 30 && $now >= $exp)
        || $now - $birth >= $exp) {
        unset($GLOBALS['datastore'][$key]);
    }
}

/////////////////////////////////////////////////////////////

function socketFatalStop($msg, $sock)
{
    say(sprintf("%s -- %s"), $msg, socket_strerror(socket_last_error($sock)));
    socket_close($sock);
    die();
}
function say($msg)
{
    fputs(STDERR, sprintf("[%s] %s\n",
        date('Y-m-d H:i:s'),
        $msg
    ));
}
