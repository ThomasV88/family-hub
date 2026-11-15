<?php

namespace App\Http\Controllers\Security;

use App\Audit\AuditEvent;
use App\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Security\Passkeys\PasskeyCeremony;
use App\Security\SafeIntended;
use App\Security\SensitiveSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Passkeys\Http\Requests\PasskeyVerificationRequest;

/**
 * Passkey as the second step of sign-in. The member is the pending login (session login.id, set after a correct
 * password), never the request's user: nobody is authenticated yet.
 */
class ChallengePasskeyController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function options(Request $request, PasskeyCeremony $ceremony): JsonResponse|RedirectResponse
    {
        $user = $this->pending($request);

        if ($user === null) {
            return $this->noPendingLogin($request);
        }

        return response()->json(['options' => $ceremony->verificationOptions($user)]);
    }

    public function store(PasskeyVerificationRequest $request, PasskeyCeremony $ceremony, SensitiveSession $sensitive): JsonResponse|RedirectResponse
    {
        $user = $this->pending($request);

        if ($user === null) {
            return $this->noPendingLogin($request);
        }

        if (! $ceremony->verify($user, $request)) {
            $this->audit->record(AuditEvent::SecondFactorFailed, $user, ['method' => 'passkey', 'context' => 'login']);

            return response()->json(['error' => 'passkey_failed'], 422);
        }

        Auth::guard('web')->login($user, (bool) $request->session()->get('login.remember', false));
        $request->session()->regenerate();
        $sensitive->confirm();
        $request->session()->forget(['login.id', 'login.remember', 'login.at']);

        return response()->json(['redirect' => SafeIntended::pull($request)]);
    }

    /** The member who passed the password step and has at least one passkey, else null. */
    private function pending(Request $request): ?User
    {
        $id = $request->session()->get('login.id');

        // Not behind SECURITY_PASSKEYS_ENABLED: a member who already has passkeys must still be able to sign in (WR-03).
        if (! is_int($id) && ! (is_string($id) && ctype_digit($id))) {
            return null;
        }

        $user = User::find((int) $id);

        return $user !== null && $user->passkeys()->exists() ? $user : null;
    }

    private function noPendingLogin(Request $request): JsonResponse|RedirectResponse
    {
        return $request->expectsJson()
            ? response()->json(['error' => 'no_pending_login'], 401)
            : redirect('/login');
    }
}
