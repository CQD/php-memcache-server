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
    'Basic RWD' => function ($memcache) {
        assertSame(false, $memcache->get('test'));

        assertSame(true, $memcache->set('test', 'miew'));
        assertSame('miew', $memcache->get('test'));

        assertSame(true, $memcache->set('test', '2miew2'));
        assertSame('2miew2', $memcache->get('test'));

        assertSame(true, $memcache->delete('test'));
        assertSame(false, $memcache->get('test'));
    },

    'Basic expire' => function ($memcache) {
        assertSame(false, $memcache->get('test'));

        assertSame(true, $memcache->set('test', 'test-expire', 1));
        assertSame('test-expire', $memcache->get('test'));

        sleep(1);
        assertSame(false, $memcache->get('test'));
    },

    'PHP datatype RWD' => function ($memcache) {
        assertSame(false, $memcache->get('test'));

        $values = [
            'Array' => ['a'=>'A','b'=>'B'],
            'Null' => null,
        ];
        foreach ($values as $type => $value) {
            assertSame(true, $memcache->set('test', $value), "Set a {$type}");
            assertSame($value, $memcache->get('test'), "Get a {$type}");
        }

        assertSame(true, $memcache->delete('test'));
        assertSame(false, $memcache->get('test'));
    },


    'Multiple RWD' => function ($memcache) {
        $data = [
            'k1' => 'K1',
            'k2' => 'K2',
            'k3' => 'K3',
        ];

        $keys = ['k0', 'k1', 'k2', 'k3', 'k4'];

        assertSame(true, $memcache->setMulti($data));
        foreach ($data as $k => $v) {
            assertSame($v, $memcache->get($k));
        }
        assertSame($data, $memcache->getMulti($keys));

        $expected = array_fill_keys($keys, true);
        $expected['k0'] = Memcached::RES_NOTFOUND;
        $expected['k4'] = Memcached::RES_NOTFOUND;
        assertSame($expected, $memcache->deleteMulti($keys));

        assertSame([], $memcache->getMulti($keys));
        foreach ($data as $k => $v) {
            assertSame(false, $memcache->get($k));
        }
    },

    'add / replace' => function ($memcache) {
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

    'append / prepend' => function ($memcache) {
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

    'incr / decr' => function ($memcache) {
        assertSame(true, $memcache->set('txt', 'TEXT3TEXT'));
        assertSame(true, $memcache->set('num', 0));

        assertSame(false, $memcache->increment('txt'));
        assertSame(1, $memcache->increment('num'));
        assertSame(1, (int) $memcache->get('num'));
        assertSame(31, $memcache->increment('num', 30));
        assertSame(31, (int) $memcache->get('num'));

        assertSame(false, $memcache->decrement('txt'));
        assertSame(30, $memcache->decrement('num'));
        assertSame(30, (int) $memcache->get('num'));
        assertSame(20, $memcache->decrement('num', 10));
        assertSame(20, (int) $memcache->get('num'));
        assertSame(0, $memcache->decrement('num', 99));
        assertSame(0, (int) $memcache->get('num'));

        $memcache->deleteMulti(['txt', 'num']);
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
        $errorMsg = $func($memcache);
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
