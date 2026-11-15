<?php

namespace App\Http\Controllers\Health;

use App\Access\AccessLevel;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Health\Concerns\FlashesRemoval;
use App\Http\Requests\Health\MedicationRequest;
use App\Medical\Queries\ArchiveQuery;
use App\Medical\Queries\MedicationQuery;
use App\Medical\Queries\SubjectQuery;
use App\Medical\Viewer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MedicationController extends Controller
{
    use FlashesRemoval;

    public function store(MedicationRequest $request, MedicationQuery $medications): RedirectResponse
    {
        $medications->create(Viewer::for($request->user()), $request->subject(), $request->validated());

        return $this->back($request->subject()->getKey())->with('toast', ['key' => 'health.medication.saved']);
    }

    public function update(MedicationRequest $request, string $person, string $medication, MedicationQuery $medications): RedirectResponse
    {
        $subject = $request->subject();
        abort_unless($medications->update(Viewer::for($request->user()), $subject, $medication, $request->validated()), 403);

        return $this->back($subject->getKey())->with('toast', ['key' => 'health.medication.saved']);
    }

    public function destroy(Request $request, string $person, string $medication, SubjectQuery $subjects, ArchiveQuery $archive): RedirectResponse
    {
        $viewer = Viewer::for($request->user());
        $subject = $subjects->findVisible($viewer, $person, AccessLevel::Edit);
        abort_if($subject === null, 403);
        $result = $archive->remove($viewer, $subject, 'medication', $medication);
        abort_if($result === null, 403);

        return $this->back($subject->getKey())->with('toast', $this->removalToast($result['outcome'], 'health.medication.deleted'));
    }

    private function back(string $personId): RedirectResponse
    {
        return redirect()->route('health.people.show', ['person' => $personId]);
    }
}
