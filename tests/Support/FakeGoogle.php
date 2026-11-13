<?php

namespace Tests\Support;

use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use RuntimeException;
use Symfony\Component\HttpFoundation\RedirectResponse;

/** Replaces Socialite's google driver: no real Google call is ever made. */
class FakeGoogle
{
    public const TOKEN = 'fake-google-access-token-xyz';

    public static function configure(): void
    {
        config(['services.google.client_id' => 'test-client', 'services.google.client_secret' => 'test-secret']);
    }

    /** The callback returns this identity. */
    public static function returns(string $sub, string $email, bool|string|null $verified = true, string $name = 'Gina Google'): void
    {
        $user = new SocialiteUser;
        $user->map(['id' => $sub, 'name' => $name, 'email' => $email, 'avatar' => null]);
        $user->setRaw(array_filter(['sub' => $sub, 'email' => $email, 'email_verified' => $verified], fn ($v) => $v !== null));
        $user->token = self::TOKEN;
        $user->refreshToken = 'fake-refresh-token-xyz';

        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->andReturn($user);
        $provider->shouldReceive('redirect')->andReturn(new RedirectResponse('https://accounts.google.test/auth'));
        $provider->shouldReceive('with')->andReturnSelf();
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    }

    /** The callback throws (state mismatch, denied consent). */
    public static function throws(): void
    {
        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->andThrow(new RuntimeException('invalid state'));
        $provider->shouldReceive('redirect')->andReturn(new RedirectResponse('https://accounts.google.test/auth'));
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    }
}
