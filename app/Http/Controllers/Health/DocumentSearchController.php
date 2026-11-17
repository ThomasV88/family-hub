<?php

namespace App\Http\Controllers\Health;

use App\Http\Controllers\Controller;
use App\Http\Requests\Health\DocumentSearchRequest;
use App\Medical\Documents\Text\SearchTerms;
use App\Medical\Queries\DocumentSearchQuery;
use App\Medical\Viewer;
use Illuminate\Http\JsonResponse;

class DocumentSearchController extends Controller
{
    /**
     * Search by recognised text and title among the documents the viewer may open. The query travels in the POST body
     * only; the answer never says how many documents exist or whether a hidden one matched.
     */
    public function search(DocumentSearchRequest $request, DocumentSearchQuery $search): JsonResponse
    {
        $viewer = Viewer::for($request->user());
        $person = $request->validated('person');
        $terms = SearchTerms::parse((string) $request->validated('q'));

        if ($terms === []) {
            return response()->json(['items' => [], 'hasMore' => false, 'page' => 1]);
        }
        $page = (int) ($request->validated('page') ?? 1);
        $result = $search->search($viewer, $terms, is_string($person) ? $person : null, $page);

        return response()->json(['items' => $result['items'], 'hasMore' => $result['hasMore'], 'page' => $page]);
    }
}
