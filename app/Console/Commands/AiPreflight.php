<?php

namespace App\Console\Commands;

use App\Medical\Ai\Preflight;
use Illuminate\Console\Command;

class AiPreflight extends Command
{
    protected $signature = 'ai:preflight';

    protected $description = 'Check the AI worker configuration (codes only, never values)';

    public function handle(Preflight $preflight): int
    {
        $problems = $preflight->problems();
        if ($problems === []) {
            $this->line('AI preflight OK');

            return self::SUCCESS;
        }
        foreach ($problems as $code) {
            $this->line($code);
        }

        return self::FAILURE;
    }
}
