<?php

namespace App\Http\Controllers\Settings;

use App\Access\AccessGate;
use App\Access\Preset;
use App\Http\Controllers\Controller;
use App\Models\Invitation;
use App\Models\User;
use App\Support\SettingsSections;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MembersController extends Controller
{
    public function index(Request $request, AccessGate $gate): Response
    {
        $viewer = $request->user();

        // Explicit field mapping: never expose password, remember_token, google_id or other columns.
        $members = User::query()->orderBy('name')->orderBy('id')->get(['id', 'name', 'email', 'is_admin'])
            ->map(fn (User $member) => [
                'id' => $member->id,
                'name' => $member->name,
                'email' => $member->email,
                'isAdmin' => (bool) $member->is_admin,
                'isSelf' => $member->id === $viewer->id,
                'access' => $gate->levels($member),
            ])->values();

        $presets = [];
        foreach (Preset::cases() as $preset) {
            $presets[$preset->value] = array_map(fn ($level) => $level->label(), $preset->grants());
        }

        $invitations = Invitation::query()->whereNull('accepted_at')->orderByDesc('id')->get()
            ->map(fn (Invitation $inv) => [
                'id' => $inv->id,
                'email' => $inv->email,
                'preset' => $inv->preset->value,
                'locale' => $inv->locale,
                'state' => $inv->state(),
                'expiresAt' => $inv->expires_at->toIso8601String(),
                'mailFailed' => $inv->mail_failed_at !== null,
            ])->values();

        $open = (int) $request->query('member');

        return Inertia::render('Settings/Members', [
            'sections' => SettingsSections::for($viewer),
            'members' => $members,
            'presets' => $presets,
            'invitations' => $invitations,
            'openMember' => $open > 0 && $members->contains('id', $open) ? $open : null,
        ]);
    }
}
