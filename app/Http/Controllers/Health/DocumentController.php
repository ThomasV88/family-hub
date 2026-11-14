<?php

namespace App\Http\Controllers\Health;

use App\Http\Controllers\Controller;
use App\Http\Requests\Health\StoreDocumentRequest;
use App\Medical\Documents\DocumentRejected;
use App\Medical\Documents\MedicalDocumentStore;
use App\Medical\Queries\DocumentQuery;
use App\Medical\Viewer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class DocumentController extends Controller
{
    private const MESSAGES = [
        'too_large' => 'health.upload.err_size',
        'unsupported_type' => 'health.upload.err_type',
        'unreadable' => 'health.upload.err_read',
        'too_many_pixels' => 'health.upload.err_read',
        'limit_reached' => 'health.upload.err_limit',
        'write_failed' => 'health.upload.err_failed',
        'busy' => 'health.upload.err_failed',
    ];

    public function store(StoreDocumentRequest $request, DocumentQuery $documents, MedicalDocumentStore $store): JsonResponse|RedirectResponse
    {
        $viewer = Viewer::for($request->user());
        $subject = $request->subject();
        $title = $request->filled('title') ? (string) $request->input('title') : $request->defaultTitle();

        // Refusals that need no file work come first: an unusable visit or a full record writes nothing.
        $visitId = $documents->resolveVisit($viewer, $subject, $request->input('visit_id'));

        $stored = null;
        try {
            if ($documents->countForSubject($viewer, $subject) >= (int) config('medical.max_documents_per_subject')) {
                throw new DocumentRejected('limit_reached');
            }
            $file = $request->file('file');
            $bytes = $file === null ? false : @file_get_contents($file->getRealPath());
            if ($bytes === false) {
                throw new DocumentRejected('unreadable');
            }
            $stored = $store->put((string) $subject->getKey(), $bytes);
            unset($bytes);
            $document = $documents->create($viewer, $subject, $stored, $title, $visitId);
        } catch (DocumentRejected $e) {
            return $this->refuse($request, $e->reason);
        } catch (\Throwable $e) {
            if ($stored !== null) {
                $store->delete(array_values(array_filter([$stored['path'], $stored['thumb_path']])));
            }
            throw $e;
        }

        if ($request->expectsJson()) {
            return response()->json(['document' => ['id' => $document->getKey(), 'kind' => $document->kind, 'title' => $document->title]], 201);
        }

        return redirect()->route('health.people.show', ['person' => $subject->getKey()])
            ->with('toast', ['key' => 'health.upload.done']);
    }

    private function refuse(StoreDocumentRequest $request, string $reason): JsonResponse|RedirectResponse
    {
        $key = self::MESSAGES[$reason];
        $params = ['max' => intdiv((int) config('medical.document_max_bytes'), 1048576), 'count' => (int) config('medical.max_documents_per_subject')];

        if ($request->expectsJson()) {
            return response()->json(['errors' => ['file' => [$key]], 'params' => $params], 422);
        }

        return back()->withErrors(['file' => $key]);
    }
}
