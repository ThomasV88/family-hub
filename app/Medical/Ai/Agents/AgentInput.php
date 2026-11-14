<?php

namespace App\Medical\Ai\Agents;

use App\Medical\Ai\Work\WorkDir;

/** A prepared work dir and the names of the page images inside it. */
final readonly class AgentInput
{
    /** @param  list<string>  $images  file names inside the work dir */
    public function __construct(
        public WorkDir $workDir,
        public array $images,
        /** Unix seconds; agents derive per-call timeouts from it. */
        public ?int $deadline = null,
    ) {}
}
