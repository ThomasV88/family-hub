<?php

namespace Tests\Support;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Passkeys\Actions\StorePasskey;
use Laravel\Passkeys\Actions\VerifyPasskey;
use Laravel\Passkeys\Contracts\PasskeyUser;
use Laravel\Passkeys\Events\PasskeyRegistered;
use Laravel\Passkeys\Events\PasskeyVerified;
use Laravel\Passkeys\Exceptions\InvalidPasskeyException;
use Laravel\Passkeys\Passkey;
use ParagonIE\ConstantTime\Base64UrlSafe;
use RuntimeException;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialRequestOptions;

/**
 * Container fakes for the WebAuthn crypto: the package actions are swapped (PasskeyCeremony resolves them from
 * the container), everything around them (requests, session options, ownership, events, audit) stays real.
 * Real authenticator ceremonies are verified on real devices (plan 06-13 UAT).
 */
class FakePasskeys
{
    /** Every assertion verifies, but only against a passkey the expected user owns (the real ownership check). */
    public static function verifies(): void
    {
        app()->bind(VerifyPasskey::class, fn () => new class extends VerifyPasskey
        {
            public function __invoke(PublicKeyCredential $credential, PublicKeyCredentialRequestOptions $options, ?PasskeyUser $user = null): Passkey
            {
                $passkey = $this->getPasskey($credential);
                $this->ensurePasskeyBelongsToUser($passkey, $user);
                $passkey->forceFill(['last_used_at' => now()])->save();
                PasskeyVerified::dispatch($passkey->user, $passkey);

                return $passkey;
            }
        });
    }

    /** Verification fails like webauthn-lib would (signature, counter, unknown credential...). */
    public static function rejects(?\Throwable $with = null): void
    {
        $with ??= InvalidPasskeyException::make();
        app()->bind(VerifyPasskey::class, fn () => new class($with) extends VerifyPasskey
        {
            public function __construct(private readonly \Throwable $with) {}

            public function __invoke(PublicKeyCredential $credential, PublicKeyCredentialRequestOptions $options, ?PasskeyUser $user = null): Passkey
            {
                throw $this->with;
            }
        });
    }

    /** Registration stores a row for the user (random credential id) and fires the real event. */
    public static function registers(): void
    {
        app()->bind(StorePasskey::class, fn () => new class extends StorePasskey
        {
            public function __invoke(Authenticatable $user, string $name, PublicKeyCredential $credential, PublicKeyCredentialCreationOptions $options): Passkey
            {
                if (! $user instanceof User) {
                    throw new RuntimeException('unexpected user');
                }

                $passkey = $user->passkeys()->create([
                    'name' => $name,
                    'credential_id' => Base64UrlSafe::encodeUnpadded(random_bytes(16)),
                    'credential' => ['aaguid' => '00000000-0000-0000-0000-000000000000'],
                ]);
                PasskeyRegistered::dispatch($user, $passkey);

                return $passkey;
            }
        });
    }

    public static function registrationFails(?\Throwable $with = null): void
    {
        $with ??= new RuntimeException('attestation invalid');
        app()->bind(StorePasskey::class, fn () => new class($with) extends StorePasskey
        {
            public function __construct(private readonly \Throwable $with) {}

            public function __invoke(Authenticatable $user, string $name, PublicKeyCredential $credential, PublicKeyCredentialCreationOptions $options): Passkey
            {
                throw $this->with;
            }
        });
    }

    /** A browser assertion payload that deserialises; $credentialId must be a passkey's credential_id. */
    public static function assertion(string $credentialId): array
    {
        return [
            'id' => $credentialId,
            'rawId' => $credentialId,
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => self::clientData('webauthn.get'),
                'authenticatorData' => Base64UrlSafe::encodeUnpadded(str_repeat("\0", 37)),
                'signature' => Base64UrlSafe::encodeUnpadded('sig'),
                'userHandle' => null,
            ],
        ];
    }

    /** A browser attestation payload that deserialises. */
    public static function attestation(): array
    {
        $credentialId = Base64UrlSafe::encodeUnpadded('new-credential-id');

        return [
            'id' => $credentialId,
            'rawId' => $credentialId,
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => self::clientData('webauthn.create'),
                'attestationObject' => Base64UrlSafe::encodeUnpadded(self::attestationObject()),
            ],
        ];
    }

    private static function clientData(string $type): string
    {
        return Base64UrlSafe::encodeUnpadded(json_encode([
            'type' => $type,
            'challenge' => Base64UrlSafe::encodeUnpadded(str_repeat('c', 32)),
            'origin' => 'http://localhost',
        ]));
    }

    /** CBOR {fmt: "none", attStmt: {}, authData: 37 bytes} */
    private static function attestationObject(): string
    {
        return "\xa3\x63fmt\x64none\x67attStmt\xa0\x68authData\x58\x25".str_repeat("\x00", 37);
    }
}
