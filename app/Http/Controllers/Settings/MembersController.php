<?php

namespace App\Http\Controllers\Settings;

use App\Access\AccessGate;
use App\Access\Preset;
use App\Http\Controllers\Controller;
use App\Medical\Queries\AdminSubjectQuery;
use App\Models\Invitation;
use App\Models\User;
use App\Models\WishlistManager;
use App\Security\ReauthMethods;
use App\Security\SecondFactorPolicy;
use App\Security\SensitiveSession;
use App\Support\SettingsSections;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class MembersController extends Controller
{
    public function index(Request $request, AccessGate $gate, SecondFactorPolicy $policy, SensitiveSession $sensitive, AdminSubjectQuery $people): Response
    {
        $viewer = $request->user();

        $managerIds = WishlistManager::query()->orderBy('manager_id')->get(['owner_id', 'manager_id'])
            ->groupBy('owner_id')
            ->map(fn ($rows) => $rows->pluck('manager_id')->map(fn ($id) => (int) $id)->values()->all());

        // One grouped query for all passkey counts.
        $passkeyCounts = DB::table('passkeys')->selectRaw('user_id, count(*) as total')->groupBy('user_id')->pluck('total', 'user_id');

        // Explicit field mapping: never expose password, remember_token, google_id or other columns.
        // The two_factor_* columns are read only to compute booleans, never passed on.
        $members = User::query()->orderBy('name')->orderBy('id')
            ->get(['id', 'name', 'email', 'is_admin', 'two_factor_secret', 'two_factor_confirmed_at'])
            ->map(fn (User $member) => [
                'id' => $member->id,
                'name' => $member->name,
                'email' => $member->email,
                'isAdmin' => (bool) $member->is_admin,
                'isSelf' => $member->id === $viewer->id,
                'access' => $gate->levels($member),
                'wishlistManagerIds' => $managerIds->get($member->id, []),
                'security' => [
                    'totp' => ReauthMethods::hasTotp($member),
                    'passkeys' => (int) ($passkeyCounts[$member->id] ?? 0),
                    'required' => $policy->required() && $policy->appliesTo($member),
                    'needs' => $policy->mustEnrol($member),
                ],
            ])->values();

        $presets = [];
        foreach (Preset::cases() as $preset) {
            $presets[$preset->value] = array_map(fn ($level) => $level->label(), $preset->grants());
        }

        $invitations = Invitation::query()->whereNull('accepted_at')->orderByDesc('id')->get()
            ->map(fn (Invitation $inv) => [
                'id' => $inv->id,
                'email' => $inv->email,
                'preset' => $inv->preset->value,
                'locale' => $inv->locale,
                'state' => $inv->state(),
                'expiresAt' => $inv->expires_at->toIso8601String(),
                'mailFailed' => $inv->mail_failed_at !== null,
            ])->values();

        $open = (int) $request->query('member');

        return Inertia::render('Settings/Members', [
            'sections' => SettingsSections::for($viewer),
            'members' => $members,
            'presets' => $presets,
            'invitations' => $invitations,
            'securityPolicy' => ['required' => $policy->required(), 'pending' => $policy->pendingCount()],
            'confirmFresh' => $sensitive->recentlyConfirmed(),
            'methods' => ReauthMethods::for($viewer),
            // Admin-only page: person names and member levels, never record data or counts (PROVISIONAL, owner confirms in 07-20).
            'healthPeople' => $people->listForAdmin(),
            'openMember' => $open > 0 && $members->contains('id', $open) ? $open : null,
        ]);
    }
}
