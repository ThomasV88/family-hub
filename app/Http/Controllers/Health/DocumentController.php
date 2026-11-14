<?php

namespace App\Http\Controllers\Health;

use App\Access\AccessLevel;
use App\Http\Controllers\Controller;
use App\Http\Requests\Health\StoreDocumentRequest;
use App\Http\Requests\Health\UpdateDocumentRequest;
use App\Medical\Documents\DocumentRejected;
use App\Medical\Documents\MedicalDocumentStore;
use App\Medical\Queries\DocumentQuery;
use App\Medical\Queries\SubjectQuery;
use App\Medical\Viewer;
use App\Models\MedicalDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

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
            // limit_reached can come from the locked re-check in create(), after the files were written.
            if ($stored !== null) {
                $store->delete(array_values(array_filter([$stored['path'], $stored['thumb_path']])));
            }

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

    /**
     * Image derivative, inline. The only file response that may be revalidated (private, no-cache + ETag, see
     * NoStoreDynamicResponses); the 304 can only follow the scoped lookup, so a hidden document never answers 304.
     */
    public function preview(Request $request, string $person, string $document, string $variant, SubjectQuery $subjects, DocumentQuery $documents, MedicalDocumentStore $store): BinaryFileResponse
    {
        $row = $this->visibleDocument($request, $person, $document, $subjects, $documents);
        abort_unless($row->kind === 'image' && in_array($variant, ['thumb', 'view'], true), 403);
        $relative = $variant === 'thumb' ? (string) $row->thumb_path : (string) $row->path;

        $response = $this->fileResponse($store, $relative, 'image/jpeg', HeaderUtils::makeDisposition(ResponseHeaderBag::DISPOSITION_INLINE, 'preview.jpg'));
        $response->headers->set('Cache-Control', 'private, no-cache');
        $response->headers->set('ETag', '"'.substr(hash_hmac('sha256', $row->sha256.$variant, (string) config('app.key')), 0, 32).$variant.'"');
        $response->isNotModified($request);

        return $response;
    }

    /** The original (PDF, HEIC) or the full JPEG as a download. Never cacheable (the middleware sets no-store). */
    public function file(Request $request, string $person, string $document, SubjectQuery $subjects, DocumentQuery $documents, MedicalDocumentStore $store): BinaryFileResponse
    {
        $row = $this->visibleDocument($request, $person, $document, $subjects, $documents);
        $ext = match ($row->kind) {
            'pdf' => 'pdf',
            'heic' => 'heic',
            default => 'jpg',
        };
        $mime = $row->kind === 'image' ? 'image/jpeg' : (string) $row->mime;
        $slug = trim(mb_substr(Str::slug((string) $row->title), 0, 60), '-');
        $name = ($slug !== '' ? $slug : 'document').'.'.$ext;

        $response = $this->fileResponse($store, (string) $row->path, $mime, HeaderUtils::makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $name));
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    public function update(UpdateDocumentRequest $request, string $person, string $document, DocumentQuery $documents): RedirectResponse
    {
        $viewer = Viewer::for($request->user());
        $subject = $request->subject();
        abort_if($documents->find($viewer, $subject, $document, AccessLevel::Edit) === null, 403);

        $visit = $request->input('visit_id');
        $saved = $documents->update(
            $viewer,
            $subject,
            $document,
            (string) $request->validated('title'),
            $request->has('visit_id'),
            is_string($visit) && $visit !== '' ? $visit : null,
        );
        abort_unless($saved, 403);

        return redirect()->route('health.people.show', ['person' => $subject->getKey()])
            ->with('toast', ['key' => 'health.doc.saved']);
    }

    public function destroy(Request $request, string $person, string $document, SubjectQuery $subjects, DocumentQuery $documents, MedicalDocumentStore $store): RedirectResponse
    {
        $viewer = Viewer::for($request->user());
        $subject = $subjects->findVisible($viewer, $person, AccessLevel::Edit);
        abort_if($subject === null, 403);
        $paths = $documents->delete($viewer, $subject, $document);
        abort_if($paths === null, 403);
        // After the commit: a failed file removal leaves an orphan for the daily sweep, never a dangling row.
        $store->delete($paths);

        return redirect()->route('health.people.show', ['person' => $subject->getKey()])
            ->with('toast', ['key' => 'health.doc.deleted']);
    }

    /** Hidden, unknown, malformed and mismatched ids all end in the same 403. */
    private function visibleDocument(Request $request, string $person, string $document, SubjectQuery $subjects, DocumentQuery $documents): MedicalDocument
    {
        $viewer = Viewer::for($request->user());
        $subject = $subjects->findVisible($viewer, $person);
        abort_if($subject === null, 403);

        return $documents->find($viewer, $subject, $document) ?? abort(403);
    }

    /** Hardened file response; a missing or malformed stored path is the same 403 as any other refusal. */
    private function fileResponse(MedicalDocumentStore $store, string $relative, string $mime, string $disposition): BinaryFileResponse
    {
        try {
            $absolute = $store->absolutePath($relative);
        } catch (\InvalidArgumentException) {
            abort(403);
        }
        abort_unless(is_file($absolute), 403);

        $response = new BinaryFileResponse($absolute, 200, [], false, null, false, false);
        $response->headers->set('Content-Type', $mime);
        $response->headers->set('Content-Disposition', $disposition);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Content-Security-Policy', "default-src 'none'");
        $response->headers->set('Vary', 'Cookie');

        return $response;
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
