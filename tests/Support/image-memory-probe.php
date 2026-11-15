<?php

use App\Wishlist\Catalog\ImageProcessor;
use Illuminate\Contracts\Console\Kernel;
use Tests\Support\ImageFixtures;

// Run by ImageProcessorTest in a separate PHP process with memory_limit=128M, so the measurement is not
// distorted by whatever the test suite has already allocated. Prints one JSON line.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$bytes = ImageFixtures::jpegWithExif(6, 4000, 3000);
gc_collect_cycles();
memory_reset_peak_usage();
$baseline = memory_get_usage();

$out = (new ImageProcessor)->process($bytes, 5 * 1024 * 1024);

echo json_encode([
    'peak' => memory_get_peak_usage() - $baseline,
    'width' => $out->width,
    'height' => $out->height,
    'limit' => ini_get('memory_limit'),
]), "\n";
