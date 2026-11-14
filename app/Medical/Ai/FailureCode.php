<?php

namespace App\Medical\Ai;

/**
 * Content-free by design; the only failure detail ever stored or shown.
 * Phase 9 adds quota.
 */
enum FailureCode: string
{
    case Timeout = 'timeout';
    case IdleTimeout = 'idle_timeout';
    case NoOutput = 'no_output';
    case InvalidOutput = 'invalid_output';
    case OutputTooLarge = 'output_too_large';
    case CliError = 'cli_error';
    case Auth = 'auth';
    case WorkerLost = 'worker_lost';
    case UnreadableDocument = 'unreadable_document';
    case UnsupportedDocument = 'unsupported_document';
    case Preflight = 'preflight';
}
