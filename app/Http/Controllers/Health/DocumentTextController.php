<?php

namespace App\Http\Controllers\Health;

use App\Access\AccessLevel;
use App\Http\Controllers\Controller;
use App\Medical\Queries\DocumentQuery;
use App\Medical\Queries\DocumentTextQuery;
use App\Medical\Queries\DocumentTextStore;
use App\Medical\Queries\SubjectQuery;
use App\Medical\Viewer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The recognised text of one document. Access is the document's own access: a member who may see the document may read
 * its text (view-only included), only editors may remove it, and a hidden, archived, unknown or malformed person or
 * document ends in the same 403 as every other document route. No-store comes from NoStoreDynamicResponses.
 */
class DocumentTextController extends Controller
{
    public function show(Request $request, string $person, string $document, SubjectQuery $subjects, DocumentQuery $documents, DocumentTextQuery $texts): JsonResponse
    {
        $viewer = Viewer::for($request->user());
        $subject = $subjects->findVisible($viewer, $person);
        abort_if($subject === null, 403);
        abort_if($documents->find($viewer, $subject, $document) === null, 403);

        // Visible document without text: the one honest 404 (the document is the member's own to see).
        $text = $texts->forDocument($viewer, $subject, $document);
        abort_if($text === null, 404);

        $pages = [];
        foreach ($text->pages() as $index => $page) {
            $pages[] = ['page' => $index + 1, 'text' => $page];
        }

        return response()->json([
            'pages' => $pages,
            'pagesRead' => $text->pagesRead,
            'pagesTotal' => $text->pagesTotal,
            'complete' => $text->complete,
            'language' => $text->language,
        ]);
    }

    public function destroy(Request $request, string $person, string $document, SubjectQuery $subjects, DocumentTextStore $store): Response
    {
        $viewer = Viewer::for($request->user());
        $subject = $subjects->findVisible($viewer, $person, AccessLevel::Edit);
        abort_if($subject === null, 403);
        // Nothing removed (hidden, archived, unknown, malformed, already removed) is the same 403 as any other write miss.
        abort_unless($store->removeAudited($viewer, (string) $subject->getKey(), $document), 403);

        return response()->noContent();
    }
}
