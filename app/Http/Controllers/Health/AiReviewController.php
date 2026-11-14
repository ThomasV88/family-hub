<?php

namespace App\Http\Controllers\Health;

use App\Access\AccessLevel;
use App\Http\Controllers\Controller;
use App\Http\Requests\Health\AiDecisionRequest;
use App\Medical\Queries\AiReviewQuery;
use App\Medical\Queries\SubjectQuery;
use App\Medical\Viewer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

class AiReviewController extends Controller
{
    public function show(Request $request, SubjectQuery $subjects, AiReviewQuery $reviews, string $person, string $document): Response|RedirectResponse
    {
        $viewer = Viewer::for($request->user());
        $subject = $subjects->findVisible($viewer, $person, AccessLevel::Edit);
        abort_if($subject === null, 403);

        $extraction = $request->query('extraction');
        abort_if($extraction !== null && ! is_string($extraction), 403);

        $props = $reviews->review($viewer, $subject, $document, $extraction);
        abort_if($props === null, 403);
        if (isset($props['redirect'])) {
            return redirect()->route('health.people.show', ['person' => $subject->getKey(), 'panel' => 'document', 'id' => $document]);
        }

        $pane = $request->query('pane');
        $props['pane'] = in_array($pane, ['document', 'entries'], true) ? $pane : null;

        return inertia('Health/AiReview', $props);
    }

    public function accept(AiDecisionRequest $request, AiReviewQuery $reviews, string $person, string $extraction, string $entry): JsonResponse
    {
        $values = $request->input('values');
        $result = $reviews->accept(Viewer::for($request->user()), $request->subject(), $extraction, $entry, is_array($values) ? $values : null, $request->user());

        return $this->respond($result);
    }

    public function reject(AiDecisionRequest $request, AiReviewQuery $reviews, string $person, string $extraction, string $entry): JsonResponse
    {
        return $this->respond($reviews->reject(Viewer::for($request->user()), $request->subject(), $extraction, $entry, $request->user()));
    }

    public function undo(AiDecisionRequest $request, AiReviewQuery $reviews, string $person, string $extraction, string $entry): JsonResponse
    {
        return $this->respond($reviews->undo(Viewer::for($request->user()), $request->subject(), $extraction, $entry, $request->user()));
    }

    /** @param  array<string, mixed>|null  $result */
    private function respond(?array $result): JsonResponse
    {
        abort_if($result === null, 403);

        return response()->json($result, $result['state'] === 'superseded' ? 409 : 200);
    }
}
