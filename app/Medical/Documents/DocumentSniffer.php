<?php

namespace App\Medical\Documents;

/**
 * Classifies an upload from its bytes alone. The client MIME type and file extension are never consulted. Allowed:
 * PDF (finfo application/pdf AND the %PDF- magic at byte 0, so a polyglot with leading junk is refused), JPEG, PNG
 * and WebP, and HEIC/HEIF (finfo heic/heif AND an ftyp box at offset 4 with an allowlisted major brand, which keeps
 * AVIF out). Anything else, including GIF, BMP and SVG, is refused.
 */
final class DocumentSniffer
{
    private const HEIC_MIMES = ['image/heic', 'image/heif', 'image/heif-sequence'];

    private const HEIC_BRANDS = ['heic', 'heix', 'hevc', 'hevx', 'heim', 'heis', 'hevm', 'hevs', 'mif1', 'msf1'];

    private const IMAGE_MIMES = ['image/jpeg' => 'jpg', 'image/png' => 'jpg', 'image/webp' => 'jpg'];

    /** @return array{kind: 'pdf'|'image'|'heic', mime: string, ext: 'pdf'|'jpg'|'heic'} */
    public function sniff(string $bytes): array
    {
        if ($bytes === '') {
            throw new DocumentRejected('unsupported_type');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (! is_string($mime)) {
            throw new DocumentRejected('unsupported_type');
        }

        if ($mime === 'application/pdf' && str_starts_with($bytes, '%PDF-')) {
            return ['kind' => 'pdf', 'mime' => 'application/pdf', 'ext' => 'pdf'];
        }

        if (in_array($mime, self::HEIC_MIMES, true)
            && strlen($bytes) >= 12
            && substr($bytes, 4, 4) === 'ftyp'
            && in_array(substr($bytes, 8, 4), self::HEIC_BRANDS, true)) {
            return ['kind' => 'heic', 'mime' => $mime, 'ext' => 'heic'];
        }

        if (isset(self::IMAGE_MIMES[$mime])) {
            return ['kind' => 'image', 'mime' => $mime, 'ext' => 'jpg'];
        }

        throw new DocumentRejected('unsupported_type');
    }
}
