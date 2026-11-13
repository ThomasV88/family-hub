<?php

namespace App\Auth;

use App\Models\User;
use App\Support\UserAgentLabel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Lists a member's sessions for the admin. The payload is read server-side for one flag and never returned. */
class SessionList
{
    /** @return list<array<string, mixed>> */
    public static function for(User $member, ?string $currentId): array
    {
        return DB::table('sessions')
            ->where('user_id', $member->getKey())
            ->orderByDesc('last_activity')
            ->get(['id', 'ip_address', 'user_agent', 'last_activity', 'payload'])
            ->map(function ($row) use ($currentId): array {
                $label = UserAgentLabel::parse($row->user_agent);

                return [
                    'id' => $row->id,
                    'browser' => $label['browser'],
                    'os' => $label['os'],
                    'mobile' => $label['mobile'],
                    'ip' => $row->ip_address,
                    'lastActivity' => CarbonImmutable::createFromTimestampUTC((int) $row->last_activity)->toIso8601String(),
                    'isCurrent' => $currentId !== null && $row->id === $currentId,
                    'staysSignedIn' => self::staysSignedIn((string) $row->payload),
                ];
            })
            ->all();
    }

    private static function staysSignedIn(string $payload): bool
    {
        $raw = base64_decode($payload, true);
        if ($raw === false) {
            return false;
        }
        $data = json_decode($raw, true);

        return is_array($data) && ($data['auth_remember'] ?? false) === true;
    }
}
