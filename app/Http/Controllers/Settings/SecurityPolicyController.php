<?php

namespace App\Http\Controllers\Settings;

use App\Audit\AuditEvent;
use App\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Security\SecondFactorPolicy;
use App\Security\SecurityTimings;
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

    /**
     * Re-confirmation windows (same route rule: access:admin + access:recent + throttle). Each changed value is audited
     * before the write, in one transaction, with the setting name and the new number of seconds only.
     */
    public function updateTimings(Request $request, SecurityTimings $timings, AuditLogger $audit): JsonResponse|RedirectResponse
    {
        $idle = SecurityTimings::range(SecurityTimings::IDLE);
        $confirm = SecurityTimings::range(SecurityTimings::CONFIRM);

        $data = $request->validate([
            'idle_seconds' => ['required', 'integer:strict', 'min:'.$idle['min'], 'max:'.$idle['max']],
            'confirm_window_seconds' => ['required', 'integer:strict', 'min:'.$confirm['min'], 'max:'.$confirm['max']],
        ]);

        DB::transaction(function () use ($timings, $audit, $request, $data): void {
            foreach ([
                SecurityTimings::IDLE => ['idle_seconds', $timings->idle()],
                SecurityTimings::CONFIRM => ['confirm_window_seconds', $timings->confirmWindow()],
            ] as $setting => [$field, $current]) {
                $seconds = (int) $data[$field];
                if ($seconds === $current) {
                    continue;
                }
                $audit->record(AuditEvent::SecurityPolicyChanged, $request->user(), ['setting' => $setting, 'value' => $seconds]);
                $timings->set($setting, $seconds);
            }
        });

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['idle_seconds' => $timings->idle(), 'confirm_window_seconds' => $timings->confirmWindow()]);
        }

        return back(303)->with('toast', ['key' => 'settings.security_timings.toast']);
    }
}
