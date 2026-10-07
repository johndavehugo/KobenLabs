<?php

/**
 * Finds php-cgi beside the running PHP.
 *
 * Kept as a file rather than an inline `php -r` one-liner in composer.json: the single
 * quotes inside an inline snippet collide with Composer's own quoting, and a parse error in
 * a shell-escaped string is close to undebuggable.
 */
$cgi = dirname(PHP_BINARY).DIRECTORY_SEPARATOR.(PHP_OS_FAMILY === 'Windows' ? 'php-cgi.exe' : 'php-cgi');

if (! file_exists($cgi)) {
    fwrite(STDERR, '  php-cgi not found beside '.PHP_BINARY.PHP_EOL);

    exit(1);
}

$address = '127.0.0.1:9000';

fwrite(STDOUT, '  '.$cgi.' listening on '.$address.PHP_EOL);

$process = proc_open([$cgi, '-b', $address], [STDIN, STDOUT, STDERR], $pipes);

exit(proc_close($process));
