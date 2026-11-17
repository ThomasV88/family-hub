<?php

namespace App\Http\Controllers\Profile;

use App\Audit\AuditEvent;
use App\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\UpdateProfileNameRequest;
use App\Models\User;
use App\Support\SettingsSections;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    public function show(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Profile/Edit', [
            'sections' => SettingsSections::for($user),
            'subject' => $this->subject($user, $user),
            'nameMax' => (int) config('profile.name_max'),
        ]);
    }

    public function updateName(UpdateProfileNameRequest $request, int $user, AuditLogger $audit): RedirectResponse
    {
        $target = User::query()->findOrFail($user);
        $name = (string) $request->validated('name');

        if ($name !== $target->name) {
            $target->forceFill(['name' => $name])->save();
            if ($request->user()->id !== $target->id) {
                $audit->record(AuditEvent::ProfileChangedByOther, $request->user(), ['field' => 'name'], $target);
            }
        }

        return back(303)->with('toast', ['key' => 'profile.toast.saved']);
    }

    /** The only fields the page ever receives: never a password, token, google id or photo path. */
    private function subject(User $viewer, User $target): array
    {
        return [
            'id' => $target->id,
            'name' => $target->name,
            'email' => $target->canSignIn() || $target->id === $viewer->id ? $target->email : null,
            'photoVersion' => $target->photo_path !== null ? $target->photo_version : null,
            'isSelf' => $viewer->id === $target->id,
            'isChild' => ! $target->canSignIn() && $target->email === null,
        ];
    }
}
