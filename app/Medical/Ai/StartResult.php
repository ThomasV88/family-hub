<?php

namespace App\Medical\Ai;

/** Outcome of a start request: queued, an already active run, a quota refusal ('busy') or the reason nothing was started. */
final readonly class StartResult
{
    /** @param 'queued'|'active'|'busy'|'consent_off'|'unavailable'|'unsupported'|'notice_required'|'denied' $outcome */
    public function __construct(public string $outcome, public ?string $extractionId = null) {}
}
