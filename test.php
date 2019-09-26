#!/usr/bin/env php
<?php

$port = 17012;

///////////////////////////////////////////////////////////////////////

if (!class_exists('Memcached')) {
    fputs(STDERR, "Memcached extension not installed, can not perform any test!\n");
    exit(-1);
}

///////////////////////////////////////////////////////////////////////

register_shutdown_function('destroyer');

$memcache = initMemcached($port);
$failed = false;

$tests = [
    'Basic set/get/delete' => function () use ($memcache) {
        assertSame(false, $memcache->get('test'));

        $memcache->set('test', 'miew');
        assertSame('miew', $memcache->get('test'));

        $memcache->set('test', '2miew2');
        assertSame('2miew2', $memcache->get('test'));

        $memcache->delete('test');
        assertSame(false, $memcache->get('test'));
    },

    'Basic expire' => function () use ($memcache) {
        assertSame(false, $memcache->get('test'));

        $memcache->set('test', 'test-expire', 1);
        assertSame('test-expire', $memcache->get('test'));

        sleep(1);
        assertSame(false, $memcache->get('test'));
    },

    'add / replace' => function () use ($memcache) {
        assertSame(false, $memcache->get('add-replace'));

        assertSame(false, $memcache->replace('add-replace', 'test-replace'));
        assertSame(true,  $memcache->add('add-replace', 'test-add'));
        assertSame('test-add',  $memcache->get('add-replace'));

        assertSame(true, $memcache->replace('add-replace', 'test-replace-2'));
        assertSame(false,  $memcache->add('add-replace', 'test-add-2'));
        assertSame('test-replace-2',  $memcache->get('add-replace'));

        assertSame(true, $memcache->delete('add-replace'));
        assertSame(false, $memcache->get('add-replace'));
    },

    'append / prepend' => function () use ($memcache) {
        $memcache->setOption(Memcached::OPT_COMPRESSION, false); // required to test append/prepend

        assertSame(false, $memcache->get('pend'));

        assertSame(false, $memcache->append('pend', 'append1'));
        assertSame(false, $memcache->get('pend'));
        assertSame(false, $memcache->prepend('pend', 'prepend1'));
        assertSame(false, $memcache->get('pend'));

        assertSame(true, $memcache->set('pend', 'PEND'));

        assertSame(true, $memcache->append('pend', 'append2'));
        assertSame('PENDappend2', $memcache->get('pend'));
        assertSame(true, $memcache->prepend('pend', 'prepend2'));
        assertSame('prepend2PENDappend2', $memcache->get('pend'));

        assertSame(true, $memcache->delete('pend'));

        assertSame(false, $memcache->append('pend', 'append1'));
        assertSame(false, $memcache->get('pend'));
        assertSame(false, $memcache->prepend('pend', 'prepend1'));
        assertSame(false, $memcache->get('pend'));
    },
];


$maxNameLen = 0;
foreach ($tests as $name => $func) {
    $maxNameLen = max($maxNameLen, strlen($name));
}

echo "\n";
foreach ($tests as $name => $func) {
    printf(
        "\033[33m%-{$maxNameLen}s\033[m: ",
        $name
    );

    try {
        $memcache->setOption(Memcached::OPT_COMPRESSION, true);
        $errorMsg = $func();
    } catch (\Exception $e) {
        $failed = true;
        $errorMsg = $e->getMessage();
    }

    printf(
        "\033[%sm%s\033[m\n",
        $errorMsg ? '1;31' : '32',
        $errorMsg ? "FAILED" : "PASSED"
    );
    if ($errorMsg) {
        $errorMsg = explode("\n", trim($errorMsg));
        $errorMsg = implode("\n  ", $errorMsg);
        echo "  " . $errorMsg, "\n";
    }
}
echo "\n";

destroyer();

exit($failed ? 1 : 0);
///////////////////////////////////////////////////////////////////////

$tfp = null;
function initMemcached($port)
{
    global $tfp;
    $cmd = "./memcache.php 127.0.0.1 $port";

    echo "starting memcache.php...\n";
    $tfp = proc_open($cmd, [
        ['file', '/dev/null', 'r'],
        ['file', '/dev/null', 'w'],
        ['file', '/dev/null', 'w'],
    ], $pipes);
    usleep(50 * 1000);
    echo "server started\n";

    $memcache = new Memcached();
    $memcache->addServer('localhost', $port);
    return $memcache;
}

function stopMemcached()
{
    global $tfp;
    if ($tfp) {
        $s = proc_get_status($tfp);
        $pid = $s['pid'];
        echo "killing memcache, PPID: $pid\n";
        shell_exec("pkill -P $pid");
        proc_close($tfp);
        $tfp = null;
    }
}

function destroyer()
{
    usleep(50 * 1000);
    stopMemcached();
}

function assertSame($expected, $actual, $msg = '')
{
    global $memcache;
    if ($expected !== $actual) {
        throw new \RuntimeException(sprintf(
            "%s -- Expected value %s not equal to actual value %s\n\nMemcached result msg: %s",
            $msg ?: 'Error',
            var_export($expected, true),
            var_export($actual, true),
            $memcache->getResultMessage()
        ));
    }

    echo '.';
}
