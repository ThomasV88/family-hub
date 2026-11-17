<?php

namespace App\Http\Controllers\Recipes;

use App\Http\Controllers\Controller;
use App\Http\Requests\Recipes\ImportNoticeRequest;
use App\Http\Requests\Recipes\StartImportRequest;
use App\Recipes\Import\ImportReason;
use App\Recipes\Import\RecipeImportQuery;
use App\Recipes\Import\RecipeImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * HTTP side of the recipe import. Responses carry enums, ids and the host only: never the page text, the prompt, the
 * model output, the address path or any error text. Another member's import and an unknown id are the same 404.
 */
class ImportController extends Controller
{
    public function notice(ImportNoticeRequest $request, RecipeImportService $service): JsonResponse|Response
    {
        $shown = array_values(array_map('strval', (array) $request->validated('providers')));
        if ($service->acknowledge($request->user(), $shown)) {
            return response()->noContent();
        }

        return response()->json(['outcome' => 'notice_changed', 'providers' => $service->noticeState($request->user())['providers']], 409);
    }

    public function store(StartImportRequest $request, RecipeImportService $service): JsonResponse
    {
        $result = $service->start($request->user(), (string) $request->validated('url'));

        return match ($result->outcome) {
            'started', 'reused' => response()->json(['outcome' => $result->outcome, 'id' => $result->id], 202),
            'invalid_url' => response()->json(['errors' => ['url' => ['recipes.import.err_url']]], 422),
            'notice_required' => response()->json(['outcome' => 'notice_required', 'providers' => $result->providers], 409),
            'limit' => response()->json(['outcome' => 'limit'], 429),
            default => response()->json(['outcome' => 'unavailable'], 409),
        };
    }

    public function show(Request $request, string $import, RecipeImportQuery $imports): JsonResponse
    {
        $row = $imports->statusFor($request->user(), $import);
        abort_if($row === null, 404);

        return response()->json([
            'id' => $row['id'],
            'status' => $row['status'],
            'reason' => $row['status'] === 'failed' ? ImportReason::for($row['failureCode']) : null,
            'host' => $row['host'],
            'textCut' => $row['textCut'],
        ]);
    }

    public function cancel(Request $request, string $import, RecipeImportService $service): Response
    {
        $service->cancel($request->user(), $import);

        return response()->noContent();
    }

    public function destroy(Request $request, string $import, RecipeImportService $service): Response
    {
        $service->discard($request->user(), $import);

        return response()->noContent();
    }
}
