<?php

namespace App\Providers;

use App\Actions\Fortify\ResetUserPassword;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\LogoutResponse;
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
    }

    /**
     * Bootstrap any application services.
     *
     * Plan 04 registers the reset views.
     */
    public function boot(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);

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
