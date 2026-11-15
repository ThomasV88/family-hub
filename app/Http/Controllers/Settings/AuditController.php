<?php

namespace App\Http\Controllers\Settings;

use App\Audit\AuditEvent;
use App\Audit\AuditQuery;
use App\Http\Controllers\Controller;
use App\Models\AuditEntry;
use App\Models\User;
use App\Support\SettingsSections;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AuditController extends Controller
{
    public function index(Request $request): Response
    {
        $validator = Validator::make($request->query(), [
            'member' => ['nullable', 'integer', 'min:1', Rule::exists('users', 'id')],
            'event' => ['nullable', 'string', Rule::in(AuditEvent::GROUPS)],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        // Invalid values are ignored, never an error page; only a reversed date range is reported.
        $bad = array_keys($validator->errors()->toArray());
        $filters = [
            'member' => in_array('member', $bad, true) ? null : ($request->query('member') ? (int) $request->query('member') : null),
            'event' => in_array('event', $bad, true) ? null : ($request->query('event') ?: null),
            'from' => in_array('from', $bad, true) ? null : ($request->query('from') ?: null),
            'to' => in_array('to', $bad, true) ? null : ($request->query('to') ?: null),
        ];

        $error = null;
        if ($filters['from'] && $filters['to'] && $filters['from'] > $filters['to']) {
            $filters['from'] = $filters['to'] = null;
            $error = 'audit.error.range';
        }

        $entries = (new AuditQuery($filters))->paginate();

        return Inertia::render('Settings/Audit', [
            'sections' => SettingsSections::for($request->user()),
            'entries' => $entries->getCollection()->map(fn (AuditEntry $e) => $this->present($e))->values(),
            'pagination' => ['page' => $entries->currentPage(), 'lastPage' => $entries->lastPage(), 'total' => $entries->total()],
            'members' => User::orderBy('name')->get(['id', 'name'])->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->values(),
            'groups' => AuditEvent::GROUPS,
            'filters' => [
                'member' => $filters['member'],
                'event' => $filters['event'],
                'from' => $filters['from'],
                'to' => $filters['to'],
            ],
            'error' => $error,
        ]);
    }

    /** Only the allowlisted shape: never subject content. */
    private function present(AuditEntry $entry): array
    {
        $event = AuditEvent::tryFrom($entry->event);
        $allowed = $event?->metaKeys() ?? [];
        $meta = array_intersect_key((array) $entry->meta, array_flip($allowed));
        // A bulk row is never listed with a person or document id, whatever an older row may still carry.
        if (! empty($meta['bulk'])) {
            unset($meta['subject'], $meta['document']);
        }

        return [
            'id' => $entry->id,
            'occurredAt' => $entry->occurred_at->toIso8601String(),
            'actorLabel' => $entry->actor_label,
            'event' => $entry->event,
            'group' => $event?->group($entry->module),
            'module' => $entry->module,
            'targetLabel' => $entry->target_label,
            'meta' => (object) $meta,
            'ip' => $entry->ip,
        ];
    }
}
