<?php

namespace App\Security\Passkeys;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Laravel\Passkeys\Actions\GenerateRegistrationOptions;
use Laravel\Passkeys\Actions\GenerateVerificationOptions;
use Laravel\Passkeys\Actions\StorePasskey;
use Laravel\Passkeys\Actions\VerifyPasskey;
use Laravel\Passkeys\Http\Requests\PasskeyRegistrationRequest;
use Laravel\Passkeys\Http\Requests\PasskeyVerificationRequest;
use Laravel\Passkeys\Passkey;
use Laravel\Passkeys\Support\WebAuthn;
use Throwable;

/**
 * The single seam between the app and laravel/passkeys + webauthn-lib. Package actions are resolved from the
 * container (tests swap them), every verification or storage failure of any kind becomes null/false, and only the
 * exception class is ever logged: credential payloads, names and exception messages stay out of the logs.
 */
class PasskeyCeremony
{
    /** @return array<string, mixed> browser-ready creation options; the serialized form waits in the session */
    public function registrationOptions(User $user): array
    {
        $options = app(GenerateRegistrationOptions::class)($user);

        session()->put('passkey.registration_options', WebAuthn::toJson($options));

        return WebAuthn::toBrowserArray($options);
    }

    public function register(User $user, PasskeyRegistrationRequest $request, string $name): ?Passkey
    {
        try {
            return app(StorePasskey::class)($user, $name, $request->credential(), $request->registrationOptions());
        } catch (Throwable $e) {
            $this->failed('register', $e);

            return null;
        }
    }

    /** @return array<string, mixed> request options limited to this member's own passkeys */
    public function verificationOptions(User $user): array
    {
        $options = app(GenerateVerificationOptions::class)($user);

        session()->put('passkey.verification_options', WebAuthn::toJson($options));

        return WebAuthn::toBrowserArray($options);
    }

    /** True only when the assertion verifies against one of $user's own passkeys. */
    public function verify(User $user, PasskeyVerificationRequest $request): bool
    {
        try {
            app(VerifyPasskey::class)($request->credential(), $request->verificationOptions(), $user);

            return true;
        } catch (Throwable $e) {
            $this->failed('verify', $e);

            return false;
        }
    }

    private function failed(string $ceremony, Throwable $e): void
    {
        Log::warning('passkey ceremony failed', ['ceremony' => $ceremony, 'exception' => $e::class]);
    }
}
