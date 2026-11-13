<?php

namespace App\Http\Controllers\Security;

use App\Http\Controllers\Controller;
use App\Security\ReauthMethods;
use App\Security\SafeIntended;
use App\Security\SecondFactorPolicy;
use App\Security\SensitiveSession;
use App\Support\SettingsSections;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Settings > Security: content-free status props only (booleans, counts, passkey display fields). */
class SecuritySettingsController extends Controller
{
    public function show(Request $request, SecondFactorPolicy $policy, SensitiveSession $session): Response
    {
        $user = $request->user();
        $totp = ReauthMethods::hasTotp($user);
        $guidance = $policy->mustEnrol($user);
        $flagged = $request->session()->get('status') === 'second_factor_required';

        return Inertia::render('Settings/Security', [
            'sections' => SettingsSections::for($user),
            'status' => [
                'totp' => $totp,
                'recoveryRemaining' => $totp ? count($user->recoveryCodes()) : null,
            ],
            'passkeys' => $user->passkeys()->orderBy('id')->get()->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'authenticator' => $p->authenticator,
                'createdAt' => $p->created_at?->toISOString(),
                'lastUsedAt' => $p->last_used_at?->toISOString(),
            ])->values()->all(),
            'required' => $policy->required() && $policy->appliesTo($user),
            'guidance' => $guidance,
            'continueTo' => ($guidance || $flagged) ? SafeIntended::peek($request) : null,
            'methods' => ReauthMethods::for($user),
            'confirmFresh' => $session->recentlyConfirmed(),
            'passkeysEnabled' => (bool) config('security.passkeys_enabled'),
        ]);
    }

    public function continue(Request $request): RedirectResponse
    {
        return redirect(SafeIntended::pull($request), 303)->with('toast', ['key' => 'security.guidance.done']);
    }
}
