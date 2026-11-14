<?php

namespace App\Medical\Ai;

/** One prepared copilot run. All paths are app-generated and absolute, never derived from a client file name. */
final readonly class CopilotRequest
{
    /** @param  list<string>  $images  absolute paths inside $workDir */
    public function __construct(
        public string $workDir,
        public string $prompt,
        public array $images,
        public ?string $model,
        public int $timeoutSeconds,
        public string $usagePath,
    ) {}
}
