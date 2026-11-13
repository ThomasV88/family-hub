---
phase: 04-wishlist
plan: 04
subsystem: wishlist-fetch
tags: [ssrf, guzzle, curl, dom, security]
requires: []
provides:
  - "App\\Wishlist\\Fetch\\SafeFetcher (get(url, maxBytes, kind): FetchResult, throws FetchFailed)"
  - "App\\Wishlist\\Fetch\\MetaImageExtractor::candidates(html, baseUrl)"
affects: [04-07]
tech-stack:
  added: []
  patterns: ["resolve once + CURLOPT_RESOLVE pin + PREREQFUNCTION IP check", "manual redirects re-validated per hop", "injectable DnsResolver/IpPolicy"]
key-files:
  created:
    - app/Wishlist/Fetch/ (SafeFetcher, FetchResult, FetchFailed, IpPolicy, PublicIpv4Policy, DnsResolver, SystemDnsResolver, UrlResolver, MetaImageExtractor)
    - tests/Support/FakeDnsResolver.php
    - tests/Support/AllowLoopbackIpPolicy.php
    - tests/fixtures/fetch/ (router.php, cp1250.html, jsonld-graph.html)
    - tests/Unit/Wishlist/Fetch/ (SafeFetcherTest, SafeFetcherSocketTest, MetaImageExtractorTest)
decisions:
  - "Byte cap for chunked bodies uses Guzzle's 'progress' callback throwing FetchFailed('too_large'); assumption A6 confirmed by the real-socket test, no stream fallback needed"
  - "Slow-drip/read stall uses CURLOPT_LOW_SPEED_LIMIT 1024 B/s for 5 s (aborts at 5 s) plus total timeout 10 s"
  - "IP literal and numeric hosts throw blocked_address; other syntax problems throw bad_url"
metrics:
  tasks: 2
  completed: 2026-11-13
---

# Phase 4 Plan 04: SSRF-safe fetcher and meta image extractor Summary

SafeFetcher (validate, single DNS resolve, IPv4 denylist, CURLOPT_RESOLVE pin with connected-IP check, manual redirects, no env proxy, byte cap) and MetaImageExtractor (og/twitter/link/JSON-LD candidates), proven with a mocked transport and a real php -S socket on 127.0.0.1.

## Commits
- 6b5b4be: IpPolicy, DnsResolver, UrlResolver, SafeFetcher with mocked-handler tests
- 230a7fa: real-socket tests, router fixture, MetaImageExtractor and its tests

## Verified
- Fetch tests: 85 passing (70 unit, 6 socket, 9 extractor). Full Pest 673 passing, Vitest 686 passing, Pint clean.
- Socket test proves: Content-Length oversize aborts from headers; chunked 6 MiB body without length aborts at the 5 MiB cap with memory growth under 20 MB; slow drip ends in `timeout` in about 5 s; redirect to a host resolving to a non-allowed IP gives `blocked_address`; the pinned request carries `Host: fetch.test:<port>`.
- Grep gates: no FILTER_FLAG_NO_PRIV_RANGE or gethostbyname; `AllowLoopbackIpPolicy`/`allowedPorts` appear nowhere in app/ except SafeFetcher's own parameter (a test enforces this).

## Deviations from Plan
1. [Rule 1 - Bug] Guzzle 8 rejects CURLOPT_PROTOCOLS, CURLOPT_NOPROGRESS and CURLOPT_XFERINFOFUNCTION in the `curl` option. Used the `protocols` and `progress` request options instead. The mocked tests could not catch this; the real-socket test did.
2. [Rule 1 - Bug] Slow-drip surfaced as `ResponseTimeoutException` without errno 28; mapping now also checks the class and "too slow"/"timed out" messages.
3. Redirect-to-private socket test redirects to `private.test` (fake-resolved to 127.0.0.2) rather than the IP literal, because IP literals are rejected earlier by URL validation; the IP-literal case is covered in the unit test.
4. Helper functions in test files are prefixed (`fetchTestFetcher`, `metaFixture`, ...) to avoid collisions with Pest's global `fixture()` and sibling plans.

## Notes for 04-07
- Constructor and `get()` contract as in the plan; reasons: bad_url, blocked_address, too_large, too_many_redirects, http_status, timeout, network. `finalUrl` is the normalised last request URL (IDN converted to ASCII, fragment dropped).
- Extractor candidates are not percent-encoded; Guzzle encodes at request time.
- IPv6-only hosts fail with `blocked_address`.

## Known Stubs
None.

## Self-Check: PASSED
