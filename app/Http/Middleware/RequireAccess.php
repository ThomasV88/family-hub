<?php

namespace App\Http\Middleware;

use App\Access\AccessGate;
use App\Access\AccessLevel;
use App\Access\Module;
use App\Audit\AuditEvent;
use App\Audit\AuditLogger;
use App\Security\SafeIntended;
use App\Security\SensitiveSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route rule guard (alias `access`):
 *   access:public | access:guest | access:authenticated | access:admin | access:module,<module>,<level> | access:recent
 * `recent` demands a signed-in member whose last proof of identity is inside the confirm window.
 * Anything unknown or malformed denies (fail closed).
 */
class RequireAccess
{
    public function __construct(private readonly AccessGate $gate, private readonly AuditLogger $audit) {}

    public function handle(Request $request, Closure $next, string $rule, ?string $module = null, ?string $level = null): Response
    {
        $user = $request->user();

        if ($rule === 'public') {
            return $next($request);
        }

        if ($rule === 'guest') {
            return $user ? redirect('/') : $next($request);
        }

        $moduleEnum = null;
        $levelEnum = null;
        if ($rule === 'module') {
            $moduleEnum = $module !== null ? Module::tryFrom($module) : null;
            $levelEnum = $level !== null ? AccessLevel::fromLabel($level) : null;
            $allowed = $moduleEnum && $levelEnum && $this->gate->allows($user, $moduleEnum, $levelEnum);
        } elseif ($rule === 'authenticated') {
            $allowed = $user !== null;
        } elseif ($rule === 'admin') {
            $allowed = $this->gate->isAdmin($user);
        } elseif ($rule === 'recent') {
            $allowed = $user !== null;
        } else {
            $allowed = false;
        }

        if ($user === null) {
            return $this->toLogin($request);
        }

        if ($rule === 'recent' && ! app(SensitiveSession::class)->recentlyConfirmed()) {
            return $this->toConfirm($request);
        }

        $this->afterDecision($request, $moduleEnum, $levelEnum, (bool) $allowed);

        abort_unless($allowed, 403);

        return $next($request);
    }

    /**
     * Audit write for health and finance, before the controller runs. Deliberately not wrapped in a
     * try/catch: if the row cannot be written the request fails (fail closed).
     */
    protected function afterDecision(Request $request, ?Module $module, ?AccessLevel $level, bool $allowed): void
    {
        if ($module === null || ! $module->audited()) {
            return;
        }

        $user = $request->user();

        if (! $allowed) {
            $this->audit->record(AuditEvent::AccessDenied, $user, $level ? ['level' => $level->label()] : [], null, $module);

            return;
        }

        $write = ! in_array($request->method(), ['GET', 'HEAD'], true);
        $this->audit->record(AuditEvent::forModule($module, $write), $user, [], null, $module);
    }

    /** Stale proof of identity: 423 for JSON callers, otherwise to the confirm page (no audit row). */
    private function toConfirm(Request $request): Response
    {
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['error' => 'confirm_required'], 423);
        }

        $back = $request->isMethod('GET') ? $request->fullUrl() : (string) url()->previous();
        SafeIntended::remember($request, $back);

        return redirect(url('/confirm-access'));
    }

    private function toLogin(Request $request): Response
    {
        $cookie = config('session.cookie');
        $sent = $request->cookies->get($cookie);
        // A cookie whose session no longer exists makes StartSession issue a new id.
        if (is_string($sent) && $sent !== '' && $sent !== $request->session()->getId()) {
            $request->session()->flash('status', 'session_ended');
        }

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            abort(401);
        }

        return $request->isMethod('GET') ? redirect()->guest('/login') : redirect('/login');
    }
}
