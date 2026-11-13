<?php

namespace App\Http\Controllers\Auth;

use App\Auth\InvalidInviteException;
use App\Auth\InviteService;
use App\Http\Controllers\Controller;
use App\Http\Middleware\SetLocale;
use App\Http\Requests\AcceptInvitationRequest;
use App\Models\Invitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Inertia\Inertia;
use Inertia\Response;

class InviteAcceptController extends Controller
{
    public function show(Request $request, string $token): Response
    {
        $invitation = Invitation::findByToken($token);

        // First visit without a language choice: render in the language the admin picked and remember it.
        // No redirect: a browser that refuses the cookie would otherwise loop (and burn the throttle budget).
        if ($invitation !== null && $invitation->state() === 'valid' && ! $request->cookies->has('locale')
            && in_array($invitation->locale, SetLocale::SUPPORTED, true)) {
            Cookie::queue(cookie('locale', $invitation->locale, 60 * 24 * 365, null, null, (bool) config('session.secure'), true, false, 'lax'));

            app()->setLocale($invitation->locale);
        }

        return $this->page($invitation, $token);
    }

    public function store(AcceptInvitationRequest $request, string $token, InviteService $invites): Response|RedirectResponse
    {
        $invitation = Invitation::findByToken($token);
        if ($invitation === null || $invitation->state() !== 'valid') {
            return $this->page($invitation, $token);
        }

        try {
            $user = $invites->accept($invitation, ['name' => $request->validated('name'), 'password' => $request->validated('password')]);
        } catch (InvalidInviteException) {
            return $this->page($invitation->refresh(), $token);
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect('/', 303)->with('toast', ['key' => 'invite.accept.welcome', 'params' => ['name' => $user->name]]);
    }

    /** Revoked and unknown links look the same; the invited email is only revealed for a valid link. */
    private function page(?Invitation $invitation, string $token): Response
    {
        $state = $invitation?->state() ?? 'revoked';

        return Inertia::render('Auth/AcceptInvite', [
            'state' => $state,
            'email' => $state === 'valid' ? $invitation->email : null,
            'token' => $state === 'valid' ? $token : null,
            'googleEnabled' => self::googleEnabled(),
        ]);
    }

    public static function googleEnabled(): bool
    {
        return filled(config('services.google.client_id')) && filled(config('services.google.client_secret'));
    }
}
