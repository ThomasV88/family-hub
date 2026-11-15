<?php

use App\Http\Middleware\EnsureRouteDeclaresAccess;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class);

// Phase 6 static guarantees (T-06-65, T-06-67): hidden factor columns, session-key ownership, no test seam or
// passkey feature in production code, recency rules that cannot silently regress.

/** Source of every PHP file below a path inside app/, keyed by relative path. @return array<string, string> */
function secSources(string $dir = 'app'): array
{
    $out = [];
    foreach (File::allFiles(base_path($dir)) as $file) {
        if ($file->getExtension() === 'php') {
            $out[str_replace(base_path().'/', '', $file->getPathname())] = (string) file_get_contents($file->getPathname());
        }
    }

    return $out;
}

/** Source with // and # and block comments removed, so prose cannot trip or hide a code check. */
function secCode(string $source): string
{
    $code = '';
    foreach (token_get_all($source) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $code .= is_array($token) ? $token[1] : $token;
    }

    return $code;
}

/** @return list<string> the middleware gathered for a named route */
function secRouteMiddleware(string $name): array
{
    $route = app('router')->getRoutes()->getByName($name);
    expect($route !== null)->toBeTrue("route {$name} is missing");

    return array_map('strval', $route->gatherMiddleware());
}

it('hides the encrypted factor columns on the user model', function () {
    $hidden = (new User)->getHidden();

    foreach (['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at'] as $column) {
        expect($hidden)->toContain($column);
    }
    expect((new User(['two_factor_secret' => 'x', 'two_factor_recovery_codes' => 'y']))->toArray())
        ->not->toHaveKeys(['two_factor_secret', 'two_factor_recovery_codes']);
});

it('shares no factor or credential data through Inertia', function () {
    $source = secCode((string) file_get_contents(base_path('app/Http/Middleware/HandleInertiaRequests.php')));

    expect($source)->not->toContain('two_factor')->not->toContain('credential')->not->toContain('passkeys');
});

it('keeps the sensitive session keys inside SensitiveSession', function () {
    $offenders = [];
    foreach (secSources() as $path => $source) {
        if ($path === 'app/Security/SensitiveSession.php') {
            continue;
        }
        foreach (['sensitive.active_at', 'auth.password_confirmed_at'] as $key) {
            if (str_contains($source, $key)) {
                $offenders[] = "{$path} mentions {$key}";
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('never references the always-fresh test seam from app code', function () {
    $offenders = [];
    foreach (secSources() as $path => $source) {
        if (str_contains($source, 'AlwaysFreshSensitiveSession')) {
            $offenders[] = $path;
        }
    }

    expect($offenders)->toBe([])
        ->and(secCode((string) file_get_contents(base_path('tests/TestCase.php'))))->toContain('AlwaysFreshSensitiveSession');
});

it('keeps the passkeys feature off and has no passkey-only sign-in route', function () {
    $fortify = secCode((string) file_get_contents(base_path('config/fortify.php')));

    expect($fortify)->not->toMatch('/Features::passkeys\s*\(/')
        ->and(config('fortify.features'))->not->toContain('passkeys');

    $names = collect(app('router')->getRoutes()->getRoutes())->map(fn ($r) => (string) $r->getName());
    expect($names->filter(fn ($n) => str_contains($n, 'passkey.login') || $n === 'passkeys.login' || $n === 'passkey.login'))->toBeEmpty();
});

it('has no usable Fortify password confirmation endpoint', function () {
    // Fortify registers the route; the access map denies it, so it can never be a password oracle.
    expect(EnsureRouteDeclaresAccess::FORTIFY_RULES['password.confirm.store'])->toBe(['disabled'])
        ->and(EnsureRouteDeclaresAccess::FORTIFY_RULES['password.confirm'])->toBe(['disabled'])
        ->and(EnsureRouteDeclaresAccess::FORTIFY_RULES['two-factor.recovery-codes'])->toBe(['disabled']);
});

it('never logs a credential or code context key', function () {
    $offenders = [];
    foreach (secSources() as $path => $source) {
        $code = secCode($source);
        preg_match_all('/Log::\w+\((.*?)\);/s', $code, $calls);
        foreach ($calls[1] as $call) {
            if (preg_match('/[\'"](credential\w*|code|recovery\w*|secret\w*|password\w*|token)[\'"]\s*=>/i', $call)) {
                $offenders[] = "{$path}: ".trim(substr($call, 0, 80));
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('renders no secret, recovery code or credential prop from the security and member controllers', function () {
    $offenders = [];
    foreach (secSources('app/Http/Controllers') as $path => $source) {
        if (! str_contains($path, 'Controllers/Security/') && ! str_contains($path, 'Settings/Member') && ! str_contains($path, 'Settings/SecurityPolicy')) {
            continue;
        }
        $code = secCode($source);
        preg_match_all('/Inertia::render\((.*?)\]\);/s', $code, $renders);
        foreach ($renders[1] as $render) {
            if (preg_match('/[\'"](secret\w*|recoveryCodes|credential\w*|qrCode|twoFactorSecret|two_factor\w*)[\'"]\s*=>/i', $render)) {
                $offenders[] = $path;
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('keeps the recency rule on enrolment, codes, passkeys, policy and member reset', function () {
    foreach (['two-factor.enable', 'two-factor.confirm', 'two-factor.regenerate-recovery-codes'] as $name) {
        expect((EnsureRouteDeclaresAccess::FORTIFY_RULES[$name] ?? null) === ['recent'])->toBeTrue("{$name} lost its recent rule");
        // The map only applies while the route carries no access: middleware of its own.
        expect(array_filter(secRouteMiddleware($name), fn ($m) => str_starts_with($m, 'access:')) === [])->toBeTrue("{$name} gained its own access rule");
    }

    foreach (['passkeys.options', 'passkeys.store', 'passkeys.destroy', 'settings.security.policy', 'settings.members.second_factor.destroy'] as $name) {
        expect(in_array('access:recent', secRouteMiddleware($name), true))->toBeTrue("{$name} lost access:recent");
    }
    expect(secRouteMiddleware('settings.security.policy'))->toContain('access:admin')
        ->and(secRouteMiddleware('settings.members.second_factor.destroy'))->toContain('access:admin');

    // Turning a live factor off checks recency inside the guard (cancelling a pending setup does not).
    expect(secCode((string) file_get_contents(base_path('app/Http/Middleware/GuardTwoFactorRoutes.php'))))->toContain('recentlyConfirmed');
});

it('has no Google re-auth surface (06-REVIEW CR-02: removed, not flagged off)', function () {
    expect(secCode((string) file_get_contents(base_path('config/security.php'))))->not->toContain('google_reauth');
    expect(file_get_contents(base_path('routes/web/google.php')))->not->toContain('confirm-access');
    expect(strtolower((string) file_get_contents(base_path('app/Security/ReauthMethods.php'))))->not->toContain('google');
    expect(file_get_contents(base_path('.env.example')))->not->toContain('SECURITY_GOOGLE_REAUTH');
});
