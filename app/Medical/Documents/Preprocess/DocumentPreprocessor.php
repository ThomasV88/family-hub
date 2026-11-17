<?php

namespace App\Medical\Documents\Preprocess;

use App\Wishlist\Catalog\ImageRejected;
use GdImage;

/**
 * The GD pre-processing arms of the OCR evaluation (A1 to A5). Inputs and outputs are file paths in a work dir; the only
 * output format is lossless PNG so the AI CLI passes it through. Every method frees its GD images in a finally block and
 * returns the time and the peak PHP memory of the call. Plan 13-06 keeps or deletes these steps per the evaluation.
 */
final class DocumentPreprocessor
{
    public const MAX_PX = 2048;

    public const STEPS = ['deskew', 'autolevel', 'denoise_sharpen', 'grayscale'];

    /** Same estimate the ImageProcessor budget uses: bytes per pixel of one truecolor bitmap including GD overhead. */
    private const BYTES_PER_PIXEL = 4.4;

    /** Deskew is skipped below this many degrees. */
    private const MIN_SKEW = 0.5;

    /** A1: fit within 2048 px (one-pass bilinear resample, white canvas). @return array{ms: int, peak_bytes: int} */
    public function fit(string $src, string $dst): array
    {
        return $this->run($src, $dst, []);
    }

    /** A2 = A1 + grayscale and histogram stretch. @return array{ms: int, peak_bytes: int} */
    public function autolevel(string $src, string $dst): array
    {
        return $this->run($src, $dst, ['autolevel']);
    }

    /** A3 = A2 + light smoothing and a 3x3 unsharp step. @return array{ms: int, peak_bytes: int} */
    public function denoiseSharpen(string $src, string $dst): array
    {
        return $this->run($src, $dst, ['autolevel', 'denoise_sharpen']);
    }

    /** A4 = A1 + rotation by the estimated skew. @return array{ms: int, peak_bytes: int, angle: float} */
    public function deskew(string $src, string $dst): array
    {
        return $this->run($src, $dst, ['deskew']);
    }

    /**
     * A5 and any other chain: fit first, then the steps in the given order.
     *
     * @param  list<string>  $steps  of self::STEPS
     * @return array{ms: int, peak_bytes: int, angle: float}
     */
    public function chain(string $src, string $dst, array $steps): array
    {
        return $this->run($src, $dst, $steps);
    }

    /**
     * @param  list<string>  $steps
     * @return array{ms: int, peak_bytes: int, angle: float}
     */
    private function run(string $src, string $dst, array $steps): array
    {
        foreach ($steps as $s) {
            if (! in_array($s, self::STEPS, true)) {
                throw new \InvalidArgumentException('unknown pre-processing step');
            }
        }
        memory_reset_peak_usage();
        $start = hrtime(true);
        $angle = 0.0;

        $img = $this->load($src);
        try {
            foreach ($steps as $step) {
                switch ($step) {
                    case 'deskew':
                        [$img, $angle] = $this->deskewImage($img);
                        break;
                    case 'autolevel':
                        $this->autolevelImage($img);
                        break;
                    case 'denoise_sharpen':
                        imagefilter($img, IMG_FILTER_SMOOTH, 10);
                        imageconvolution($img, [[0, -1, 0], [-1, 8, -1], [0, -1, 0]], 4, 0);
                        break;
                    case 'grayscale':
                        imagefilter($img, IMG_FILTER_GRAYSCALE);
                        break;
                }
            }
            if (! imagepng($img, $dst, 6)) {
                throw new \RuntimeException('pre-processed image not writable');
            }
        } finally {
            imagedestroy($img);
        }

        return [
            'ms' => (int) round((hrtime(true) - $start) / 1_000_000),
            'peak_bytes' => memory_get_peak_usage(),
            'angle' => $angle,
        ];
    }

    /** Decodes within the ImageProcessor budget and fits to 2048 px; the source bitmap is freed before returning. */
    private function load(string $src): GdImage
    {
        $info = @getimagesize($src);
        if ($info === false || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true) || $info[0] < 1 || $info[1] < 1) {
            throw new ImageRejected('unsupported_type');
        }
        [$w, $h] = $info;
        [$outW, $outH] = $this->size($w, $h);
        $size = filesize($src);
        if (($size === false ? 0 : $size) + self::BYTES_PER_PIXEL * ($w * $h + $outW * $outH) > (int) config('medical.image_decode_budget_bytes')) {
            throw new ImageRejected('too_many_pixels');
        }

