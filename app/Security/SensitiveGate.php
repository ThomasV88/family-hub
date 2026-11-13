<?php

namespace App\Security;

use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Idle re-authentication gate for sensitive modules. Called by RequireAccess only after an allow decision.
 * Returns a response to short-circuit the request, or null to continue.
 */
class SensitiveGate
{
    public function __construct(private readonly SecondFactorPolicy $policy) {}

    public function check(Request $request, User $user): ?Response
    {
        $json = $this->jsonCaller($request);
        $get = in_array($request->method(), ['GET', 'HEAD'], true);

        if ($this->policy->mustEnrol($user)) {
            if ($json) {
                return response()->json(['error' => 'second_factor_required'], 423);
            }
            $this->remember($request, $get);
            $request->session()->flash('status', 'second_factor_required');

            return redirect(url('/settings/security'), $get ? 302 : 303);
        }

        $passive = $this->passive($request);

        if (! app(SensitiveSession::class)->fresh()) {
            if ($passive) {
                return response()->json(['error' => 'reauth_required'], 423);
            }

            $inertia = (bool) $request->header('X-Inertia');
            $mode = $request->headers->get('Sec-Fetch-Mode');
            $nonNavigation = ! $inertia && $mode !== null && $mode !== 'navigate';

            if ($json || $nonNavigation) {
                $this->remember($request, false);
                if (! $get) {
                    $request->session()->flash('status', 'reauth_interrupted');
                }

                return $json ? response()->json(['error' => 'reauth_required'], 423) : response('', 423);
            }

            $this->remember($request, $get);
            if (! $get) {
                $request->session()->flash('status', 'reauth_interrupted');
            }

            return redirect(url('/confirm-access'), $get ? 302 : 303);
        }

        if (! $passive) {
            app(SensitiveSession::class)->touch();
        }

        return null;
    }

    private function jsonCaller(Request $request): bool
    {
        return $request->expectsJson() && ! $request->header('X-Inertia');
    }

    private function passive(Request $request): bool
    {
        return strtolower((string) $request->header('Purpose')) === 'prefetch'
            || $request->header('X-Fh-Passive') === '1';
    }

    /** GET remembers the full address; everything else goes back to the page the member came from. */
    private function remember(Request $request, bool $useFullUrl): void
    {
        SafeIntended::remember($request, $useFullUrl ? $request->fullUrl() : (string) url()->previous());
    }
}
