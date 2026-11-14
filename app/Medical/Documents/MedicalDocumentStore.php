<?php

namespace App\Medical\Documents;

use App\Wishlist\Catalog\ImageProcessor;
use App\Wishlist\Catalog\ImageRejected;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The single file IO seam for medical documents (a later encryption layer wraps only this class). Files live on the
 * private `medical` disk as {subject-uuid}/{ULID}[-full|-thumb].{jpg|pdf|heic}; the client file name never reaches
 * the disk. Images are stored only as sanitized re-encodes (ImageProcessor, metadata-free JPEG); PDF and HEIC
 * originals are stored unchanged and never decoded.
 */
final class MedicalDocumentStore
{
    private const PATH = '#^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/[0-9A-Z]{26}(-full|-thumb)?\.(jpg|pdf|heic)$#D';

    private const UUID = '#^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$#D';

    public function __construct(private readonly DocumentSniffer $sniffer, private readonly ImageProcessor $images) {}

    /**
     * @return array{kind: string, mime: string, ext: string, path: string, thumb_path: ?string, width: ?int, height: ?int, size_bytes: int, sha256: string}
     */
    public function put(string $subjectId, string $bytes): array
    {
        $this->assertSubject($subjectId);
        $max = (int) config('medical.document_max_bytes');
        if (strlen($bytes) > $max) {
            throw new DocumentRejected('too_large');
        }
        $type = $this->sniffer->sniff($bytes);
        $name = strtoupper((string) Str::ulid());

        $thumbPath = null;
        $width = null;
        $height = null;
        if ($type['kind'] === 'image') {
            try {
                $image = $this->images->process($bytes, $max, (int) config('medical.image_full_px'), (int) config('medical.image_thumb_px'));
            } catch (ImageRejected $e) {
                throw new DocumentRejected(match ($e->reason) {
                    'too_large' => 'too_large',
                    'too_many_pixels' => 'too_many_pixels',
                    'busy' => 'busy',
                    default => 'unreadable',
                });
            }
            $path = "{$subjectId}/{$name}-full.jpg";
            $thumbPath = "{$subjectId}/{$name}-thumb.jpg";
            $primary = $image->fullBytes;
            $writes = [$path => $image->fullBytes, $thumbPath => $image->thumbBytes];
            $width = $image->width;
            $height = $image->height;
        } else {
            $path = "{$subjectId}/{$name}.{$type['ext']}";
            $primary = $bytes;
            $writes = [$path => $bytes];
        }

        $this->write($writes);

        return [
            'kind' => $type['kind'],
            'mime' => $type['kind'] === 'image' ? 'image/jpeg' : $type['mime'],
            'ext' => $type['ext'],
            'path' => $path,
            'thumb_path' => $thumbPath,
            'width' => $width,
            'height' => $height,
            'size_bytes' => strlen($primary),
            'sha256' => hash('sha256', $primary),
        ];
    }

    public function absolutePath(string $relative): string
    {
        $this->assertPath($relative);

        return Storage::disk('medical')->path($relative);
    }

    /** @param  list<string>  $paths */
    public function delete(array $paths): void
    {
        foreach ($paths as $path) {
            $this->assertPath($path);
        }
        if ($paths !== []) {
            Storage::disk('medical')->delete($paths);
        }
    }

    public function deleteSubjectDirectory(string $subjectId): void
    {
        $this->assertSubject($subjectId);
        Storage::disk('medical')->deleteDirectory($subjectId);
    }

    /** Read-only inventory for the orphan sweep: every stored file with its modification time. @return list<array{path: string, modifiedAt: int}> */
    public function files(): array
    {
        $disk = Storage::disk('medical');
        $out = [];
        foreach ($disk->allFiles() as $path) {
            $out[] = ['path' => $path, 'modifiedAt' => (int) $disk->lastModified($path)];
        }

        return $out;
    }

    /** Read-only inventory for the orphan sweep: every top-level subject directory. @return list<array{subjectId: string, modifiedAt: int}> */
    public function subjectDirectories(): array
    {
        $disk = Storage::disk('medical');
        $out = [];
        foreach ($disk->directories() as $dir) {
            $out[] = ['subjectId' => $dir, 'modifiedAt' => (int) $disk->lastModified($dir)];
        }

        return $out;
    }

    /** @param  array<string, string>  $writes  path => bytes */
    private function write(array $writes): void
    {
        $disk = Storage::disk('medical');
        try {
            foreach ($writes as $path => $bytes) {
                // The disk has throw=false: a full disk or bad volume owner returns false.
                if ($disk->put($path, $bytes) === false) {
                    throw new DocumentRejected('write_failed');
                }
            }
        } catch (\Throwable) {
            // Remove everything attempted, including the file whose write returned false (it may be partial).
            $disk->delete(array_keys($writes));
            throw new DocumentRejected('write_failed');
        }
    }

    private function assertPath(string $relative): void
    {
        if (preg_match(self::PATH, $relative) !== 1) {
            throw new InvalidArgumentException('Invalid medical document path.');
        }
    }

    private function assertSubject(string $subjectId): void
    {
        if (preg_match(self::UUID, $subjectId) !== 1) {
            throw new InvalidArgumentException('Invalid medical subject directory.');
        }
    }
}
