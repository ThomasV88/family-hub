<?php

// Test-only router for `php -S 127.0.0.1:<port>`; see SafeFetcherSocketTest. Never deployed.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
set_time_limit(0);
ignore_user_abort(false);

switch ($path) {
    case '/ok':
        header('Content-Type: text/plain');
        echo 'hello';
        break;
    case '/host':
        header('Content-Type: text/plain');
        echo $_SERVER['HTTP_HOST'];
        break;
    case '/big-length':
        header('Content-Type: application/octet-stream');
        header('Content-Length: 6291456');
        flush();
        sleep(20); // only a header-based abort can finish within the client's time limit
        break;
    case '/big-chunked':
        header('Content-Type: application/octet-stream');
        $chunk = str_repeat('x', 65536);
        for ($i = 0; $i < 96; $i++) {
            echo $chunk;
            flush();
            if (connection_aborted()) {
                break;
            }
        }
        break;
    case '/drip':
        header('Content-Type: text/plain');
        for ($i = 0; $i < 60; $i++) {
            echo 'x';
            flush();
            sleep(1);
            if (connection_aborted()) {
                break;
            }
        }
        break;
    case '/gzip-bomb':
        // Hostile server: gzip regardless of Accept-Encoding, ~256 MB of zeros that compress to a few hundred KB.
        header('Content-Type: text/html');
        header('Content-Encoding: gzip');
        $ctx = deflate_init(ZLIB_ENCODING_GZIP);
        $zeros = str_repeat("\0", 1048576);
        $out = '';
        for ($i = 0; $i < 256; $i++) {
            $out .= deflate_add($ctx, $zeros, ZLIB_NO_FLUSH);
        }
        $out .= deflate_add($ctx, '', ZLIB_FINISH);
        header('Content-Length: '.strlen($out));
        echo $out;
        break;
    case '/redirect-private':
        http_response_code(302);
        header('Location: http://private.test:'.$_SERVER['SERVER_PORT'].'/ok');
        break;
    default:
        http_response_code(404);
}
