<?php

namespace App\Http\Controllers\Health;

use App\Access\AccessLevel;
use App\Access\Module;
use App\Audit\AuditEvent;
use App\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Medical\EmergencyCard;
use App\Medical\Queries\SubjectQuery;
use App\Medical\Viewer;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * The emergency card. Authenticated, View level, audited before it is built (an unauditable read is never shown; the
 * exception is deliberately not caught). No signed URL, token or unauthenticated variant exists.
 */
class EmergencyCardController extends Controller
{
    public function show(Request $request, string $person, SubjectQuery $subjects, AuditLogger $audit, EmergencyCard $card): Response
    {
        $viewer = Viewer::for($request->user());
        $subject = $subjects->findVisible($viewer, $person, AccessLevel::View);
        abort_if($subject === null, 403);

        $audit->record(AuditEvent::MedicalExported, $request->user(), [
            'subject' => (string) $subject->getKey(),
            'scope' => 'emergency',
            'format' => 'html',
        ], null, Module::Health);

        return inertia('Health/EmergencyCard', $card->props($viewer, $subject, now((string) config('family.timezone'))->toDateString()));
    }
}
