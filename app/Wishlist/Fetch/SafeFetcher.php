<?php

namespace App\Wishlist\Fetch;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ResponseTimeoutException;
use GuzzleHttp\HandlerStack;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * SSRF-safe outbound GET for wish links: validates the URL, resolves the host exactly once, checks every
 * A record against the IP policy, pins the connection to that IP (CURLOPT_RESOLVE plus a connected-IP
 * check), follows redirects manually (each hop re-validated and re-pinned), ignores environment proxies
 * and aborts at the byte cap. Failures throw FetchFailed with a short reason; no partial data is returned.
 */
final class SafeFetcher
{
    private const REDIRECT_STATUSES = [301, 302, 303, 307, 308];

    private readonly DnsResolver $dns;

    private readonly IpPolicy $policy;

    /** @var (callable(): mixed)|null */
    private $handler;

    /**
     * @param  list<int>  $allowedPorts  tests only; production code never passes this
     */
    public function __construct(
        ?DnsResolver $dns = null,
        ?IpPolicy $policy = null,
        ?callable $handler = null,
        private readonly int $maxHops = 3,
        private readonly array $allowedPorts = [80, 443],
    ) {
        $this->dns = $dns ?? new SystemDnsResolver;
        $this->policy = $policy ?? new PublicIpv4Policy;
        $this->handler = $handler;
    }

    /** @param  'html'|'image'  $kind */
    public function get(string $url, int $maxBytes, string $kind): FetchResult
    {
        for ($hop = 0; $hop <= $this->maxHops + 1; $hop++) {
            [$requestUrl, $host, $port] = $this->validate($url);
            $ip = $this->pickPublicIpv4($host);
            $response = $this->send($requestUrl, $host, $port, $ip, $maxBytes, $kind);
            $status = $response->getStatusCode();

            if (in_array($status, self::REDIRECT_STATUSES, true)) {
                if ($hop >= $this->maxHops) {
                    throw new FetchFailed('too_many_redirects');
                }
                $location = $response->getHeaderLine('Location');
                $next = $location === '' ? null : UrlResolver::resolve($requestUrl, $location);
                if ($next === null) {
                    throw new FetchFailed('bad_url');
                }
                $url = $next;

                continue;
            }

            if ($status !== 200) {
                throw new FetchFailed('http_status');
            }

            return new FetchResult(
                $this->readCapped($response, $maxBytes),
                $requestUrl,
                $status,
                $response->getHeaderLine('Content-Type') ?: null,
            );
        }

        throw new FetchFailed('too_many_redirects');
    }

