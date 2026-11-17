<?php

namespace App\Medical\Documents\Preprocess;

use GdImage;

/**
 * Page skew by the projection-profile method, GD only: the image is shrunk to 512 px, rotated through 41 trial angles
 * within +-10 degrees and the angle whose row sums vary most (text lines line up) wins. A parabola through the best
 * angle and its two neighbours refines the 0.5 degree grid. Pure computation on an image: no I/O, no logging.
 */
final class SkewEstimator
{
    public const MAX_DEGREES = 10.0;

    public const STEP = 0.5;

    private const PROBE_PX = 512;

    /**
     * The angle in degrees by which the page is rotated counterclockwise (the page looks like imagerotate($upright, $skew)).
     * Rotating by minus this value straightens it. 0.0 when the page has no usable lines.
     */
    public function estimate(GdImage $img): float
    {
        $w = imagesx($img);
        $h = imagesy($img);
        if ($w < 8 || $h < 8) {
            return 0.0;
        }
        $scale = self::PROBE_PX / max($w, $h);
        $sw = max(8, (int) round($w * min(1.0, $scale)));
        $sh = max(8, (int) round($h * min(1.0, $scale)));
        $small = imagescale($img, $sw, $sh, IMG_BOX);
        if (! $small instanceof GdImage) {
            return 0.0;
        }
        try {
            imagefilter($small, IMG_FILTER_GRAYSCALE);
            imagefilter($small, IMG_FILTER_NEGATE);

            $scores = [];
            $n = (int) round(2 * self::MAX_DEGREES / self::STEP);
            for ($i = 0; $i <= $n; $i++) {
                $trial = -self::MAX_DEGREES + $i * self::STEP;
                $scores[$i] = $this->score($small, $trial, $sw, $sh);
            }
        } finally {
            imagedestroy($small);
        }

        $best = 0;
        foreach ($scores as $i => $s) {
            if ($s > $scores[$best]) {
                $best = $i;
            }
        }
        if ($scores[$best] <= 0.0) {
            return 0.0;
        }
        $offset = 0.0;
        if ($best > 0 && $best < $n) {
            $a = $scores[$best - 1];
            $b = $scores[$best];
            $c = $scores[$best + 1];
            $den = $a - 2 * $b + $c;
            if ($den < 0.0) {
                $offset = max(-0.5, min(0.5, 0.5 * ($a - $c) / $den));
            }
        }
        $correction = -self::MAX_DEGREES + ($best + $offset) * self::STEP;

        return -$correction;
    }

    /** Variance of the row means of the rotated, re-cropped negative image. */
    private function score(GdImage $negative, float $angle, int $w, int $h): float
    {
        $black = (int) imagecolorallocate($negative, 0, 0, 0);
        $rot = $angle === 0.0 ? null : imagerotate($negative, $angle, $black);
        $src = $rot instanceof GdImage ? $rot : $negative;
        $crop = null;
        $col = null;
        try {
            if ($rot instanceof GdImage) {
                $x = max(0, intdiv(imagesx($rot) - $w, 2));
                $y = max(0, intdiv(imagesy($rot) - $h, 2));
                $crop = imagecrop($rot, ['x' => $x, 'y' => $y, 'width' => $w, 'height' => $h]);
                if (! $crop instanceof GdImage) {
                    return 0.0;
                }
                $src = $crop;
            }
            $col = imagescale($src, 1, $h, IMG_BOX);
            if (! $col instanceof GdImage) {
                return 0.0;
            }
            $sum = $sq = 0.0;
            for ($y = 0; $y < $h; $y++) {
                $v = (float) (imagecolorat($col, 0, $y) & 0xFF);
                $sum += $v;
                $sq += $v * $v;
            }
            $mean = $sum / $h;

            return $sq / $h - $mean * $mean;
        } finally {
            foreach ([$rot, $crop, $col] as $i) {
                if ($i instanceof GdImage) {
                    imagedestroy($i);
                }
            }
        }
    }
}
