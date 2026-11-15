<?php

/*
 * Polish name days from the 12 Polish Wikipedia monthly pages "Imieniny – <month>" (CC BY-SA 4.0).
 *
 *   php build-pl.php --fetch <dir>          download wikitext + revisions.json (network)
 *   php build-pl.php --build <dir> <out>    parse stored wikitext into the normalised JSON (no network)
 *
 * Downloaded wikitext is data only and is never executed.
 */

const MONTH_NAMES = ['styczeń', 'luty', 'marzec', 'kwiecień', 'maj', 'czerwiec', 'lipiec', 'sierpień', 'wrzesień', 'październik', 'listopad', 'grudzień'];
const GENITIVE = ['stycznia', 'lutego', 'marca', 'kwietnia', 'maja', 'czerwca', 'lipca', 'sierpnia', 'września', 'października', 'listopada', 'grudnia'];

function fail(string $message): never
{
    fwrite(STDERR, $message."\n");
    exit(1);
}

function fetchAll(string $dir): void
{
    if (! is_dir($dir) && ! mkdir($dir, 0775, true)) {
        fail("cannot create $dir");
    }
    $revisions = [];
    $ctx = stream_context_create(['http' => ['header' => "User-Agent: family-hub-data-build/1.0 (name-day calendar build)\r\n", 'timeout' => 30, 'ignore_errors' => true]]);
    foreach (MONTH_NAMES as $i => $month) {
        $title = 'Imieniny – '.$month;
        $url = 'https://pl.wikipedia.org/w/api.php?'.http_build_query([
            'action' => 'parse', 'page' => $title, 'prop' => 'wikitext|revid', 'format' => 'json', 'formatversion' => 2,
        ]);
        $raw = false;
        for ($try = 0; $try < 5 && $raw === false; $try++) {
            $body = @file_get_contents($url, false, $ctx);
            $status = (int) (preg_match('#HTTP/\S+ (\d{3})#', $http_response_header[0] ?? '', $sm) ? $sm[1] : 0);
            if ($body !== false && $status === 200) {
                $raw = $body;
            } else {
                $wait = 5;
                foreach ($http_response_header ?? [] as $h) {
                    if (stripos($h, 'retry-after:') === 0) {
                        $wait = min(90, max(5, (int) trim(substr($h, 12)) + 2));
                    }
                }
                sleep($wait);
            }
        }
        if ($raw === false) {
            fail("fetch error $title");
        }
        sleep(2);
        $data = json_decode($raw, true);
        if (! isset($data['parse']['wikitext'], $data['parse']['revid'])) {
            fail("page not found $title");
        }
        $mm = sprintf('%02d', $i + 1);
        file_put_contents("$dir/$mm.wikitext", $data['parse']['wikitext']);
        $revisions[] = ['month' => $mm, 'title' => $data['parse']['title'], 'revid' => $data['parse']['revid'], 'fetched_at' => gmdate('c')];
    }
    file_put_contents("$dir/revisions.json", json_encode($revisions, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n");
}

function cleanLine(string $line): string
{
    $line = preg_replace('/<!--.*?-->/s', '', $line);
    $line = preg_replace('/<ref[^>]*>.*?<\/ref>|<ref[^>]*\/>/s', '', $line);
    $line = preg_replace('/\{\{[^{}]*\}\}/', '', $line);
    $line = preg_replace('/\[\[([^\]|]*)\|([^\]]*)\]\]/u', '$2', $line);
    $line = preg_replace('/\[\[([^\]]*)\]\]/u', '$1', $line);
    $line = str_replace(["'''", "''"], '', $line);

    return trim(ltrim($line, ':* '));
}

/** @return list<string> */
function parseNames(string $line, string $key): array
{
    $line = cleanLine($line);
    // "Wszyscy, w tym: A, B" means "everyone, including A, B": neither phrase is a name
    $line = preg_replace('/^Wszyscy\s*,?\s*(w tym:?)?\s*/u', '', $line);
    // a lowercase parenthetical is a qualifier ("w latach nieprzestępnych"), not a name
    $line = preg_replace('/\s*\(\p{Ll}[^()]*\)/u', '', $line);
    // keep alternative spellings written in parentheses as names of their own
    $line = preg_replace('/\(([^()]*)\)/u', ', $1', $line);
    $names = [];
    foreach (preg_split('/\s*,\s*|\s+i\s+/u', $line) as $token) {
        $token = trim($token, " \t.;");
        if ($token === '') {
            continue;
        }
        if (! preg_match('/^\p{Lu}[\p{L}\p{M}\'\- ]{0,40}$/u', $token)) {
            fail("parse error $key token '$token'");
        }
        $names[$token] = true;
    }

    return array_keys($names);
}

function buildAll(string $dir, string $out): void
{
    $revisions = json_decode((string) @file_get_contents("$dir/revisions.json"), true);
    if (! is_array($revisions) || count($revisions) !== 12) {
        fail('revisions.json missing or incomplete');
    }
    $days = [];
    for ($m = 1; $m <= 31; $m++) {
        for ($mo = 1; $mo <= 12; $mo++) {
            if (checkdate($mo, $m, 2024)) {
                $days[sprintf('%02d-%02d', $mo, $m)] = [];
            }
        }
    }
    ksort($days);
    foreach (MONTH_NAMES as $i => $_) {
        $mo = $i + 1;
        $mm = sprintf('%02d', $mo);
        $text = @file_get_contents("$dir/$mm.wikitext");
        if ($text === false) {
            fail("missing $mm.wikitext");
        }
        $current = null;
        foreach (preg_split('/\R/u', $text) as $line) {
            if (preg_match('/^={2,3}\s*\[\[(\d{1,2}) '.preg_quote(GENITIVE[$i], '/').'\]\]\s*={2,3}\s*$/u', $line, $mt)) {
                $current = sprintf('%02d-%02d', $mo, (int) $mt[1]);
                if (! isset($days[$current])) {
                    fail("parse error $current (invalid day)");
                }

                continue;
            }
            if ($current !== null && str_starts_with($line, ':')) {
                $days[$current] = array_values(array_unique(array_merge($days[$current], parseNames($line, $current))));
            } elseif (preg_match('/^==[^=]/', $line)) {
                $current = null;
            }
        }
    }
    $maxRev = max(array_column($revisions, 'revid'));
    $fetched = substr(min(array_column($revisions, 'fetched_at')), 0, 10);
    $doc = [
        'calendar' => 'pl',
        'version' => 'wikipedia-revids<='.$maxRev.'@'.$fetched,
        'source' => 'https://pl.wikipedia.org/wiki/Imieniny',
        'license' => 'CC BY-SA 4.0',
        'days' => $days,
    ];
    file_put_contents($out, json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n");
}

$mode = $argv[1] ?? '';
if ($mode === '--fetch' && isset($argv[2])) {
    fetchAll($argv[2]);
} elseif ($mode === '--build' && isset($argv[2], $argv[3])) {
    buildAll($argv[2], $argv[3]);
} else {
    fail('usage: build-pl.php --fetch <dir> | --build <dir> <out>');
}
