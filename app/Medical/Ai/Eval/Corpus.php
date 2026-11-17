<?php

namespace App\Medical\Ai\Eval;

/**
 * A synthetic evaluation folder, accepted only when it proves itself: it lives under the AI work dir (eval/), not in the
 * medical disk, holds a MANIFEST.json with "synthetic": true, every listed file is a regular, unlinked file whose sha256
 * matches, and nothing else is in the folder. The ground truth belongs to the repository fixtures, or to a verified
 * `truth/` copy listed in the manifest (the AI image does not ship tests/). Nothing here reads a database document.
 */
final class Corpus
{
    private const EXTENSIONS = ['png', 'jpg', 'pdf'];

    private const KINDS = ['image', 'pdf', 'probe'];

    private const MAX_ITEMS = 500;

    private const MAX_FILE_BYTES = 25_000_000;

    private const ALLOWED_EXTRA = ['MANIFEST.json', 'state.json', 'state.json.tmp', 'work', 'truth'];

    /**
     * @param  list<array{id: string, doc: string, condition: string, kind: string, file: string, sha256: string, path: string}>  $items
     * @param  array<string, string>  $truth  doc id => absolute path of the ground-truth text
     */
    private function __construct(
        public readonly string $dir,
        public readonly array $items,
        private readonly string $fixtures,
        private readonly array $truth,
    ) {}

    /**
     * @param  ?string  $root  folder the corpus must be inside (null: config ai.work_dir + /eval); tests pass a temp dir
     * @param  ?string  $fixtures  folder of the ground-truth files (null: tests/Fixtures/ocr-eval, else the manifest truth/ copy)
     */
    public static function load(string $dir, ?string $root = null, ?string $fixtures = null): self
    {
        $real = realpath($dir);
        if ($real === false || ! is_dir($real) || is_link(rtrim($dir, '/'))) {
            throw new CorpusRejected('no_directory');
        }
        $root ??= rtrim((string) config('ai.work_dir'), '/').'/eval';
        $rootReal = realpath($root);
        if ($rootReal === false || ! str_starts_with($real.'/', rtrim($rootReal, '/').'/')) {
            throw new CorpusRejected('outside_eval_root');
        }
        $configured = config('filesystems.disks.medical.root');
        $medical = is_string($configured) && $configured !== '' ? realpath($configured) : false;
        if ($medical !== false && (str_starts_with($real.'/', rtrim($medical, '/').'/') || str_starts_with(rtrim($medical, '/').'/', $real.'/'))) {
            throw new CorpusRejected('inside_private_documents');
        }

        $manifestPath = $real.'/MANIFEST.json';
        if (! is_file($manifestPath) || is_link($manifestPath) || filesize($manifestPath) > 1_000_000) {
            throw new CorpusRejected('no_manifest');
        }
        $m = json_decode((string) file_get_contents($manifestPath), true, 8);
        if (! is_array($m) || ! is_array($m['items'] ?? null) || ! array_is_list($m['items']) || $m['items'] === [] || count($m['items']) > self::MAX_ITEMS) {
            throw new CorpusRejected('bad_manifest');
        }
        if (($m['synthetic'] ?? null) !== true) {
            throw new CorpusRejected('not_synthetic');
        }
        if (! is_string($m['generator'] ?? null) || trim($m['generator']) === '') {
            throw new CorpusRejected('bad_manifest');
        }

        $items = [];
        $ids = $files = [];
        foreach ($m['items'] as $row) {
            $item = self::item($row, $real);
            if (isset($ids[$item['id']]) || isset($files[$item['file']])) {
                throw new CorpusRejected('duplicate_item');
            }
            $ids[$item['id']] = $files[$item['file']] = true;
            $items[] = $item;
        }

        $truth = [];
        foreach ((array) ($m['truth'] ?? []) as $row) {
            if (! is_array($row) || ! is_string($row['file'] ?? null) || preg_match('/^[a-z0-9][a-z0-9-]{0,40}\.(txt|expected\.json)$/', $row['file']) !== 1) {
                throw new CorpusRejected('bad_truth');
            }
            $path = $real.'/truth/'.$row['file'];
            if (! is_file($path) || is_link($path) || ! is_string($row['sha256'] ?? null) || ! hash_equals($row['sha256'], (string) hash_file('sha256', $path))) {
                throw new CorpusRejected('truth_mismatch');
            }
            $truth[$row['file']] = $path;
        }

        self::assertNoExtras($real, $files, array_keys($truth));

        if ($fixtures === null) {
            $repo = base_path('tests/Fixtures/ocr-eval');
            $fixtures = is_dir($repo) ? $repo : $real.'/truth';
        }

        return new self($real, $items, $fixtures, $truth);
    }

