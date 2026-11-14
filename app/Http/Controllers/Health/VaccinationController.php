<?php

namespace App\Http\Controllers\Health;

use App\Access\AccessLevel;
use App\Http\Controllers\Controller;
use App\Http\Requests\Health\MarkVaccinationGivenRequest;
use App\Http\Requests\Health\VaccinationRequest;
use App\Medical\Queries\SubjectQuery;
use App\Medical\Queries\VaccinationQuery;
use App\Medical\Viewer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class VaccinationController extends Controller
{
    public function store(VaccinationRequest $request, VaccinationQuery $vaccinations): RedirectResponse
    {
        $vaccinations->create(Viewer::for($request->user()), $request->subject(), $request->validated());

        return $this->back($request->subject()->getKey())->with('toast', ['key' => 'health.vaccination.saved']);
    }

    public function update(VaccinationRequest $request, string $person, string $vaccination, VaccinationQuery $vaccinations): RedirectResponse
    {
        $subject = $request->subject();
        abort_unless($vaccinations->update(Viewer::for($request->user()), $subject, $vaccination, $request->validated()), 403);

        return $this->back($subject->getKey())->with('toast', ['key' => 'health.vaccination.saved']);
    }

    public function destroy(Request $request, string $person, string $vaccination, SubjectQuery $subjects, VaccinationQuery $vaccinations): RedirectResponse
    {
        $viewer = Viewer::for($request->user());
        $subject = $subjects->findVisible($viewer, $person, AccessLevel::Edit);
        abort_if($subject === null, 403);
        abort_unless($vaccinations->delete($viewer, $subject, $vaccination), 403);

        return $this->back($subject->getKey())->with('toast', ['key' => 'health.vaccination.deleted']);
    }

    public function given(MarkVaccinationGivenRequest $request, string $person, string $vaccination, VaccinationQuery $vaccinations): RedirectResponse
    {
        $subject = $request->subject();
        $data = $request->validated();
        abort_unless($vaccinations->markGiven(Viewer::for($request->user()), $subject, $vaccination, $data['given_on'], $data['next_due_on'] ?? null), 403);

        return $this->back($subject->getKey())->with('toast', ['key' => 'health.vaccination.given', 'params' => ['date' => $data['given_on']]]);
    }

    private function back(string $personId): RedirectResponse
    {
        return redirect()->route('health.people.show', ['person' => $personId]);
    }
}
