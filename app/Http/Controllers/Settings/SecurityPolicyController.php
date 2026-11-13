<?php

namespace App\Http\Controllers\Settings;

use App\Audit\AuditEvent;
use App\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Security\SecondFactorPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SecurityPolicyController extends Controller
{
    // The route carries access:admin + access:recent: both directions need the admin's fresh confirmation.
    public function update(Request $request, SecondFactorPolicy $policy, AuditLogger $audit): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['required' => ['required', 'boolean:strict']]);
        $on = (bool) $data['required'];

        DB::transaction(function () use ($policy, $audit, $request, $on): void {
            $policy->setRequired($on);
            $audit->record(AuditEvent::SecurityPolicyChanged, $request->user(), ['setting' => SecondFactorPolicy::SETTING, 'value' => $on]);
        });

        if ($request->expectsJson()) {
            return response()->json(['required' => $on]);
        }

        return back(303)->with('toast', ['key' => $on ? 'members.security.on_toast' : 'members.security.off_toast']);
    }
}
