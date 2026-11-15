<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\HealthPeopleLinkRequest;
use App\Http\Requests\Settings\HealthPeopleMembersRequest;
use App\Medical\Queries\AdminSubjectQuery;
use Illuminate\Http\RedirectResponse;

/** Admin writes for who can open which person. Records are never read here. */
class HealthPeopleController extends Controller
{
    public function __construct(private readonly AdminSubjectQuery $people) {}

    public function members(HealthPeopleMembersRequest $request, string $person): RedirectResponse
    {
        $subject = $this->people->find($person);
        abort_if($subject === null, 404);

        $this->people->syncMembers($subject, $request->levels(), $request->user());

        return back(303)->with('toast', ['key' => 'health.access.saved']);
    }

    public function link(HealthPeopleLinkRequest $request, string $person): RedirectResponse
    {
        $subject = $this->people->find($person);
        abort_if($subject === null, 404);

        $this->people->link($subject, $request->userId(), (bool) $request->validated('self_access_enabled'), $request->user());

        return back(303)->with('toast', ['key' => 'health.access.saved']);
    }
}
