<?php

namespace App\Medical\Documents;

use GdImage;
use Throwable;

/**
 * Applies the member's adjustments to one page image of the job's throwaway work dir, in place. The maths equal the CSS
 * chain brightness(), contrast(), grayscale() of the browser preview: c' = (c * b - 127.5) * k + 127.5 per channel.
 * Never throws; false means the copy could not be made and nothing was changed.
 */
final class AiCopyAdjuster
{
    public const MAX_PX = 2048;

    /** Same estimate the other GD steps use: bytes per pixel of one truecolor bitmap including GD overhead. */
    private const BYTES_PER_PIXEL = 4.4;

    public function apply(string $path, Adjustments $a): bool
    {
        $img = null;
        $tmp = $path.'.adj';
        try {
            $info = @getimagesize($path);
            if ($info === false || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true) || $info[0] < 1 || $info[1] < 1) {
                return false;
            }
            [$w, $h] = $info;
            $longest = max($w, $h);
            $f = $longest > self::MAX_PX ? self::MAX_PX / $longest : 1.0;
            $outW = max(1, (int) round($w * $f));
            $outH = max(1, (int) round($h * $f));
            $size = filesize($path);
            if (($size === false ? 0 : $size) + self::BYTES_PER_PIXEL * ($w * $h + $outW * $outH) > (int) config('medical.image_decode_budget_bytes')) {
                return false;
            }

            $bytes = file_get_contents($path);
            $img = $bytes === false ? false : @imagecreatefromstring($bytes);
            unset($bytes);
            if (! $img instanceof GdImage) {
                return false;
            }
            if (! imageistruecolor($img)) {
                imagepalettetotruecolor($img);
            }
            if ($outW !== $w || $outH !== $h) {
                $scaled = imagecreatetruecolor($outW, $outH);
                imagefilledrectangle($scaled, 0, 0, $outW - 1, $outH - 1, (int) imagecolorallocate($scaled, 255, 255, 255));
                $ok = imagecopyresampled($scaled, $img, 0, 0, 0, 0, $outW, $outH, $w, $h);
                imagedestroy($img);
                $img = $scaled;
                if (! $ok) {
                    return false;
                }
            }

            $b = $a->brightness / 100;
            $k = $a->contrast / 100;
            if ($b * $k !== 1.0 || $k !== 1.0) {
                $m = [[0, 0, 0], [0, $b * $k, 0], [0, 0, 0]];
                if (! imageconvolution($img, $m, 1, 127.5 * (1 - $k))) {
                    return false;
                }
            }
            if ($a->bw && ! imagefilter($img, IMG_FILTER_GRAYSCALE)) {
                return false;
            }

            $written = $info[2] === IMAGETYPE_PNG ? imagepng($img, $tmp, 6) : imagejpeg($img, $tmp, 92);
            if (! $written || ! rename($tmp, $path)) {
                return false;
            }

            return true;
        } catch (Throwable) {
            return false;
        } finally {
            if ($img instanceof GdImage) {
                imagedestroy($img);
            }
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }
}
