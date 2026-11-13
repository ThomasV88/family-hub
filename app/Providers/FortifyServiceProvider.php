<?php

namespace App\Providers;

use App\Actions\Fortify\ResetUserPassword;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
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

        $this->app->singleton(PasswordResetResponse::class, fn () => new class implements PasswordResetResponse
        {
            public function toResponse($request): Response
            {
                return redirect('/login', 303)->with('status', 'password_reset');
            }
        });
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

        Fortify::loginView(fn () => Inertia::render('Auth/Login', ['googleEnabled' => false]));

        // Fortify always registers the password-confirmation routes; the app has no such screen, so a GET is a plain 404 instead of a missing-view 500.
        Fortify::confirmPasswordView(fn () => abort(404));

        Fortify::authenticateUsing(function (Request $request) {
            $user = User::where('email', Str::lower((string) $request->input('email')))->first();

            $matches = Hash::check((string) $request->input('password'), $user?->password ?? self::DUMMY_HASH);

            return ($user && $matches) ? $user : null;
        });
    }
}
