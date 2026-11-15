<?php

namespace Tests\Support;

use GdImage;

/** Image bytes generated in memory; no binary blobs from the internet. */
final class ImageFixtures
{
    private static function canvas(int $w, int $h, bool $alpha = false): GdImage
    {
        $img = imagecreatetruecolor($w, $h);
        if ($alpha) {
            imagealphablending($img, false);
            imagesavealpha($img, true);
            imagefilledrectangle($img, 0, 0, $w - 1, $h - 1, (int) imagecolorallocatealpha($img, 0, 0, 0, 127));
        } else {
            imagefilledrectangle($img, 0, 0, $w - 1, $h - 1, (int) imagecolorallocate($img, 40, 120, 200));
            imagefilledrectangle($img, 0, 0, intdiv($w, 2), intdiv($h, 2), (int) imagecolorallocate($img, 220, 60, 60));
        }

        return $img;
    }

    private static function encode(callable $fn, GdImage $img): string
    {
        ob_start();
        $fn($img);
        $bytes = (string) ob_get_clean();
        imagedestroy($img);

        return $bytes;
    }

    public static function jpeg(int $w = 64, int $h = 48): string
    {
        return self::encode(fn ($i) => imagejpeg($i, null, 60), self::canvas($w, $h));
    }

    /** A JPEG with an APP1 Exif segment holding the orientation and a GPS IFD, spliced after SOI. */
    public static function jpegWithExif(int $orientation = 6, int $w = 64, int $h = 48): string
    {
        $jpeg = self::jpeg($w, $h);
        $gpsOffset = 8 + 2 + 2 * 12 + 4;
        $ifd0 = pack('v', 2)
            .pack('vvVv', 0x0112, 3, 1, $orientation).pack('v', 0)
            .pack('vvVV', 0x8825, 4, 1, $gpsOffset)
            .pack('V', 0);
        $gps = pack('v', 2)
            .pack('vvV', 0x0001, 2, 2)."N\0\0\0"
            .pack('vvVV', 0x0002, 5, 3, $gpsOffset + 2 + 2 * 12 + 4)
            .pack('V', 0)
            .pack('VVVVVV', 48, 1, 51, 1, 30, 1);
        $payload = "Exif\0\0".'II'.pack('v', 42).pack('V', 8).$ifd0.$gps;
        $segment = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;

        return substr($jpeg, 0, 2).$segment.substr($jpeg, 2);
    }

    public static function pngTransparent(int $w = 32, int $h = 32): string
    {
        return self::encode(fn ($i) => imagepng($i), self::canvas($w, $h, true));
    }

    /** A PNG with a valid IHDR and CRC declaring huge dimensions and no pixel data to speak of. */
    public static function pngBomb(int $w = 30000, int $h = 30000): string
    {
        $chunk = fn (string $type, string $data) => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

        return "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', $w, $h, 8, 2, 0, 0, 0)).$chunk('IEND', '');
    }

    public static function gif(int $w = 16, int $h = 16): string
    {
        $img = imagecreate($w, $h);
        imagecolorallocate($img, 10, 200, 10);

        return self::encode(fn ($i) => imagegif($i), $img);
    }

    public static function webp(int $w = 32, int $h = 32): string
    {
        return self::encode(fn ($i) => imagewebp($i, null, 70), self::canvas($w, $h));
    }

    public static function svg(): string
    {
        return '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><script>alert(1)</script></svg>';
    }

    /** A valid GIF header (1x1) followed by HTML and script, with no image data. */
    public static function polyglot(): string
    {
        return 'GIF89a'.pack('vv', 1, 1)."\x00\x00\x00".'<html><script>alert(document.cookie)</script></html>';
    }

    public static function html(): string
    {
        return '<!doctype html><html><body><h1>not an image</h1></body></html>';
    }

    public static function random(int $length = 2048): string
    {
        return random_bytes($length);
    }
}
