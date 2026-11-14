<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Route;

/** The Settings sections a member may see, in display order. Plans 05 and 06 add routes; sections appear automatically. */
class SettingsSections
{
    /** @return list<array{key: string, href: string, labelKey: string, icon: string}> */
    public static function for(User $user): array
    {
        $sections = [];

        if ($user->is_admin && Route::has('settings.members')) {
            $sections[] = ['key' => 'members', 'href' => route('settings.members', absolute: false), 'labelKey' => 'settings.section.members', 'icon' => 'users'];
        }
        if ($user->is_admin && Route::has('settings.audit')) {
            $sections[] = ['key' => 'audit', 'href' => route('settings.audit', absolute: false), 'labelKey' => 'settings.section.audit', 'icon' => 'scroll-text'];
        }
        if ($user->is_admin && Route::has('settings.finance_accounts.index')) {
            $sections[] = ['key' => 'finance_accounts', 'href' => route('settings.finance_accounts.index', absolute: false), 'labelKey' => 'settings.section.finance_accounts', 'icon' => 'wallet'];
        }
        if ($user->is_admin && Route::has('settings.ai_calls')) {
            $sections[] = ['key' => 'ai_calls', 'href' => route('settings.ai_calls', absolute: false), 'labelKey' => 'settings.section.ai_calls', 'icon' => 'bot'];
        }
        if ($user->is_admin && Route::has('settings.ai_agents')) {
            $sections[] = ['key' => 'ai_agents', 'href' => route('settings.ai_agents', absolute: false), 'labelKey' => 'settings.section.ai_agents', 'icon' => 'bot'];
        }
        if (Route::has('settings.notifications')) {
            $sections[] = ['key' => 'notifications', 'href' => route('settings.notifications', absolute: false), 'labelKey' => 'notifications.nav', 'icon' => 'bell'];
        }
        if (Route::has('settings.security')) {
            $sections[] = ['key' => 'security', 'href' => route('settings.security', absolute: false), 'labelKey' => 'settings.section.security', 'icon' => 'shield-check'];
        }
        $sections[] = ['key' => 'appearance', 'href' => route('settings.appearance', absolute: false), 'labelKey' => 'settings.section.appearance', 'icon' => 'palette'];

        return $sections;
    }
}
