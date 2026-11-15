<?php

use App\Wishlist\Catalog\ImageProcessor;
use App\Wishlist\Catalog\ImageRejected;
use Illuminate\Contracts\Console\Kernel;
use Tests\Support\ImageFixtures;

// Run by MedicalImageLimitsTest in a separate PHP process with memory_limit=128M (docker/app/php-prod.ini), so the
// measurement is not distorted by what the test suite has allocated. Arguments: width height orientation. The medical
// settings (config/medical.php) are used exactly as MedicalDocumentStore passes them. Prints one JSON line.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$width, $height, $orientation] = [(int) ($argv[1] ?? 4000), (int) ($argv[2] ?? 3000), (int) ($argv[3] ?? 6)];
$bytes = ImageFixtures::jpegWithExif($orientation, $width, $height);
gc_collect_cycles();
memory_reset_peak_usage();
$baseline = memory_get_usage();

try {
    $out = (new ImageProcessor)->process(
        $bytes,
        (int) config('medical.document_max_bytes'),
        (int) config('medical.image_full_px'),
        (int) config('medical.image_thumb_px'),
        (int) config('medical.image_jpeg_quality'),
        (int) config('medical.image_decode_budget_bytes'),
    );
} catch (ImageRejected $e) {
    echo json_encode(['rejected' => $e->reason, 'peak' => memory_get_peak_usage() - $baseline]), "\n";
    exit(0);
}

$hwm = preg_match('/VmHWM:\s+(\d+) kB/', (string) @file_get_contents('/proc/self/status'), $m) === 1 ? (int) $m[1] * 1024 : null;

echo json_encode([
    'peak' => memory_get_peak_usage() - $baseline,
    'baseline' => $baseline,
    'peak_total' => memory_get_peak_usage(),
    'width' => $out->width,
    'height' => $out->height,
    'full_bytes' => strlen($out->fullBytes),
    'rss_peak' => $hwm,
    'limit' => ini_get('memory_limit'),
]), "\n";
