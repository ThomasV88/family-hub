<?php

namespace App\Wishlist\Catalog;

use GdImage;

/**
 * The one hardened path for every wish image, uploaded or fetched. The type comes from the bytes (never from a
 * client-declared MIME), size and pixel count are capped before any decode, and the output is a fresh JPEG that
 * carries no metadata. Memory: the full-resolution bitmap is resized first and freed, and only the small result
 * is rotated or flipped, so a 12 MP photo stays inside a 128M memory_limit.
 */
final class ImageProcessor
{
    private const TYPES = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF];

    private const MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    private const QUALITY = 82;

    public function process(string $bytes, int $maxBytes): ProcessedImage
    {
        if (strlen($bytes) > $maxBytes) {
            throw new ImageRejected('too_large');
        }

        $info = @getimagesizefromstring($bytes);
        if ($info === false || ! in_array($info[2], self::TYPES, true) || $info[0] < 1 || $info[1] < 1) {
            throw new ImageRejected('unsupported_type');
        }
        [$width, $height] = $info;
        if ($width * $height > (int) config('wishlist.image_max_pixels')) {
            throw new ImageRejected('too_many_pixels');
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (! is_string($mime) || ! in_array($mime, self::MIMES, true)) {
            throw new ImageRejected('unsupported_type');
        }

        $orientation = $info[2] === IMAGETYPE_JPEG ? $this->orientation($bytes) : 1;

        $source = @imagecreatefromstring($bytes);
        if (! $source instanceof GdImage) {
            throw new ImageRejected('unsupported_type');
        }

        // Target size in the oriented frame; for orientations 5-8 the source axes swap roles.
        $swap = $orientation >= 5;
        $orientedW = $swap ? $height : $width;
        $orientedH = $swap ? $width : $height;
        [$fullW, $fullH] = $this->fit($orientedW, $orientedH, (int) config('wishlist.image_full_px'));
        $resized = $this->resample($source, $swap ? $fullH : $fullW, $swap ? $fullW : $fullH);
        imagedestroy($source);
        unset($source);

        $full = $this->orient($resized, $orientation);
        unset($resized);

        [$thumbW, $thumbH] = $this->fit(imagesx($full), imagesy($full), (int) config('wishlist.image_thumb_px'));
        $thumb = $this->resample($full, $thumbW, $thumbH);

        $result = new ProcessedImage($this->encode($full), $this->encode($thumb), imagesx($full), imagesy($full));
        imagedestroy($full);
        imagedestroy($thumb);

        return $result;
    }

    /** Reads the EXIF orientation from a stream so the bytes are not copied into a data URI. */
    private function orientation(string $bytes): int
    {
        if (! function_exists('exif_read_data')) {
            return 1;
        }
        $stream = fopen('php://temp', 'r+b');
        if ($stream === false) {
            return 1;
        }
        fwrite($stream, $bytes);
        rewind($stream);
        $exif = @exif_read_data($stream, 'IFD0');
        fclose($stream);
        $value = is_array($exif) ? ($exif['Orientation'] ?? 1) : 1;

        return is_int($value) && $value >= 1 && $value <= 8 ? $value : 1;
    }

    /** @return array{0: int, 1: int} */
    private function fit(int $w, int $h, int $max): array
    {
        $longest = max($w, $h);
        if ($longest <= $max) {
            return [$w, $h];
        }
        $scale = $max / $longest;

        return [max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale))];
    }

    /** Copies onto a white truecolor canvas, which also flattens any transparency. */
    private function resample(GdImage $src, int $w, int $h): GdImage
    {
        $dst = imagecreatetruecolor($w, $h);
        imagefilledrectangle($dst, 0, 0, $w - 1, $h - 1, (int) imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $w, $h, imagesx($src), imagesy($src));

        return $dst;
    }

    /** Applies EXIF orientation 2-8 to an already small image, freeing each intermediate. */
    private function orient(GdImage $img, int $orientation): GdImage
    {
        $white = (int) imagecolorallocate($img, 255, 255, 255);
        $rotate = function (GdImage $i, int $ccwDegrees) use ($white): GdImage {
            $out = imagerotate($i, $ccwDegrees, $white);
            if (! $out instanceof GdImage) {
                return $i;
            }
            imagedestroy($i);

            return $out;
        };

        switch ($orientation) {
            case 2:
                imageflip($img, IMG_FLIP_HORIZONTAL);
                break;
            case 3:
                $img = $rotate($img, 180);
                break;
            case 4:
                imageflip($img, IMG_FLIP_VERTICAL);
                break;
            case 5:
                $img = $rotate($img, 270);
                imageflip($img, IMG_FLIP_HORIZONTAL);
                break;
            case 6:
                $img = $rotate($img, 270);
                break;
            case 7:
                $img = $rotate($img, 90);
                imageflip($img, IMG_FLIP_HORIZONTAL);
                break;
            case 8:
                $img = $rotate($img, 90);
                break;
        }

        return $img;
    }

    private function encode(GdImage $img): string
    {
        ob_start();
        imagejpeg($img, null, self::QUALITY);

        return (string) ob_get_clean();
    }
}
