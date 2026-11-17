<?php

use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class);

// T-10-12: a new session-establishing call site must be reviewed against canSignIn(), so it fails here first.

/** Source of each PHP file under app/ with comments removed. @return array<string, string> */
function signInSources(): array
{
    $out = [];
    foreach (File::allFiles(base_path('app')) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $code = '';
        foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }
        $out[str_replace(base_path().'/', '', $file->getPathname())] = $code;
    }

    return $out;
}

it('allows only the reviewed classes to log a user in', function () {
    $allowed = [
        'app/Http/Controllers/Security/ChallengePasskeyController.php',
        'app/Http/Controllers/Auth/InviteAcceptController.php',
        'app/Http/Controllers/Auth/GoogleController.php',
    ];

    $callers = [];
    foreach (signInSources() as $path => $code) {
        if (preg_match('/Auth::login\s*\(|Auth::guard\([^)]*\)\s*->\s*login\s*\(|->\s*loginUsingId\s*\(|Auth::loginUsingId\s*\(|->\s*login\s*\(/', $code) === 1) {
            $callers[] = $path;
        }
    }
    sort($callers);
    sort($allowed);

    expect($callers)->toBe($allowed);
});

it('keeps the canSignIn gate in the interactive sign-in paths', function () {
    $sources = signInSources();

    expect($sources['app/Providers/FortifyServiceProvider.php'])->toContain('canSignIn')
        ->and($sources['app/Http/Controllers/Security/ChallengePasskeyController.php'])->toContain('canSignIn')
        ->and($sources['app/Auth/GoogleLoginService.php'])->toContain('canSignIn')
        ->and($sources['app/Http/Middleware/GuardTwoFactorRoutes.php'])->toContain('canSignIn')
        ->and($sources['app/Actions/Fortify/ResetUserPassword.php'])->toContain('canSignIn');
});

it('registers BlockDisabledSignIn before HandleInertiaRequests', function () {
    $bootstrap = (string) file_get_contents(base_path('bootstrap/app.php'));
    $block = strpos($bootstrap, 'BlockDisabledSignIn::class');
    $inertia = strpos($bootstrap, 'HandleInertiaRequests::class,');

    expect($block)->not->toBeFalse()->and($inertia)->not->toBeFalse()->and($block)->toBeLessThan($inertia);
});
