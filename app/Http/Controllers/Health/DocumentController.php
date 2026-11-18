<?php

namespace App\Http\Controllers\Health;

use App\Access\AccessLevel;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Health\Concerns\FlashesRemoval;
use App\Http\Requests\Health\StoreDocumentRequest;
use App\Http\Requests\Health\UpdateDocumentRequest;
use App\Medical\Batches\BatchKicker;
use App\Medical\Documents\DocumentRejected;
use App\Medical\Documents\MedicalDocumentStore;
use App\Medical\Documents\PdfRasterizer;
use App\Medical\Queries\ArchiveQuery;
use App\Medical\Queries\DocumentQuery;
use App\Medical\Queries\SubjectQuery;
use App\Medical\Queries\UploadBatchQuery;
use App\Medical\Viewer;
use App\Models\MedicalDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

class DocumentController extends Controller
{
    use FlashesRemoval;

    private const MESSAGES = [
        'too_large' => 'health.upload.err_size',
        'unsupported_type' => 'health.upload.err_type',
        'unreadable' => 'health.upload.err_read',
        'too_many_pixels' => 'health.upload.err_read',
        'limit_reached' => 'health.upload.err_limit',
        'write_failed' => 'health.upload.err_failed',
        'busy' => 'health.upload.err_failed',
        'batch_full' => 'health.batch.err_full',
        'duplicate' => 'health.batch.err_duplicate',
        'batch_closed' => 'health.batch.err_closed',
    ];

    public function store(StoreDocumentRequest $request, DocumentQuery $documents, MedicalDocumentStore $store, UploadBatchQuery $batches, BatchKicker $kicker): JsonResponse|RedirectResponse
    {
        $viewer = Viewer::for($request->user());
        $subject = $request->subject();
        $title = $request->filled('title') ? (string) $request->input('title') : $request->defaultTitle();

        // Refusals that need no file work come first: an unusable visit or a full record writes nothing.
        $batchId = $request->filled('upload_batch_id') ? (string) $request->input('upload_batch_id') : null;
        $visitId = $batchId === null ? $documents->resolveVisit($viewer, $subject, $request->input('visit_id')) : null;
        $item = null;

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
            if ($batchId === null) {
                $created = $documents->create($viewer, $subject, $stored, $title, $visitId);
                $document = (object) ['id' => $created->getKey(), 'kind' => $created->kind, 'title' => $created->title];
            } else {
                // A batch that is not this person's, not open or not an id at all answers null: the same refusal as a closed one.
                $item = $batches->addDocument($viewer, $subject, $batchId, $stored, $title, $request->user()) ?? throw new DocumentRejected('batch_closed');
                $document = (object) ['id' => $item['documentId'], 'kind' => $stored['kind'], 'title' => $title];
            }
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

        if ($item !== null) {
            // After commit: the first document starts without waiting for the next tick.
            $kicker->kick($batchId);
        }

        if ($request->expectsJson()) {
            $payload = ['document' => ['id' => $document->id, 'kind' => $document->kind, 'title' => $document->title]];
            if ($item !== null) {
                $payload['item'] = ['id' => $item['itemId'], 'duplicateOf' => $item['duplicateOf']];
            }

            return response()->json($payload, 201);
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

    /**
     * One page of a PDF as a JPEG, rendered per request and never stored. A malformed or out-of-range page, a non-PDF, an
     * unreadable PDF and every hidden or unknown id end in the same 403. No-store comes from NoStoreDynamicResponses.
     */
    public function page(Request $request, string $person, string $document, string $page, SubjectQuery $subjects, DocumentQuery $documents, MedicalDocumentStore $store, PdfRasterizer $rasterizer): Response
    {
        $row = $this->visibleDocument($request, $person, $document, $subjects, $documents);
        abort_unless($row->kind === 'pdf' && preg_match('/^[1-9][0-9]{0,2}$/', $page) === 1, 403);
        $number = (int) $page;
        abort_if($number > (int) config('ai.pdf.view_max_pages'), 403);

        try {
            $absolute = $store->absolutePath((string) $row->path);
        } catch (\InvalidArgumentException) {
            abort(403);
        }
        abort_unless(is_file($absolute), 403);

        $bytes = $rasterizer->page($absolute, $number);
        abort_if($bytes === null, 403);

        return response($bytes, 200, [
            'Content-Type' => 'image/jpeg',
            'Content-Disposition' => HeaderUtils::makeDisposition(ResponseHeaderBag::DISPOSITION_INLINE, 'page-'.$number.'.jpg'),
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'",
            'Vary' => 'Cookie',
        ]);
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

    public function destroy(Request $request, string $person, string $document, SubjectQuery $subjects, ArchiveQuery $archive, MedicalDocumentStore $store): RedirectResponse
    {
        $viewer = Viewer::for($request->user());
        $subject = $subjects->findVisible($viewer, $person, AccessLevel::Edit);
        abort_if($subject === null, 403);
        $result = $archive->remove($viewer, $subject, 'document', $document);
        abort_if($result === null, 403);
        // After the commit: a failed file removal leaves an orphan for the daily sweep, never a dangling row. An archived
        // document keeps its files (the paths list is empty).
        $store->delete($result['paths']);

        return redirect()->route('health.people.show', ['person' => $subject->getKey()])
            ->with('toast', $this->removalToast($result['outcome'], 'health.doc.deleted'));
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