    /** @return array{id: string, doc: string, condition: string, kind: string, file: string, sha256: string, path: string} */
    private static function item(mixed $row, string $dir): array
    {
        if (! is_array($row)) {
            throw new CorpusRejected('bad_manifest');
        }
        foreach (['id', 'doc', 'condition', 'kind', 'file', 'sha256'] as $k) {
            if (! is_string($row[$k] ?? null)) {
                throw new CorpusRejected('bad_manifest');
            }
        }
        if (preg_match('/^[a-z0-9][a-z0-9-]{0,40}$/', $row['id']) !== 1
            || preg_match('/^[a-z0-9][a-z0-9-]{0,40}$/', $row['doc']) !== 1
            || preg_match('/^[a-z0-9][a-z0-9-]{0,20}$/', $row['condition']) !== 1
            || ! in_array($row['kind'], self::KINDS, true)
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,80}$/', $row['file']) !== 1
            || preg_match('/^[a-f0-9]{64}$/', $row['sha256']) !== 1) {
            throw new CorpusRejected('bad_manifest');
        }
        $ext = strtolower(pathinfo($row['file'], PATHINFO_EXTENSION));
        if (! in_array($ext, self::EXTENSIONS, true)) {
            throw new CorpusRejected('bad_extension');
        }
        $path = $dir.'/'.$row['file'];
        if (is_link($path) || ! is_file($path)) {
            throw new CorpusRejected(is_link($path) ? 'symlink' : 'missing_file');
        }
        $size = filesize($path);
        if ($size === false || $size > self::MAX_FILE_BYTES) {
            throw new CorpusRejected('file_too_large');
        }
        if (! hash_equals($row['sha256'], (string) hash_file('sha256', $path))) {
            throw new CorpusRejected('sha256_mismatch');
        }

        return ['id' => $row['id'], 'doc' => $row['doc'], 'condition' => $row['condition'], 'kind' => $row['kind'],
            'file' => $row['file'], 'sha256' => $row['sha256'], 'path' => $path];
    }

    /**
     * @param  array<string, true>  $listed
     * @param  list<string>  $truthFiles
     */
    private static function assertNoExtras(string $dir, array $listed, array $truthFiles): void
    {
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..' || isset($listed[$name]) || in_array($name, self::ALLOWED_EXTRA, true)) {
                if (in_array($name, ['work', 'truth'], true) && (is_link($dir.'/'.$name) || ! is_dir($dir.'/'.$name))) {
                    throw new CorpusRejected('unlisted_file');
                }

                continue;
            }
            if (str_starts_with($name, 'report') && is_file($dir.'/'.$name) && ! is_link($dir.'/'.$name)) {
                continue;
            }
            throw new CorpusRejected('unlisted_file');
        }
        if (is_dir($dir.'/truth')) {
            foreach (scandir($dir.'/truth') ?: [] as $name) {
                if ($name !== '.' && $name !== '..' && ! in_array($name, $truthFiles, true)) {
                    throw new CorpusRejected('unlisted_file');
                }
            }
        }
    }

    /** The exact ground-truth text of a document. */
    public function groundTruth(string $doc): string
    {
        return (string) file_get_contents($this->truthFile($doc.'.txt'));
    }

    /** @return array<string, mixed>|null the expected entries (null for the probe, which has none) */
    public function expected(string $doc): ?array
    {
        $file = $this->truthFile($doc.'.expected.json', false);
        if ($file === null) {
            return null;
        }
        $d = json_decode((string) file_get_contents($file), true, 16);

        return is_array($d) ? $d : null;
    }

    /** @return list<array{id: string, doc: string, condition: string, kind: string, file: string, sha256: string, path: string}> */
    public function where(?string $kind = null, ?string $doc = null, ?string $condition = null): array
    {
        return array_values(array_filter($this->items, fn (array $i) => ($kind === null || $i['kind'] === $kind)
            && ($doc === null || $i['doc'] === $doc) && ($condition === null || $i['condition'] === $condition)));
    }

    /** @return array{id: string, doc: string, condition: string, kind: string, file: string, sha256: string, path: string}|null */
    public function find(string $id): ?array
    {
        foreach ($this->items as $i) {
            if ($i['id'] === $id) {
                return $i;
            }
        }

        return null;
    }

    private function truthFile(string $name, bool $required = true): ?string
    {
        $path = isset($this->truth[$name]) ? $this->truth[$name] : $this->fixtures.'/'.$name;
        if (preg_match('/^[a-z0-9][a-z0-9-]{0,40}\.(txt|expected\.json)$/', $name) !== 1 || ! is_file($path)) {
            if ($required) {
                throw new CorpusRejected('no_ground_truth');
            }

            return null;
        }

        return $path;
    }
}
