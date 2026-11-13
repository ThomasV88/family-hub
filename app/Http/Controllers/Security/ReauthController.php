<?php

namespace App\Http\Controllers\Security;

use App\Audit\AuditEvent;
use App\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Http\Requests\Security\ReauthRequest;
use App\Models\User;
use App\Security\ReauthMethods;
use App\Security\SafeIntended;
use App\Security\SensitiveSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;

/** "Confirm it's you": authenticator code or passkey for members with a second factor, the password only for members without one (recovery codes are deliberately not accepted here). */
class ReauthController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function show(Request $request): Response
    {
        return Inertia::render('Auth/ConfirmAccess', [
            'methods' => ReauthMethods::for($request->user()),
            'minutes' => intdiv((int) config('security.idle_seconds'), 60),
            'interrupted' => $request->session()->get('status') === 'reauth_interrupted',
        ]);
    }

    public function store(ReauthRequest $request, SensitiveSession $session): JsonResponse|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $method = $request->has('password') ? 'password' : 'totp';
        $field = $method === 'password' ? 'password' : 'code';

        $ok = $method === 'password'
            ? $this->passwordValid($user, (string) $request->input('password'))
            : $this->codeValid($user, (string) $request->input('code'));

        $json = $request->expectsJson() && ! $request->header('X-Inertia');

        if (! $ok) {
            $this->audit->record(AuditEvent::SecondFactorFailed, $user, ['method' => $method, 'context' => 'reauth']);
            $error = $method === 'password' ? 'reauth.error.password' : 'twofactor.error.code';

            return $json
                ? response()->json(['errors' => [$field => $error]], 422)
                : back()->withErrors([$field => $error]);
        }

        // Audit first (fail closed, 06-REVIEW WR-04): if the row cannot be written the request fails and the
        // windows were never opened. Then open both the idle window and the 10-minute security window, in page
        // and dialog mode alike.
        $this->audit->record(AuditEvent::ReauthConfirmed, $user, ['method' => $method]);
        $session->confirmRotating();

        return $json
            ? response()->json(['confirmed' => true])
            : redirect(SafeIntended::pull($request), 303);
    }

    public function restart(Request $request): RedirectResponse
    {
        app(SensitiveSession::class)->clear();
        Auth::guard('web')->logout();
        $request->session()->regenerate();

        return redirect('/login', 303);
    }

    private function passwordValid(User $user, string $password): bool
    {
        return ReauthMethods::passwordAllowed($user) && $password !== '' && Hash::check($password, (string) $user->password);
    }

    private function codeValid(User $user, string $code): bool
    {
        if (! ReauthMethods::hasTotp($user) || $code === '') {
            return false;
        }

        $secret = Fortify::currentEncrypter()->decrypt($user->two_factor_secret);

        return (bool) app(TwoFactorAuthenticationProvider::class)->verify($secret, $code);
    }
}
