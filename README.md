# Memcached server implemented with PHP

This is a simple implementation of the Memcached in PHP.

This is intended to be a "It's fun to remake to wheels" project, and should never be used in production.

## How to use

Run the `memcached.php` script will start the server.

```shell
./memcache.php
```

Host name and port can also be specified. If not, `127.0.0.1` and `11211` will be used.

```shell
./memcache.php 127.0.0.1 11211
```

## Requirements

This is developed on a PHP 7.3 machine. *Should* work with PHP 7.0 or above, but not guaranteed.

## Testing

Run `test.php` will run the test suite. Memcached extension is required to run the test suite.
