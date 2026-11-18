<?php

namespace App\Http\Controllers\Health;

use App\Http\Controllers\Controller;
use App\Http\Requests\Health\UploadBatchRequest;
use App\Medical\Batches\BatchKicker;
use App\Medical\Queries\UploadBatchQuery;
use App\Medical\Viewer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Inertia\Response as InertiaResponse;

/**
 * Thin HTTP layer over UploadBatchQuery. Every miss (hidden or unknown person, malformed or unknown batch or item, a batch
 * of another person) is the same 403 with the same message as a failed authorize(). Responses never carry recognised text.
 */
class UploadBatchController extends Controller
{
    private const DENIED = 'This action is unauthorized.';

    public function store(UploadBatchRequest $request, UploadBatchQuery $batches, string $person): JsonResponse
    {
        $r = $batches->create(Viewer::for($request->user()), $request->subject(), $request->user(), $request->boolean('acknowledged'), $this->providers($request), $request->boolean('textNotice'));
        abort_if($r['result'] === 'denied', 403, self::DENIED);

        if ($r['result'] === 'created') {
            return response()->json(['batchId' => $r['batchId']], 201);
        }
        if ($r['result'] === 'exists') {
            return response()->json(['batchId' => $r['batchId'], 'existing' => true]);
        }

        return response()->json($this->conflict($r), 409);
    }

    public function show(UploadBatchRequest $request, UploadBatchQuery $batches, string $person, string $batch): InertiaResponse
    {
        $props = $batches->page(Viewer::for($request->user()), $request->subject(), $batch);
        abort_if($props === null, 403, self::DENIED);

        return inertia('Health/UploadBatch', $props);
    }

    public function status(UploadBatchRequest $request, UploadBatchQuery $batches, string $person, string $batch): JsonResponse
    {
        $payload = $batches->status(Viewer::for($request->user()), $request->subject(), $batch);
        abort_if($payload === null, 403, self::DENIED);

        return response()->json($payload);
    }

    public function current(UploadBatchRequest $request, UploadBatchQuery $batches, string $person, string $batch): Response
    {
        $ok = $batches->setCurrent(Viewer::for($request->user()), $request->subject(), $batch, (string) $request->input('itemId'));
        abort_unless($ok === true, 403, self::DENIED);

        return response()->noContent();
    }

    public function choices(UploadBatchRequest $request, UploadBatchQuery $batches, string $person, string $batch, string $item): JsonResponse
    {
        $r = $batches->saveChoices(Viewer::for($request->user()), $request->subject(), $batch, $item, (array) $request->input('choices'));
        abort_if($r === null, 403, self::DENIED);
        if ($r['result'] === 'not_ready') {
            return response()->json(['reason' => 'not_ready'], 409);
        }

        return response()->json(['choices' => (object) $r['choices']]);
    }

    public function adjustments(UploadBatchRequest $request, UploadBatchQuery $batches, string $person, string $batch, string $item): JsonResponse
    {
        $r = $batches->saveAdjustments(Viewer::for($request->user()), $request->subject(), $batch, $item, $request->only(['brightness', 'contrast', 'bw']));
        abort_if($r === null, 403, self::DENIED);

        return response()->json(['adjustments' => $r]);
    }

    public function rescan(UploadBatchRequest $request, UploadBatchQuery $batches, BatchKicker $kicker, string $person, string $batch, string $item): JsonResponse
    {
        $r = $batches->requestRescan(Viewer::for($request->user()), $request->subject(), $batch, $item, $request->user(), $request->boolean('acknowledged'), $this->providers($request), $request->boolean('textNotice'));
        abort_if($r === null, 403, self::DENIED);
        if ($r['result'] !== 'queued') {
            return response()->json($this->conflict($r), 409);
        }
        $kicker->kick($batch);

        return response()->json(['status' => 'queued'], 202);
    }

    public function continue(UploadBatchRequest $request, UploadBatchQuery $batches, BatchKicker $kicker, string $person, string $batch, string $item): JsonResponse
    {
        try {
            $r = $batches->continue(Viewer::for($request->user()), $request->subject(), $batch, $item, $request->user());
        } catch (ValidationException $e) {
            // Keys are entries.<key>.<field>; nothing was saved.
            return response()->json(['errors' => $e->errors()], 422);
        }
        abort_if($r === null, 403, self::DENIED);
        if ($r['result'] === 'not_ready') {
            return response()->json(['reason' => 'not_ready'], 409);
        }
        $kicker->kick($batch);

        return response()->json(['result' => $r['result'], 'nextItemId' => $r['nextItemId'], 'approved' => $r['approved'], 'rejected' => $r['rejected']]);
    }

    public function removeItem(UploadBatchRequest $request, UploadBatchQuery $batches, BatchKicker $kicker, string $person, string $batch, string $item): Response
    {
        $r = $batches->removeItem(Viewer::for($request->user()), $request->subject(), $batch, $item, $request->user());
        abort_if($r === null, 403, self::DENIED);
        $kicker->kick($batch);

        return response()->noContent();
    }

    public function destroy(UploadBatchRequest $request, UploadBatchQuery $batches, string $person, string $batch): JsonResponse
    {
        $removed = $batches->delete(Viewer::for($request->user()), $request->subject(), $batch, $request->user());
        abort_if($removed === null, 403, self::DENIED);

        return response()->json(['removed' => $removed]);
    }

    /** @return list<string>|null the provider keys the member saw in the notice dialog */
    private function providers(UploadBatchRequest $request): ?array
    {
        return $request->has('providers') ? array_values((array) $request->input('providers')) : null;
    }

    /**
     * @param  array<string, mixed>  $r
     * @return array<string, mixed>
     */
    private function conflict(array $r): array
    {
        $body = ['reason' => $r['result']];
        if (isset($r['providers'])) {
            $body['providers'] = $r['providers'];
        }
        // Only while the switch is on: the dialog must carry the statement about the stored text.
        if ($r['result'] === 'notice_required' && config('ai.ocr.enabled')) {
            $body['textNotice'] = true;
        }

        return $body;
    }
}
