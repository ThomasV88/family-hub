<?php

namespace App\Medical\Ai\Work;

use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/** A fresh 0700 per-job directory (on tmpfs in production) holding only app-named copies. */
final class WorkDir
{
    private function __construct(private readonly string $path) {}

    public static function create(): self
    {
        $root = self::root();
        if ($root === null || (! is_dir($root) && ! @mkdir($root, 0700, true) && ! is_dir($root))) {
            throw new RuntimeException('ai work dir unavailable');
        }
        $path = $root.'/'.strtolower((string) Str::ulid());
        if (! @mkdir($path, 0700) || ! @mkdir($path.'/tmp', 0700)) {
            throw new RuntimeException('ai work dir unavailable');
        }
        chmod($path, 0700);
        chmod($path.'/tmp', 0700);

        return new self($path);
    }

    /** A private scratch directory under the system temp dir (used by the PDF rasterizer). */
    public static function makeTempDir(): string
    {
        $path = sys_get_temp_dir().'/fh-'.strtolower((string) Str::ulid());
        if (! @mkdir($path, 0700)) {
            throw new RuntimeException('temp dir unavailable');
        }

        return $path;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function file(string $name): string
    {
        if (preg_match('/^[a-z0-9.-]{1,40}$/', $name) !== 1 || str_contains($name, '..')) {
            throw new InvalidArgumentException('invalid work file name');
        }

        return $this->path.'/'.$name;
    }

    public function put(string $name, string $bytes): string
    {
        $file = $this->file($name);
        if (file_put_contents($file, $bytes) === false) {
            throw new RuntimeException('ai work file not writable');
        }
        chmod($file, 0600);

        return $file;
    }

    public function cleanup(): void
    {
        self::removeTree($this->path);
    }

    /** Names the app gives its job directories: a lower-case ULID. Nothing else is ever swept. */
    private const JOB_DIR = '/^[0-9a-z]{26}$/';

    /**
     * The configured work dir, or null when it is not safe to create into or delete from: it must be an absolute path
     * without dot segments, not the filesystem root, and not a parent of the Codex home (which holds the login).
     */
    public static function root(): ?string
    {
        $raw = trim((string) config('ai.work_dir'));
        if ($raw === '' || $raw[0] !== '/' || preg_match('#(^|/)\.\.?(/|$)#', $raw) === 1 || str_contains($raw, "\0")) {
            return null;
        }
        $root = rtrim($raw, '/');
        if ($root === '') {
            return null;
        }
        $codex = rtrim(trim((string) config('ai.codex.home')), '/');
        if ($codex !== '' && str_starts_with($codex.'/', $root.'/') && $codex !== $root) {
            return null;
        }
        // The fixed production login location is protected even when the config points elsewhere.
        if (str_starts_with('/codex/', $root.'/')) {
            return null;
        }

        return $root;
    }

    /**
     * Removes job directories and stale rasterizer scratch dirs (and nothing else) older than the given age. Refuses to touch anything when the work dir
     * is not safe (see root()), whatever the configuration says.
     */
    public static function sweepStale(int $minutes = 30): int
    {
        $cutoff = time() - $minutes * 60;
        // Rasterizer scratch dirs (page images of a medical document) live under the system temp dir and are normally
        // removed in a finally block; a SIGKILL or OOM leaves them behind, so they are swept here too.
        $removed = self::sweepOld(sys_get_temp_dir(), '/^fh-[0-9a-z]{26}$/', $cutoff);

        $root = self::root();
        if ($root === null || ! is_dir($root)) {
            return $removed;
        }

        return $removed + self::sweepOld($root, self::JOB_DIR, $cutoff);
    }

    /** Removes direct child directories of $base whose name matches $pattern and whose mtime is before $cutoff. */
    private static function sweepOld(string $base, string $pattern, int $cutoff): int
    {
        $removed = 0;
        foreach (glob(rtrim($base, '/').'/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (is_link($dir) || preg_match($pattern, basename($dir)) !== 1) {
                continue;
            }
            $mtime = @filemtime($dir);
            if ($mtime !== false && $mtime < $cutoff) {
                self::removeTree($dir);
                $removed++;
            }
        }

        return $removed;
    }

    public static function removeTree(string $dir): void
    {
        if (is_link($dir) || ! is_dir($dir)) {
            if (is_link($dir) || is_file($dir)) {
                @unlink($dir);
            }

            return;
        }
        @chmod($dir, 0700);
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            self::removeTree($dir.'/'.$entry);
        }
        @rmdir($dir);
    }
}