        $bytes = file_get_contents($src);
        $img = $bytes === false ? false : @imagecreatefromstring($bytes);
        unset($bytes);
        if (! $img instanceof GdImage) {
            throw new ImageRejected('unsupported_type');
        }
        if (! imageistruecolor($img)) {
            imagepalettetotruecolor($img);
        }
        if ($outW === $w && $outH === $h) {
            return $img;
        }
        // One pass into a white canvas: imagescale() is two-pass and keeps an intermediate bitmap alive, which pushes the
        // peak of a portrait 3600 px page past 100 MB. The cost is about 0.2 s per page.
        $scaled = imagecreatetruecolor($outW, $outH);
        imagefilledrectangle($scaled, 0, 0, $outW - 1, $outH - 1, (int) imagecolorallocate($scaled, 255, 255, 255));
        $ok = imagecopyresampled($scaled, $img, 0, 0, 0, 0, $outW, $outH, $w, $h);
        imagedestroy($img);
        if (! $ok) {
            imagedestroy($scaled);
            throw new ImageRejected('unsupported_type');
        }

        return $scaled;
    }

    /** @return array{0: int, 1: int} */
    private function size(int $w, int $h): array
    {
        $longest = max($w, $h);
        if ($longest <= self::MAX_PX) {
            return [$w, $h];
        }
        $f = self::MAX_PX / $longest;

        return [max(1, (int) round($w * $f)), max(1, (int) round($h * $f))];
    }

    /**
     * Rotates by minus the estimated skew on a white background and crops back to the original size, so the result still
     * fits within 2048 px. Below 0.5 degrees the image is returned unchanged.
     *
     * @return array{0: GdImage, 1: float}
     */
    private function deskewImage(GdImage $img): array
    {
        $skew = (new SkewEstimator)->estimate($img);
        if (abs($skew) < self::MIN_SKEW) {
            return [$img, 0.0];
        }
        $w = imagesx($img);
        $h = imagesy($img);
        $rot = imagerotate($img, -$skew, (int) imagecolorallocate($img, 255, 255, 255));
        if (! $rot instanceof GdImage) {
            return [$img, 0.0];
        }
        imagedestroy($img);
        $crop = imagecrop($rot, ['x' => max(0, intdiv(imagesx($rot) - $w, 2)), 'y' => max(0, intdiv(imagesy($rot) - $h, 2)), 'width' => $w, 'height' => $h]);
        imagedestroy($rot);
        if (! $crop instanceof GdImage) {
            throw new \RuntimeException('deskew crop failed');
        }

        return [$crop, $skew];
    }

    /**
     * Grayscale, then a linear stretch from the 1st to the 99th luminance percentile to 0..255. The histogram comes from
     * a 512 px copy and the stretch is applied through the 256 entry palette, so no per-pixel PHP loop runs on the page.
     */
    private function autolevelImage(GdImage $img): void
    {
        imagefilter($img, IMG_FILTER_GRAYSCALE);
        $w = imagesx($img);
        $h = imagesy($img);
        $scale = min(1.0, 512 / max($w, $h));
        $small = imagescale($img, max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale)), IMG_BOX);
        if (! $small instanceof GdImage) {
            return;
        }
        $hist = array_fill(0, 256, 0);
        try {
            $sw = imagesx($small);
            $sh = imagesy($small);
            for ($y = 0; $y < $sh; $y++) {
                for ($x = 0; $x < $sw; $x++) {
                    $hist[imagecolorat($small, $x, $y) & 0xFF]++;
                }
            }
        } finally {
            imagedestroy($small);
        }
        $total = array_sum($hist);
        $acc = 0;
        $lo = 0;
        $hi = 255;
        $gotLo = false;
        foreach ($hist as $v => $c) {
            $acc += $c;
            if (! $gotLo && $acc >= 0.01 * $total) {
                $lo = $v;
                $gotLo = true;
            }
            if ($acc >= 0.99 * $total) {
                $hi = $v;
                break;
            }
        }
        if ($hi - $lo < 16) {
            return;
        }

        // The palette trick: an 8-bit copy of a gray image has at most 256 entries; remapping them is the whole stretch.
        imagetruecolortopalette($img, false, 256);
        for ($i = 0, $n = imagecolorstotal($img); $i < $n; $i++) {
            $c = imagecolorsforindex($img, $i);
            $g = (int) round(($c['red'] + $c['green'] + $c['blue']) / 3);
            $s = max(0, min(255, (int) round(($g - $lo) * 255 / ($hi - $lo))));
            imagecolorset($img, $i, $s, $s, $s);
        }
        imagepalettetotruecolor($img);
    }
}
