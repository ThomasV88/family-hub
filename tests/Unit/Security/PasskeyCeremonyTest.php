<?php

use App\Models\User;
use App\Security\Passkeys\PasskeyCeremony;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Passkeys\Actions\GenerateRegistrationOptions;
use Laravel\Passkeys\Actions\GenerateVerificationOptions;
use Laravel\Passkeys\Exceptions\InvalidPasskeyException;
use Laravel\Passkeys\Http\Requests\PasskeyRegistrationRequest;
use Laravel\Passkeys\Http\Requests\PasskeyVerificationRequest;
use Laravel\Passkeys\Support\WebAuthn;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Tests\Support\FakePasskeys;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function ceremonyRequest(string $class, array $payload, array $session = []): mixed
{
    $request = $class::create('/x', 'POST', $payload);
    $request->setLaravelSession(app('session.store'));
    foreach ($session as $key => $value) {
        $request->session()->put($key, $value);
    }
    $request->setContainer(app())->setRedirector(app('redirect'))->validateResolved();

    return $request;
}

function ceremonyAssertionRequest(?string $credentialId = null, bool $withOptions = true): PasskeyVerificationRequest
{
    $session = $withOptions ? ['passkey.verification_options' => WebAuthn::toJson(app(GenerateVerificationOptions::class)())] : [];

    return ceremonyRequest(PasskeyVerificationRequest::class, ['credential' => FakePasskeys::assertion($credentialId ?? Base64UrlSafe::encodeUnpadded('x'))], $session);
}

function ceremonyPasskey(User $user, string $bytes = 'cred-one'): string
{
    $id = Base64UrlSafe::encodeUnpadded($bytes);
    DB::table('passkeys')->insert([
        'user_id' => $user->id, 'name' => 'Laptop', 'credential_id' => $id,
        'credential' => json_encode(['x' => 1]), 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

it('returns false and logs only the exception class when verification throws', function (Throwable $failure) {
    $user = User::factory()->create();
    $id = ceremonyPasskey($user);
    FakePasskeys::rejects($failure);
    Log::spy();

    expect(app(PasskeyCeremony::class)->verify($user, ceremonyAssertionRequest($id)))->toBeFalse();

    Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context) use ($failure) {
        return $context === ['ceremony' => 'verify', 'exception' => $failure::class]
            && ! str_contains(json_encode($context), 'sig');
    });
})->with([
    'invalid passkey' => fn () => InvalidPasskeyException::make('secret-detail'),
    'counter or signature' => fn () => new RuntimeException('Invalid counter. secret-detail'),
    'type error' => fn () => new TypeError('secret-detail'),
]);

it('returns false when the stored ceremony options are gone', function () {
    $user = User::factory()->create();
    $id = ceremonyPasskey($user);
    FakePasskeys::verifies();

    expect(app(PasskeyCeremony::class)->verify($user, ceremonyAssertionRequest($id, withOptions: false)))->toBeFalse();
});

it('verifies a passkey owned by the expected member and refuses another member\'s', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $id = ceremonyPasskey($owner);
    FakePasskeys::verifies();
    $ceremony = app(PasskeyCeremony::class);

    expect($ceremony->verify($owner, ceremonyAssertionRequest($id)))->toBeTrue()
        ->and($ceremony->verify($other, ceremonyAssertionRequest($id)))->toBeFalse()
        ->and($ceremony->verify($owner, ceremonyAssertionRequest(Base64UrlSafe::encodeUnpadded('unknown'))))->toBeFalse();
});

it('returns null and logs only the exception class when registration throws', function () {
    $user = User::factory()->create();
    FakePasskeys::registrationFails(new RuntimeException('attestation secret-detail'));
    Log::spy();

    $request = ceremonyRequest(
        PasskeyRegistrationRequest::class,
        ['name' => 'Phone', 'credential' => FakePasskeys::attestation()],
        ['passkey.registration_options' => WebAuthn::toJson(app(GenerateRegistrationOptions::class)($user))],
    );

    expect(app(PasskeyCeremony::class)->register($user, $request, 'Phone'))->toBeNull();
    expect($user->passkeys()->count())->toBe(0);
    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $m, array $c) => $c === ['ceremony' => 'register', 'exception' => RuntimeException::class]);
});

it('builds user-verification-required creation options and keeps them in the session', function () {
    $user = User::factory()->create();
    session()->start();

    $options = app(PasskeyCeremony::class)->registrationOptions($user);

    expect($options['authenticatorSelection']['userVerification'])->toBe('required')
        ->and(session('passkey.registration_options'))->toBeString();
});

it('limits verification options to the member\'s own passkeys', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $mine = ceremonyPasskey($user, 'mine');
    ceremonyPasskey($other, 'theirs');
    session()->start();

    $options = app(PasskeyCeremony::class)->verificationOptions($user);

    expect(array_column($options['allowCredentials'], 'id'))->toBe([$mine]);
});
