<?php

namespace App\Http\Controllers\Health;

use App\Access\AccessLevel;
use App\Http\Controllers\Controller;
use App\Http\Requests\Health\MedicationRequest;
use App\Medical\Queries\MedicationQuery;
use App\Medical\Queries\SubjectQuery;
use App\Medical\Viewer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MedicationController extends Controller
{
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

    public function destroy(Request $request, string $person, string $medication, SubjectQuery $subjects, MedicationQuery $medications): RedirectResponse
    {
        $viewer = Viewer::for($request->user());
        $subject = $subjects->findVisible($viewer, $person, AccessLevel::Edit);
        abort_if($subject === null, 403);
        abort_unless($medications->delete($viewer, $subject, $medication), 403);

        return $this->back($subject->getKey())->with('toast', ['key' => 'health.medication.deleted']);
    }

    private function back(string $personId): RedirectResponse
    {
        return redirect()->route('health.people.show', ['person' => $personId]);
    }
}
