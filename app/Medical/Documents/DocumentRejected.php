<?php

namespace App\Medical\Documents;

use InvalidArgumentException;
use RuntimeException;

/** A document the pipeline refuses. Carries only a machine reason, never file content or the client file name. */
final class DocumentRejected extends RuntimeException
{
    public const REASONS = ['unsupported_type', 'too_large', 'too_many_pixels', 'unreadable', 'limit_reached', 'write_failed', 'busy'];

    public function __construct(public readonly string $reason)
    {
        if (! in_array($reason, self::REASONS, true)) {
            throw new InvalidArgumentException('Unknown document rejection reason.');
        }
        parent::__construct('document_rejected:'.$reason);
    }
}
