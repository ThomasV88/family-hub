<?php

namespace App\Medical\Queries;

use App\Medical\Documents\Text\DocumentText;
use App\Medical\Viewer;
use App\Medical\Visibility;
use App\Models\MedicalSubject;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Read side of the recognised document text for the request. Every statement starts from the viewer's visible,
 * non-archived documents (the text follows the document's access, never its own), so a hidden or archived document
 * reads exactly like one that has no text. The body is only selected where a caller needs it.
 */
class DocumentTextQuery
{
    /** The text of one visible, non-archived document of the subject, or null (no text, hidden, archived, unknown or malformed). */
    public function forDocument(Viewer $viewer, MedicalSubject $subject, string $documentId): ?DocumentText
    {
        if (! Str::isUuid($documentId)) {
            return null;
        }

        $row = Visibility::apply(
            DB::table('medical_documents')
                ->join('medical_document_texts', function ($join) {
                    $join->on('medical_document_texts.document_id', '=', 'medical_documents.id')
                        ->on('medical_document_texts.subject_id', '=', 'medical_documents.subject_id');
                })
                ->where('medical_documents.id', $documentId)
                ->where('medical_documents.subject_id', $subject->getKey()),
            $viewer,
            'medical_documents.subject_id',
        )->first([
            'medical_document_texts.document_id', 'medical_document_texts.subject_id', 'medical_document_texts.pages_read',
            'medical_document_texts.pages_total', 'medical_document_texts.complete', 'medical_document_texts.language', 'medical_document_texts.body',
        ]);

        if ($row === null) {
            return null;
        }

        return new DocumentText(
            (string) $row->document_id,
            (string) $row->subject_id,
            (int) $row->pages_read,
            (int) $row->pages_total,
            (bool) $row->complete,
            $row->language === null ? null : (string) $row->language,
            (string) $row->body,
        );
    }

    /**
     * Ids of the subject's visible documents that have recognised text.
     *
     * @return list<string>
     */
    public function documentIdsWithText(Viewer $viewer, MedicalSubject $subject): array
    {
        return Visibility::apply(
            DB::table('medical_documents')
                ->join('medical_document_texts', 'medical_document_texts.document_id', '=', 'medical_documents.id')
                ->where('medical_documents.subject_id', $subject->getKey()),
            $viewer,
            'medical_documents.subject_id',
        )->pluck('medical_documents.id')->map(fn ($id) => (string) $id)->values()->all();
    }

    /** Whether the viewer can see any document that has recognised text (turns the search box on before the AI is enabled). */
    public function anyVisible(Viewer $viewer): bool
    {
        return Visibility::apply(
            DB::table('medical_documents')
                ->join('medical_document_texts', 'medical_document_texts.document_id', '=', 'medical_documents.id'),
            $viewer,
            'medical_documents.subject_id',
        )->exists();
    }
}
