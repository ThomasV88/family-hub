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
use Illuminate\Http\Request;
use Laravel\Passkeys\Http\Requests\PasskeyVerificationRequest;

/** "Confirm it's you" with a passkey, for the signed-in member's own passkeys only. */
class ReauthPasskeyController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function options(Request $request, PasskeyCeremony $ceremony): JsonResponse
    {
        $user = $this->user($request);
        abort_unless($user->passkeys()->exists(), 404);

        return response()->json(['options' => $ceremony->verificationOptions($user)]);
    }

    public function store(PasskeyVerificationRequest $request, PasskeyCeremony $ceremony, SensitiveSession $sensitive): JsonResponse
    {
        $user = $this->user($request);
        abort_unless($user->passkeys()->exists(), 404);

        if (! $ceremony->verify($user, $request)) {
            $this->audit->record(AuditEvent::SecondFactorFailed, $user, ['method' => 'passkey', 'context' => 'reauth']);

            return response()->json(['error' => 'passkey_failed'], 422);
        }

        // Audit first (fail closed, 06-REVIEW WR-04): no audit row, no confirmation.
        $this->audit->record(AuditEvent::ReauthConfirmed, $user, ['method' => 'passkey']);
        $sensitive->confirmRotating();

        return $request->input('mode') === 'dialog'
            ? response()->json(['confirmed' => true])
            : response()->json(['redirect' => SafeIntended::pull($request)]);
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
