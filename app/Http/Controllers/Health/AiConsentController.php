<?php

namespace App\Http\Controllers\Health;

use App\Http\Controllers\Controller;
use App\Http\Requests\Health\AiConsentRequest;
use App\Medical\Queries\SubjectQuery;
use App\Medical\Viewer;
use Illuminate\Http\RedirectResponse;

class AiConsentController extends Controller
{
    public function update(AiConsentRequest $request, SubjectQuery $subjects): RedirectResponse
    {
        $subject = $request->subject();
        $value = $request->boolean('value');
        abort_unless($subjects->setConsent(Viewer::for($request->user()), $subject, $value), 403);

        return redirect()->route('health.people.show', ['person' => $subject->getKey()])
            ->with('toast', ['key' => $value ? 'health.ai.on_toast' : 'health.ai.off_toast']);
    }
}
