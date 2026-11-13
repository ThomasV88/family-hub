<?php

namespace App\Providers;

use App\Actions\Fortify\ResetUserPassword;
use App\Auth\RedirectIfSecondFactor;
use App\Http\Controllers\Auth\InviteAcceptController;
use App\Models\User;
use App\Security\SafeIntended;
use App\Security\SensitiveSession;
use App\Support\RequestInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\FailedTwoFactorLoginResponse;
use Laravel\Fortify\Contracts\LoginResponse;
use Laravel\Fortify\Contracts\RecoveryCodesGeneratedResponse;
use Laravel\Fortify\Contracts\TwoFactorConfirmedResponse;
use Laravel\Fortify\Contracts\TwoFactorDisabledResponse;
use Laravel\Fortify\Contracts\TwoFactorEnabledResponse;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse;
use Laravel\Fortify\Contracts\LogoutResponse;
use Laravel\Fortify\Contracts\PasswordResetResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response;

class FortifyServiceProvider extends ServiceProvider
{
    /** Valid bcrypt hash of a random value, compared against for unknown emails (timing equalisation). */
    private const DUMMY_HASH = '$2y$12$KGeQMpZyrhRywPdrgDBz2ehnElY3i3O4.DMl2Xd.RdDsnii5LRwp2';

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(LogoutResponse::class, fn () => new class implements LogoutResponse
        {
            public function toResponse($request): Response
            {
                // The next rendered page tells the browser to drop every stored history entry of this session.
                Inertia::clearHistory();

                return redirect('/login', 303)->with('status', 'signed_out');
            }
        });

        // No enumeration: known and unknown emails get the very same response (research Pattern 4).
        $linkSent = fn () => new class implements FailedPasswordResetLinkRequestResponse, SuccessfulPasswordResetLinkRequestResponse
        {
            public function toResponse($request): Response
            {
                return back()->with('status', 'reset_link_sent');
            }
        };
        $this->app->singleton(SuccessfulPasswordResetLinkRequestResponse::class, $linkSent);
        $this->app->singleton(FailedPasswordResetLinkRequestResponse::class, $linkSent);

        $this->registerSignInResponses();
        $this->registerTwoFactorResponses();

        $this->app->singleton(PasswordResetResponse::class, fn () => new class implements PasswordResetResponse
        {
            public function toResponse($request): Response
            {
                return redirect('/login', 303)->with('status', 'password_reset');
            }
        });
    }

    /**
     * Completing an interactive sign-in counts as proof of identity for Health/Finances and the security
     * actions. Done here (not on the Login event) so a remember-cookie restore never opens the window.
     */
    private function registerSignInResponses(): void
    {
        $this->app->singleton(LoginResponse::class, fn () => new class implements LoginResponse
        {
            public function toResponse($request): Response
            {
                app(SensitiveSession::class)->confirm();

                return $request->wantsJson()
                    ? new JsonResponse(['two_factor' => false])
                    : redirect(SafeIntended::pull($request), 303);
            }
        });

        $this->app->singleton(TwoFactorLoginResponse::class, fn () => new class implements TwoFactorLoginResponse
        {
            public function toResponse($request): Response
            {
                app(SensitiveSession::class)->confirm();
                $request->session()->forget(['login.id', 'login.remember', 'login.at']);

                if ($request->wantsJson()) {
                    return new JsonResponse('', 204);
                }

                $redirect = redirect(SafeIntended::pull($request), 303);

                if ($request->filled('recovery_code') && $request->user() !== null) {
                    $remaining = count($request->user()->recoveryCodes());
                    $redirect->with('toast', ['key' => 'twofactor.recovery.used', 'params' => ['n' => $remaining], 'count' => $remaining]);
                }

                return $redirect;
            }
        });

        $this->app->singleton(FailedTwoFactorLoginResponse::class, fn () => new class implements FailedTwoFactorLoginResponse
        {
            public function toResponse($request): Response
            {
                $recovery = $request->filled('recovery_code');
                $key = $recovery ? 'recovery_code' : 'code';
                $message = $recovery ? 'twofactor.error.recovery' : 'twofactor.error.code';

                if ($request->wantsJson()) {
                    return new JsonResponse(['message' => $message, 'errors' => [$key => [$message]]], 422);
                }

                return redirect()->route('two-factor.login')->withErrors([$key => $message]);
            }
        });
    }

    /** Factor management answers JSON only; secrets and codes are never cached. */
    private function registerTwoFactorResponses(): void
    {
        $this->app->singleton(TwoFactorEnabledResponse::class, fn () => new class implements TwoFactorEnabledResponse
        {
            public function toResponse($request): Response
            {
                return $request->wantsJson() ? new JsonResponse(['enabled' => true]) : back();
            }
        });

        $this->app->singleton(TwoFactorDisabledResponse::class, fn () => new class implements TwoFactorDisabledResponse
        {
            public function toResponse($request): Response
            {
                return $request->wantsJson() ? new JsonResponse(['disabled' => true]) : back();
            }
        });

        $codes = fn () => new class implements RecoveryCodesGeneratedResponse, TwoFactorConfirmedResponse
        {
            public function toResponse($request): Response
            {
                if (! $request->wantsJson()) {
                    return back();
                }

                $response = new JsonResponse(['recoveryCodes' => array_values($request->user()->recoveryCodes())]);
                $response->headers->set('Cache-Control', 'no-store, private');

                return $response;
            }
        };
        $this->app->singleton(RecoveryCodesGeneratedResponse::class, $codes);
        $this->app->singleton(TwoFactorConfirmedResponse::class, $codes);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);

        Fortify::requestPasswordResetLinkView(fn () => Inertia::render('Auth/ForgotPassword'));

        Fortify::resetPasswordView(function (Request $request) {
            $email = Str::lower((string) $request->query('email', ''));
            $token = (string) $request->route('token');
            $user = $email !== '' ? User::where('email', $email)->first() : null;

            return Inertia::render('Auth/ResetPassword', [
                'token' => $token,
                'email' => $email,
                'valid' => $user !== null && Password::broker()->tokenExists($user, $token),
            ]);
        });

        Fortify::loginView(fn () => Inertia::render('Auth/Login', ['googleEnabled' => InviteAcceptController::googleEnabled()]));

        // Fortify always registers the password-confirmation routes; the app has no such screen, so a GET is a plain 404 instead of a missing-view 500.
        Fortify::confirmPasswordView(fn () => abort(404));

        Fortify::redirectUserForTwoFactorAuthenticationUsing(RedirectIfSecondFactor::class);

        // Booleans only: no ids, names or emails reach the page.
        Fortify::twoFactorChallengeView(function (Request $request) {
            $user = User::find($request->session()->get('login.id'));
            $totp = $user !== null && $user->two_factor_secret !== null && $user->two_factor_confirmed_at !== null;
            $passkey = $user !== null && (bool) config('security.passkeys_enabled') && $user->passkeys()->exists();

            return Inertia::render('Auth/TwoFactorChallenge', ['totp' => $totp, 'passkey' => $passkey]);
        });

        Fortify::authenticateUsing(function (Request $request) {
            $user = User::where('email', RequestInput::email($request))->first();

            $matches = Hash::check(RequestInput::string($request, 'password'), $user?->password ?? self::DUMMY_HASH);

            return ($user && $matches) ? $user : null;
        });
    }
}
