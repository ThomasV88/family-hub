<?php

namespace App\Http\Controllers\Health;

use App\Access\AccessLevel;
use App\Http\Controllers\Controller;
use App\Http\Requests\Health\AllergyRequest;
use App\Medical\Queries\AllergyQuery;
use App\Medical\Queries\SubjectQuery;
use App\Medical\Viewer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AllergyController extends Controller
{
    public function store(AllergyRequest $request, AllergyQuery $allergies): RedirectResponse
    {
        $allergies->create(Viewer::for($request->user()), $request->subject(), $request->validated());

        return $this->back($request->subject()->getKey())->with('toast', ['key' => 'health.allergy.saved']);
    }

    public function update(AllergyRequest $request, string $person, string $allergy, AllergyQuery $allergies): RedirectResponse
    {
        $subject = $request->subject();
        abort_unless($allergies->update(Viewer::for($request->user()), $subject, $allergy, $request->validated()), 403);

        return $this->back($subject->getKey())->with('toast', ['key' => 'health.allergy.saved']);
    }

    public function destroy(Request $request, string $person, string $allergy, SubjectQuery $subjects, AllergyQuery $allergies): RedirectResponse
    {
        $viewer = Viewer::for($request->user());
        $subject = $subjects->findVisible($viewer, $person, AccessLevel::Edit);
        abort_if($subject === null, 403);
        abort_unless($allergies->delete($viewer, $subject, $allergy), 403);

        return $this->back($subject->getKey())->with('toast', ['key' => 'health.allergy.deleted']);
    }

    private function back(string $personId): RedirectResponse
    {
        return redirect()->route('health.people.show', ['person' => $personId]);
    }
}
