<?php

namespace App\Http\Controllers\Health;

use App\Access\AccessLevel;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Health\Concerns\FlashesRemoval;
use App\Http\Requests\Health\VisitRequest;
use App\Medical\Queries\ArchiveQuery;
use App\Medical\Queries\SubjectQuery;
use App\Medical\Queries\VisitQuery;
use App\Medical\Viewer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class VisitController extends Controller
{
    use FlashesRemoval;

    public function store(VisitRequest $request, VisitQuery $visits): RedirectResponse
    {
        $visits->create(Viewer::for($request->user()), $request->subject(), $request->validated());

        return $this->back($request->subject()->getKey())->with('toast', ['key' => 'health.visit.saved']);
    }

    public function update(VisitRequest $request, string $person, string $visit, VisitQuery $visits): RedirectResponse
    {
        $subject = $request->subject();
        abort_unless($visits->update(Viewer::for($request->user()), $subject, $visit, $request->validated()), 403);

        return $this->back($subject->getKey())->with('toast', ['key' => 'health.visit.saved']);
    }

    public function destroy(Request $request, string $person, string $visit, SubjectQuery $subjects, ArchiveQuery $archive): RedirectResponse
    {
        $viewer = Viewer::for($request->user());
        $subject = $subjects->findVisible($viewer, $person, AccessLevel::Edit);
        abort_if($subject === null, 403);
        $result = $archive->remove($viewer, $subject, 'visit', $visit);
        abort_if($result === null, 403);

        return $this->back($subject->getKey())->with('toast', $this->removalToast($result['outcome'], 'health.visit.deleted'));
    }

    private function back(string $personId): RedirectResponse
    {
        return redirect()->route('health.people.show', ['person' => $personId]);
    }
}
