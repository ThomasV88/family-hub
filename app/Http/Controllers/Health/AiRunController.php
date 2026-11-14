<?php

namespace App\Http\Controllers\Health;

use App\Access\AccessLevel;
use App\Http\Controllers\Controller;
use App\Http\Requests\Health\AiRunRequest;
use App\Medical\Ai\AgentHealth;
use App\Medical\Ai\RunExtractionJob;
use App\Medical\Queries\AiRunQuery;
use App\Medical\Queries\SubjectQuery;
use App\Medical\Viewer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiRunController extends Controller
{
    public function run(AiRunRequest $request, AiRunQuery $runs, string $person, string $document): JsonResponse
    {
        $viewer = Viewer::for($request->user());
        $result = $runs->start($viewer, $request->subject(), $document, $request->user(), $request->boolean('acknowledged'));
        abort_if($result->outcome === 'denied', 403);

        if ($result->outcome === 'queued') {
            RunExtractionJob::dispatch((string) $result->extractionId)->afterCommit();

            return response()->json(['status' => 'queued', 'extractionId' => $result->extractionId], 202);
        }

        return response()->json(array_filter(['reason' => $result->outcome, 'extractionId' => $result->extractionId], fn ($v) => $v !== null), 409);
    }

    public function cancel(Request $request, SubjectQuery $subjects, AiRunQuery $runs, string $person, string $extraction): JsonResponse
    {
        $viewer = Viewer::for($request->user());
        $subject = $subjects->findVisible($viewer, $person, AccessLevel::Edit);
        abort_if($subject === null, 403);
        $result = $runs->cancel($viewer, $subject, $extraction);
        abort_if($result === null, 403);

        return response()->json($result);
    }

    public function status(Request $request, SubjectQuery $subjects, AiRunQuery $runs, AgentHealth $health, string $person): JsonResponse
    {
        $viewer = Viewer::for($request->user());
        $subject = $subjects->findVisible($viewer, $person);
        abort_if($subject === null, 403);

        return response()->json(['documents' => (object) $runs->statusMap($viewer, $subject), 'available' => $health->runnable()]);
    }
}
