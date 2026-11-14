<?php

use App\Http\Middleware\EnsureRouteDeclaresAccess;
use App\Http\Middleware\GuardTwoFactorRoutes;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\NoReferrer;
use App\Http\Middleware\NoStoreDynamicResponses;
use App\Http\Middleware\RequireAccess;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\ThrottleAuthRoutes;
use App\Medical\ScrubbedQueryReport;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: [
            // Only the nginx container on the compose network (private ranges) may set forwarded headers;
            // nginx overwrites all of them, so client values never reach PHP.
            '127.0.0.1',
            '10.0.0.0/8',
            '172.16.0.0/12',
            '192.168.0.0/16',
        ]);
        // The theme cookie is written by JS (plain value) and allowlisted server-side in ThemePreference.
        $middleware->redirectGuestsTo('/login');
        $middleware->redirectUsersTo('/');
        $middleware->authenticateSessions();
        $middleware->encryptCookies(except: ['fh_theme']);
        $middleware->alias(['access' => RequireAccess::class, 'no-referrer' => NoReferrer::class]);
        // NoStoreDynamicResponses is first so it wraps every later middleware: refusals produced by the access
        // rules (403, 423, redirects) must be no-store as well.
        $middleware->web(append: [
            NoStoreDynamicResponses::class,
            SetLocale::class,
            HandleInertiaRequests::class,
            EnsureRouteDeclaresAccess::class,
            ThrottleAuthRoutes::class,
            GuardTwoFactorRoutes::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // A QueryException carries the SQL with its bound values; for medical_* tables that is medical content.
        $exceptions->reportable(fn (QueryException $e) => ScrubbedQueryReport::handle($e) ? false : null);

        $forbidden = fn (Request $request) => Inertia::render('Errors/Forbidden')->toResponse($request)->setStatusCode(403);

        $exceptions->render(function (HttpExceptionInterface $e, Request $request) use ($forbidden) {
            // Expired CSRF token (419, prepared from TokenMismatchException): back to login with a clear message.
            if ($e->getStatusCode() === 419 && ! ($request->expectsJson() && ! $request->header('X-Inertia'))) {
                return redirect('/login')->with('status', 'session_ended');
            }

            if ($e->getStatusCode() === 403 && ! $request->is('api/*') && ! ($request->expectsJson() && ! $request->header('X-Inertia'))) {
                return $forbidden($request);
            }

            return null;
        });
        $exceptions->render(function (AuthorizationException $e, Request $request) use ($forbidden) {
            if (! $request->is('api/*') && ! ($request->expectsJson() && ! $request->header('X-Inertia'))) {
                return $forbidden($request);
            }

            return null;
        });
    })->create();
