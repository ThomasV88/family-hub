<?php

namespace App\Medical\Batches;

/**
 * The display state of one batch item, from the stored item state and the status of its AI run. Pure mapping, no I/O.
 *
 * waiting     not sent yet, consent on (the tick takes it)
 * consent_off not sent yet, the person's AI consent is off
 * processing  run queued or running
 * paused      run waiting for AI quota (available_at tells when)
 * review      proposal ready for the member
 * failed      run failed, or cancelled because access went away
 * stopped     run cancelled for another reason (the member, consent)
 * done        finished with Continue
 */
final class ItemState
{
    public const ALL = ['waiting', 'consent_off', 'processing', 'paused', 'review', 'failed', 'stopped', 'done'];

    public static function for(string $itemState, ?string $runStatus, ?string $cancelReason, bool $consentOn): string
    {
        if ($itemState === 'done') {
            return 'done';
        }
        if ($itemState === 'pending' || $runStatus === null) {
            return $consentOn ? 'waiting' : 'consent_off';
        }

        return match ($runStatus) {
            'queued', 'running' => 'processing',
            'waiting' => 'paused',
            'ready' => 'review',
            'failed' => 'failed',
            'cancelled' => $cancelReason === 'access' ? 'failed' : 'stopped',
            default => 'stopped',
        };
    }
}
