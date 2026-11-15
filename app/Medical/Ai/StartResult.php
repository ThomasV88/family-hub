<?php

namespace App\Medical\Ai;

/** Outcome of a start request: queued, an already active run, a quota refusal ('busy') or the reason nothing was started. */
final readonly class StartResult
{
    /** @param 'queued'|'active'|'busy'|'consent_off'|'unavailable'|'unsupported'|'notice_required'|'denied' $outcome */
    /** @param list<string>|null $providers the enabled provider keys, set with 'notice_required' so the dialog can name what is true now */
    public function __construct(public string $outcome, public ?string $extractionId = null, public ?array $providers = null) {}
}
