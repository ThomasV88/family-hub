<?php

namespace App\Http\Controllers\Health;

use App\Access\AccessLevel;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Health\Concerns\FlashesRemoval;
use App\Http\Requests\Health\AllergyRequest;
use App\Medical\Queries\AllergyQuery;
use App\Medical\Queries\ArchiveQuery;
use App\Medical\Queries\SubjectQuery;
use App\Medical\Viewer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AllergyController extends Controller
{
    use FlashesRemoval;

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

    public function destroy(Request $request, string $person, string $allergy, SubjectQuery $subjects, ArchiveQuery $archive): RedirectResponse
    {
        $viewer = Viewer::for($request->user());
        $subject = $subjects->findVisible($viewer, $person, AccessLevel::Edit);
        abort_if($subject === null, 403);
        $result = $archive->remove($viewer, $subject, 'allergy', $allergy);
        abort_if($result === null, 403);

        return $this->back($subject->getKey())->with('toast', $this->removalToast($result['outcome'], 'health.allergy.deleted'));
    }

    private function back(string $personId): RedirectResponse
    {
        return redirect()->route('health.people.show', ['person' => $personId]);
    }
}
