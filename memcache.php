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

    switch (strtolower($cmd)) {
    case 'set':
        return cmdSet($args, $sock);
    case 'get':
        return cmdGet($args, $sock);

    case 'version':
        return "VERSION {$GLOBALS['version']}";

    // unknown command
    default:
        return "ERROR";
    }
}


function cmdSet($args, $sock)
{
    list($key, $flags, $exp, $bytes) = explode(' ', $args);
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

    $data = substr($data, 0 , -2);
    say("data is:" . json_encode($data));
    $GLOBALS['datastore'][$key] = $data;

    return "STORED";
}

function cmdGet($args, $sock)
{
    $keys = explode(' ', $args);

    foreach($keys as $key){
        $data = $GLOBALS['datastore'][$key] ?? null;
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
