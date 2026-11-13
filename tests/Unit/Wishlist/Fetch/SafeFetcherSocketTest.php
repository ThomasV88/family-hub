<?php

use App\Wishlist\Fetch\FetchFailed;
use App\Wishlist\Fetch\SafeFetcher;
use Symfony\Component\Process\Process;
use Tests\Support\AllowLoopbackIpPolicy;
use Tests\Support\FakeDnsResolver;

/**
 * Real-socket proof of the byte cap and time limits against a local php -S server bound to 127.0.0.1
 * only. fetch.test is mapped to 127.0.0.1 by the fake resolver; the loopback policy and the port
 * allowlist exist only in tests.
 */
beforeAll(function () {
    $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    $name = stream_socket_get_name($probe, false);
    fclose($probe);
    $GLOBALS['fetch_port'] = (int) substr($name, strrpos($name, ':') + 1);

    $GLOBALS['fetch_server'] = new Process(
        [PHP_BINARY, '-d', 'max_execution_time=0', '-S', '127.0.0.1:'.$GLOBALS['fetch_port'], __DIR__.'/../../../fixtures/fetch/router.php'],
        null,
        ['PHP_CLI_SERVER_WORKERS' => '4'],
    );
    $GLOBALS['fetch_server']->start();
    $deadline = microtime(true) + 5;
    while (microtime(true) < $deadline) {
        $s = @fsockopen('127.0.0.1', $GLOBALS['fetch_port'], $e, $m, 0.2);
        if ($s) {
            fclose($s);
            break;
        }
        usleep(50000);
    }
});

afterAll(function () {
    $GLOBALS['fetch_server']->stop(1);
});

function fetchSocketFetcher(): SafeFetcher
{
    return new SafeFetcher(
        new FakeDnsResolver(['fetch.test' => ['127.0.0.1'], 'private.test' => ['127.0.0.2']]),
        new AllowLoopbackIpPolicy,
        null,
        3,
        [$GLOBALS['fetch_port']],
    );
}

function fetchSocketUrl(string $path): string
{
    return 'http://fetch.test:'.$GLOBALS['fetch_port'].$path;
}

function fetchSocketReason(string $path, int $cap): string
{
    try {
        fetchSocketFetcher()->get(fetchSocketUrl($path), $cap, 'image');
    } catch (FetchFailed $e) {
        return $e->reason;
    }

    return 'no-exception';
}

it('fetches a small body through the pinned connection with the original Host header', function () {
    expect(fetchSocketFetcher()->get(fetchSocketUrl('/ok'), 1000, 'html')->body)->toBe('hello');
    expect(fetchSocketFetcher()->get(fetchSocketUrl('/host'), 1000, 'html')->body)->toBe('fetch.test:'.$GLOBALS['fetch_port']);
});

it('aborts on an oversize Content-Length without reading the body', function () {
    $t = microtime(true);
    expect(fetchSocketReason('/big-length', 5 * 1024 * 1024))->toBe('too_large')
        ->and(microtime(true) - $t)->toBeLessThan(8.0);
});

it('aborts an oversize chunked body without Content-Length and stays within memory', function () {
    $before = memory_get_usage();
    expect(fetchSocketReason('/big-chunked', 5 * 1024 * 1024))->toBe('too_large')
        ->and(memory_get_peak_usage() - $before)->toBeLessThan(20 * 1024 * 1024);
});

it('does not decompress a gzip bomb: the cap applies to the stored bytes, never the expanded size', function () {
    $t = microtime(true);
    $result = fetchSocketFetcher()->get(fetchSocketUrl('/gzip-bomb'), 5 * 1024 * 1024, 'html');
    // Still gzip on arrival (a few hundred KB), not 256 MB of expanded zeros.
    expect(strlen($result->body))->toBeLessThan(1024 * 1024)
        ->and(str_starts_with($result->body, "\x1f\x8b"))->toBeTrue()
        ->and(microtime(true) - $t)->toBeLessThan(8.0);
});

it('times out a slow-drip response', function () {
    $t = microtime(true);
    expect(fetchSocketReason('/drip', 1000))->toBe('timeout')
        ->and(microtime(true) - $t)->toBeLessThan(12.0);
});

it('refuses a redirect to a host that resolves to a non-allowed address', function () {
    expect(fetchSocketReason('/redirect-private', 1000))->toBe('blocked_address');
});

it('is never given a test-only escape hatch by production code', function () {
    $hits = shell_exec('grep -rnE "AllowLoopbackIpPolicy|allowedPorts" '.escapeshellarg(dirname(__DIR__, 4).'/app').' | grep -v "app/Wishlist/Fetch/SafeFetcher.php"');
    expect(trim((string) $hits))->toBe('');
    $new = shell_exec('grep -rn "new SafeFetcher" '.escapeshellarg(dirname(__DIR__, 4).'/app').' | grep allowedPorts');
    expect(trim((string) $new))->toBe('');
});
