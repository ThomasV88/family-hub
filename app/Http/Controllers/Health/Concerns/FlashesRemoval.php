<?php

namespace App\Http\Controllers\Health\Concerns;

/**
 * The toast of a delete request. The server decided between a permanent delete and an archive (App\Medical\Retention);
 * the flash tells the client which happened as `outcome` ('deleted' or 'archived') and picks the matching message.
 */
trait FlashesRemoval
{
    /**
     * @param  'deleted'|'archived'  $outcome
     * @return array{key: string, outcome: string}
     */
    protected function removalToast(string $outcome, string $deletedKey): array
    {
        return ['key' => $outcome === 'archived' ? 'health.archive.archived' : $deletedKey, 'outcome' => $outcome];
    }
}
