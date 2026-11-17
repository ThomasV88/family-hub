<?php

use App\Medical\Documents\Preprocess\DocumentPreprocessor;
use Illuminate\Contracts\Console\Kernel;

// Run by PreprocessTest in a separate PHP process with memory_limit=128M (as the ai-worker), so the measurement is not
// distorted by the test suite. Arguments: input file, output file. Prints one JSON line with the numbers of the full chain.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$r = (new DocumentPreprocessor)->chain($argv[1], $argv[2], ['deskew', 'autolevel', 'denoise_sharpen', 'grayscale']);
echo json_encode(['ms' => $r['ms'], 'peak' => $r['peak_bytes'], 'peak_total' => memory_get_peak_usage(), 'limit' => ini_get('memory_limit')]), "\n";
