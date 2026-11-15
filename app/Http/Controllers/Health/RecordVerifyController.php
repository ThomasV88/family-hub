<?php

namespace App\Http\Controllers\Health;

use App\Access\AccessLevel;
use App\Http\Controllers\Controller;
use App\Medical\Queries\AllergyQuery;
use App\Medical\Queries\MedicationQuery;
use App\Medical\Queries\SubjectQuery;
use App\Medical\Queries\VaccinationQuery;
use App\Medical\Queries\VisitQuery;
use App\Medical\Viewer;
use App\Models\MedicalSubject;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Marks an AI-extracted record as verified (the only unverified -> verified transition). No route model binding: a
 * hidden person, a hidden record, an unknown id and a malformed id all end in the same 403. A record the viewer can
 * change that needs no verification (manual or already verified) simply redirects back.
 */
class RecordVerifyController extends Controller
{
    public function visit(Request $request, string $person, string $visit, SubjectQuery $subjects, VisitQuery $visits): RedirectResponse
    {
        return $this->run($request, $person, $subjects, fn ($viewer, $subject) => $visits->verify($viewer, $subject, $visit)
            || $visits->find($viewer, $subject, $visit, AccessLevel::Edit) !== null);
    }

    public function medication(Request $request, string $person, string $medication, SubjectQuery $subjects, MedicationQuery $medications): RedirectResponse
    {
        return $this->run($request, $person, $subjects, fn ($viewer, $subject) => $medications->verify($viewer, $subject, $medication)
            || $medications->find($viewer, $subject, $medication, AccessLevel::Edit) !== null);
    }

    public function allergy(Request $request, string $person, string $allergy, SubjectQuery $subjects, AllergyQuery $allergies): RedirectResponse
    {
        return $this->run($request, $person, $subjects, fn ($viewer, $subject) => $allergies->verify($viewer, $subject, $allergy)
            || $allergies->find($viewer, $subject, $allergy, AccessLevel::Edit) !== null);
    }

    public function vaccination(Request $request, string $person, string $vaccination, SubjectQuery $subjects, VaccinationQuery $vaccinations): RedirectResponse
    {
        return $this->run($request, $person, $subjects, fn ($viewer, $subject) => $vaccinations->verify($viewer, $subject, $vaccination)
            || $vaccinations->find($viewer, $subject, $vaccination, AccessLevel::Edit) !== null);
    }

    /** @param  callable(Viewer, MedicalSubject): bool  $verify  true when the record was verified or is visible with nothing to verify */
    private function run(Request $request, string $person, SubjectQuery $subjects, callable $verify): RedirectResponse
    {
        $viewer = Viewer::for($request->user());
        $subject = $subjects->findVisible($viewer, $person, AccessLevel::Edit);
        abort_if($subject === null, 403);
        abort_unless($verify($viewer, $subject), 403);

        return redirect()->route('health.people.show', ['person' => $subject->getKey()])
            ->with('toast', ['key' => 'health.ai.provenance.verified_toast']);
    }
}
