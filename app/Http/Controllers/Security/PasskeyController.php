<?php

namespace App\Http\Controllers\Security;

use App\Audit\AuditEvent;
use App\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Http\Requests\Security\PasskeyNameRequest;
use App\Models\User;
use App\Security\Passkeys\PasskeyCeremony;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Laravel\Passkeys\Actions\DeletePasskey;
use Laravel\Passkeys\Http\Requests\PasskeyRegistrationRequest;
use Laravel\Passkeys\Passkey;

/** Passkey management for the signed-in member. Enrolment and removal need the 10-minute confirmation (route rule). */
class PasskeyController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function options(Request $request, PasskeyCeremony $ceremony): JsonResponse
    {
        return response()->json(['options' => $ceremony->registrationOptions($this->user($request))]);
    }

    public function store(PasskeyRegistrationRequest $request, PasskeyCeremony $ceremony): JsonResponse
    {
        // Own name rules first, so a bad name never consumes the stored ceremony options.
        $name = Validator::make(
            ['name' => trim((string) $request->input('name'))],
            ['name' => PasskeyNameRequest::nameRules()],
            PasskeyNameRequest::nameMessages(),
        )->validate()['name'];

        $passkey = $ceremony->register($this->user($request), $request, $name);

        if ($passkey === null) {
            return response()->json(['error' => 'passkey_failed'], 422);
        }

        return response()->json(['passkey' => $this->present($passkey)], 201);
    }

    // The id is resolved here, not by route model binding: binding runs before access:* and would answer an
    // anonymous caller differently for an existing and a missing id (06-REVIEW IN-02).
    public function update(PasskeyNameRequest $request, int $passkeyId): JsonResponse
    {
        $user = $this->user($request);
        $passkey = $this->owned($user, $passkeyId);

        $passkey->forceFill(['name' => $request->validated('name')])->save();
        $this->audit->record(AuditEvent::PasskeyRenamed, $user);

        return response()->json(['passkey' => $this->present($passkey)]);
    }

    public function destroy(Request $request, int $passkeyId, DeletePasskey $delete): Response
    {
        $user = $this->user($request);
        $passkey = $this->owned($user, $passkeyId);

        $delete($user, $passkey);

        return response()->noContent();
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    /** Another member's passkey is indistinguishable from a missing one. */
    private function owned(User $user, int $id): Passkey
    {
        return Passkey::query()->where('user_id', $user->getKey())->whereKey($id)->firstOr(fn () => abort(404));
    }

    /** @return array<string, mixed> display fields only: never the credential or its id */
    private function present(Passkey $passkey): array
    {
        return [
            'id' => $passkey->id,
            'name' => $passkey->name,
            'authenticator' => $passkey->authenticator,
            'createdAt' => $passkey->created_at?->toIso8601String(),
            'lastUsedAt' => $passkey->last_used_at?->toIso8601String(),
        ];
    }
}
