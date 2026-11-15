<?php

namespace App\Medical\Ai;

/** One prepared codex run. All paths are app-generated and absolute, never derived from a client file name. */
final readonly class CodexRequest
{
    /** @param  list<string>  $images  absolute paths inside $workDir */
    public function __construct(
        public string $workDir,
        public string $schemaPath,
        public string $resultPath,
        public array $images,
        public string $prompt,
        public ?string $model = null,
    ) {}
}
