<?php

namespace App\Medical;

use App\Medical\Queries\SystemQuery;
use App\Models\MedicalSubject;
use Throwable;

/**
 * The single AI-consent check. Phase 8 MUST call it at dispatch and again immediately before invoking the CLI.
 *
 * Fail-closed: a missing subject, an off flag or any error answers false. It reads the database on every call and never
 * trusts the in-memory attribute of a passed model, so a change takes effect immediately.
 */
final class AiConsent
{
    public static function allows(MedicalSubject|string $subject): bool
    {
        try {
            $id = $subject instanceof MedicalSubject ? (string) $subject->getKey() : $subject;

            return app(SystemQuery::class)->aiConsentFor($id);
        } catch (Throwable) {
            return false;
        }
    }

    /** Consent of the person a document belongs to; false for an unknown or malformed document id and on any error. */
    public static function allowsDocument(string $documentId): bool
    {
        try {
            $subjectId = app(SystemQuery::class)->documentSubjectId($documentId);

            return $subjectId !== null && self::allows($subjectId);
        } catch (Throwable) {
            return false;
        }
    }
}
