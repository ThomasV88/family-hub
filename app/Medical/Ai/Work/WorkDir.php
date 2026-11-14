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
        $root = rtrim((string) config('ai.work_dir'), '/');
        if (! is_dir($root) && ! @mkdir($root, 0700, true) && ! is_dir($root)) {
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

    public static function sweepStale(int $minutes = 30): int
    {
        $root = rtrim((string) config('ai.work_dir'), '/');
        $removed = 0;
        $cutoff = time() - $minutes * 60;
        foreach (glob($root.'/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (is_link($dir)) {
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
