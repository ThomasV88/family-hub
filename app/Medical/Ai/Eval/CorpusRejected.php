<?php

namespace App\Medical\Ai\Eval;

use RuntimeException;

/** A refused evaluation folder. The message is one of a fixed set of codes, never a path or any file content. */
final class CorpusRejected extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('corpus_rejected:'.$reason);
    }
}
