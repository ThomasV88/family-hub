<?php

namespace App\Http\Controllers\Health;

use App\Access\Module;
use App\Audit\AuditEvent;
use App\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Http\Requests\Health\ExportRequest;
use App\Medical\Export\Exporter;
use App\Medical\Export\Filename;
use App\Medical\Export\JsonFormat;
use App\Medical\Export\MarkdownFormat;
use App\Medical\Queries\ExportQuery;
use App\Medical\Viewer;
use App\Models\MedicalSubject;
use Illuminate\Http\Response;
use Inertia\Response as InertiaResponse;

/**
 * On-demand exports. Generated in memory and sent straight to the client: nothing is stored, and the audit row is
 * written first so an export that cannot be audited is never produced (the exception is deliberately not caught).
 */
class ExportController extends Controller
{
    public function show(ExportRequest $request, ExportQuery $query): InertiaResponse
    {
        $viewer = Viewer::for($request->user());
        $subject = $request->subject();

        return inertia('Health/Export', [
            'person' => ['id' => (string) $subject->getKey(), 'name' => (string) $subject->name],
            'specialtiesWithVisits' => $query->specialtiesWithVisits($viewer, $subject),
            'hasUnverified' => $query->hasUnverified($viewer, $subject),
        ]);
    }

    public function print(ExportRequest $request, AuditLogger $audit, Exporter $exporter): InertiaResponse
    {
        $viewer = Viewer::for($request->user());
        $subject = $request->subject();
        $options = $request->options();
        $this->audit($audit, $request, $subject, $options, 'html');

        return inertia('Health/ExportPrint', [
            'person' => ['id' => (string) $subject->getKey()],
            // The footer date is taken in the browser when the page is shown, so the response carries no clock.
            'export' => array_diff_key($exporter->build($viewer, $subject, $options), ['generated_at' => true]),
        ]);
    }

    public function download(ExportRequest $request, AuditLogger $audit, Exporter $exporter): Response
    {
        $viewer = Viewer::for($request->user());
        $subject = $request->subject();
        $options = $request->options();
        $format = $options['format'] === 'md' ? 'md' : 'json';
        $this->audit($audit, $request, $subject, $options, $format);

        $model = $exporter->build($viewer, $subject, $options);
        $body = $format === 'md' ? MarkdownFormat::render($model) : JsonFormat::render($model);

        return response($body, 200, [
            'Content-Type' => $format === 'md' ? MarkdownFormat::CONTENT_TYPE : JsonFormat::CONTENT_TYPE,
            'Content-Disposition' => Filename::disposition($options['scope'] === 'specialty' ? (string) $options['specialty'] : 'all', $format),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /** @param  array<string, mixed>  $options */
    private function audit(AuditLogger $audit, ExportRequest $request, MedicalSubject $subject, array $options, string $format): void
    {
        $audit->record(AuditEvent::MedicalExported, $request->user(), [
            'subject' => (string) $subject->getKey(),
            'scope' => $options['scope'] === 'specialty' ? 'specialty' : 'person',
            'format' => $format,
        ], null, Module::Health);
    }
}
