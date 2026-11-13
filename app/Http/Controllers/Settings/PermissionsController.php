<?php

namespace App\Http\Controllers\Settings;

use App\Access\PermissionService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdatePermissionsRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

class PermissionsController extends Controller
{
    public function update(UpdatePermissionsRequest $request, int $user, PermissionService $service): RedirectResponse
    {
        // Resolved here, not by route model binding: binding runs before access:admin and would 404 non-admins.
        $member = User::query()->findOrFail($user);
        $service->save($request->user(), $member, $request->levels());

        return back()->with('toast', ['key' => 'permissions.saved']);
    }
}
