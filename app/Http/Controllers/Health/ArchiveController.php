<?php

namespace App\Http\Controllers\Health;

use App\Access\AccessLevel;
use App\Http\Controllers\Controller;
use App\Medical\Queries\ArchiveQuery;
use App\Medical\Queries\SubjectQuery;
use App\Medical\Viewer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Restore from the Archive section. Edit scope only; a hidden, unknown, malformed or not archived id, an unknown record
 * type and a view-only member all end in the same 403 as every other medical refusal.
 */
class ArchiveController extends Controller
{
    public function restorePerson(Request $request, string $person, SubjectQuery $subjects): RedirectResponse
    {
        $viewer = Viewer::for($request->user());
        abort_unless($subjects->restore($viewer, $person), 403);

        return redirect()->route('health.people.show', ['person' => $person])
            ->with('toast', ['key' => 'health.archive.restored']);
    }

    public function restoreRecord(Request $request, string $person, string $type, string $record, SubjectQuery $subjects, ArchiveQuery $archive): RedirectResponse
    {
        $viewer = Viewer::for($request->user());
        $subject = $subjects->findVisible($viewer, $person, AccessLevel::Edit);
        abort_if($subject === null, 403);
        abort_unless($archive->restore($viewer, $subject, $type, $record), 403);

        return redirect()->route('health.people.show', ['person' => $subject->getKey()])
            ->with('toast', ['key' => 'health.archive.restored']);
    }
}
