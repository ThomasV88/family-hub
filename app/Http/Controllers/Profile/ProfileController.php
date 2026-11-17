<?php

namespace App\Http\Controllers\Profile;

use App\Audit\AuditEvent;
use App\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\UpdateProfileNameRequest;
use App\Models\User;
use App\Models\WishlistManager;
use App\Profile\ProfileAccess;
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
            // Children whose wish list this member manages: the entry point to edit them without admin rights.
            'managedPeople' => User::query()
                ->whereIn('id', WishlistManager::query()->where('manager_id', $user->id)->select('owner_id'))
                ->whereNull('email')->where('sign_in_disabled', true)
                ->orderBy('name')->orderBy('id')
                ->get(['id', 'name', 'photo_path', 'photo_version'])
                ->map(fn (User $child) => [
                    'id' => $child->id,
                    'name' => $child->name,
                    'photoVersion' => $child->photo_path !== null ? $child->photo_version : null,
                ])->values(),
        ]);
    }

    /** Another person's page: only a child the viewer may edit; every other id answers the same 404. */
    public function person(Request $request, int $user, ProfileAccess $access): Response
    {
        $viewer = $request->user();
        $target = User::query()->find($user);

        abort_unless(
            $target !== null && $target->id !== $viewer->id
                && ! $target->canSignIn() && $target->email === null
                && $access->canEdit($viewer, $target),
            404,
        );

        return Inertia::render('Profile/Edit', [
            'sections' => SettingsSections::for($viewer),
            'subject' => $this->subject($viewer, $target),
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
