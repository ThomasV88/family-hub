<?php

namespace App\Http\Controllers\Health;

use App\Access\AccessLevel;
use App\Http\Controllers\Controller;
use App\Http\Requests\Health\StorePersonRequest;
use App\Http\Requests\Health\UpdatePersonRequest;
use App\Medical\PersonPage;
use App\Medical\Queries\SubjectQuery;
use App\Medical\Viewer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

class PersonController extends Controller
{
    public function index(Request $request, SubjectQuery $subjects, PersonPage $page): Response|RedirectResponse
    {
        $viewer = Viewer::for($request->user());
        $first = $subjects->visible($viewer)->first();
        if ($first !== null) {
            return redirect()->route('health.people.show', ['person' => $first->getKey()]);
        }

        return $page->render($viewer, null, $request);
    }

    public function show(Request $request, string $person, SubjectQuery $subjects, PersonPage $page): Response
    {
        $viewer = Viewer::for($request->user());
        $subject = $subjects->findVisible($viewer, $person);
        abort_if($subject === null, 403);

        return $page->render($viewer, $subject, $request);
    }

    public function store(StorePersonRequest $request, SubjectQuery $subjects): RedirectResponse
    {
        $subject = $subjects->create(Viewer::for($request->user()), $request->validated());

        return redirect()->route('health.people.show', ['person' => $subject->getKey()])
            ->with('toast', ['key' => 'health.person.saved']);
    }

    public function update(UpdatePersonRequest $request, string $person, SubjectQuery $subjects): RedirectResponse
    {
        $subject = $request->subject();
        abort_unless($subjects->update(Viewer::for($request->user()), $subject, $request->validated()), 403);

        return redirect()->route('health.people.show', ['person' => $subject->getKey()])
            ->with('toast', ['key' => 'health.person.saved']);
    }

    public function destroy(Request $request, string $person, SubjectQuery $subjects): RedirectResponse
    {
        $viewer = Viewer::for($request->user());
        $subject = $subjects->findVisible($viewer, $person, AccessLevel::Edit);
        abort_if($subject === null, 403);
        abort_unless($subjects->delete($viewer, $subject), 403);

        return redirect()->route('health.index')->with('toast', ['key' => 'health.person.deleted']);
    }
}