    /** @return array{0: string, 1: string, 2: int} normalised request URL, ASCII host, port */
    private function validate(string $url): array
    {
        if ($url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $url) === 1) {
            throw new FetchFailed('bad_url');
        }
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new FetchFailed('bad_url');
        }
        $scheme = strtolower($parts['scheme']);
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new FetchFailed('bad_url');
        }
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        if (! in_array($port, $this->allowedPorts, true)) {
            throw new FetchFailed('bad_url');
        }

        $host = strtolower($parts['host']);
        if (str_contains($host, '[') || str_contains($host, ':') || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            throw new FetchFailed('blocked_address');
        }
        if (! preg_match('/^[\x00-\x7f]*$/', $host)) {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if ($ascii === false) {
                throw new FetchFailed('bad_url');
            }
            $host = $ascii;
        }
        // FQDN with a letter TLD: rejects single labels, decimal/hex/octal forms, trailing dots.
        if (strlen($host) > 253 || ! preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+(?:[a-z]{2,63}|xn--[a-z0-9-]{1,59})$/', $host)) {
            throw new FetchFailed('bad_url');
        }

        $explicitPort = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = ($parts['path'] ?? '') === '' ? '/' : $parts['path'];
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';

        return [$scheme.'://'.$host.$explicitPort.$path.$query, $host, $port];
    }

    private function pickPublicIpv4(string $host): string
    {
        $ips = $this->dns->resolveA($host);
        if ($ips === []) {
            throw new FetchFailed('blocked_address');
        }
        foreach ($ips as $ip) {
            if (! $this->policy->allows($ip)) {
                throw new FetchFailed('blocked_address');
            }
        }

        return $ips[0];
    }

    private function send(string $url, string $host, int $port, string $ip, int $maxBytes, string $kind): ResponseInterface
    {
        $tooLarge = false;
        $curl = [
            CURLOPT_RESOLVE => ["{$host}:{$port}:{$ip}"],
            CURLOPT_LOW_SPEED_LIMIT => 1024,
            CURLOPT_LOW_SPEED_TIME => 5,
        ];
        if (defined('CURLOPT_PREREQFUNCTION')) {
            $curl[CURLOPT_PREREQFUNCTION] = fn ($ch, $dstIp) => self::connectedIpMatches($ip, (string) $dstIp)
                ? 0
                : (defined('CURL_PREREQFUNC_ABORT') ? CURL_PREREQFUNC_ABORT : 1);
        }

        $stack = $this->handler !== null ? HandlerStack::create($this->handler) : HandlerStack::create();
        $client = new Client(['handler' => $stack]);

        try {
            return $client->request('GET', $url, [
                'allow_redirects' => false,
                'proxy' => '',
                'http_errors' => false,
                'connect_timeout' => 3,
                'timeout' => 10,
                'read_timeout' => 5,
                // No transparent decompression: the byte cap must apply to bytes actually stored (gzip bomb).
                'decode_content' => false,
                'headers' => [
                    'Accept-Encoding' => 'identity',
                    'User-Agent' => 'FamilyHubImageFetcher/1.0',
                    'Accept' => $kind === 'image' ? 'image/*' : 'text/html,application/xhtml+xml',
                ],
                'protocols' => ['http', 'https'],
                'curl' => $curl,
                // Aborts chunked bodies without Content-Length once the cap is crossed.
                'progress' => function ($dlTotal, $dlNow) use ($maxBytes, &$tooLarge): void {
                    if ($dlNow > $maxBytes) {
                        $tooLarge = true;

                        throw new FetchFailed('too_large');
                    }
                },
                'on_headers' => function (ResponseInterface $r) use ($maxBytes): void {
                    $length = $r->getHeaderLine('Content-Length');
                    if ($length !== '' && ctype_digit($length) && (int) $length > $maxBytes) {
                        throw new FetchFailed('too_large');
                    }
                },
            ]);
        } catch (Throwable $e) {
            throw $this->map($e, $tooLarge);
        }
    }

    private function map(Throwable $e, bool $tooLarge): FetchFailed
    {
        for ($t = $e; $t !== null; $t = $t->getPrevious()) {
            if ($t instanceof FetchFailed) {
                return $t;
            }
        }
        if ($tooLarge) {
            return new FetchFailed('too_large');
        }
        $errno = 0;
        if (method_exists($e, 'getHandlerContext')) {
            $errno = (int) ($e->getHandlerContext()['errno'] ?? 0);
        }
        $message = strtolower($e->getMessage());
        if ($errno === 28 || $e instanceof ResponseTimeoutException || str_contains($message, 'timed out') || str_contains($message, 'too slow')) {
            return new FetchFailed('timeout');
        }

        return new FetchFailed('network');
    }

    private function readCapped(ResponseInterface $response, int $maxBytes): string
    {
        $stream = $response->getBody();
        $stream->rewind();
        $body = '';
        while (! $stream->eof() && strlen($body) <= $maxBytes) {
            $chunk = $stream->read(65536);
            if ($chunk === '') {
                break;
            }
            $body .= $chunk;
        }
        if (strlen($body) > $maxBytes) {
            throw new FetchFailed('too_large');
        }

        return $body;
    }

    /** Binary-safe comparison of the pinned IPv4 and the address curl actually connected to. */
    public static function connectedIpMatches(string $pinned, string $primary): bool
    {
        $a = @inet_pton($pinned);
        $b = @inet_pton($primary);

        return $a !== false && $b !== false && $a === $b;
    }
}
