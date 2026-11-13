<?php

namespace App\Http\Controllers\Wishlist\Owner;

use App\Http\Controllers\Controller;
use App\Http\Requests\Wishlist\MemberDatesRequest;
use App\Models\User;
use App\Models\WishOccasion;
use App\Wishlist\Occasions\NameDayCalendar;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class MemberDatesController extends Controller
{
    /** Access was decided in MemberDatesRequest::authorize (self, designated manager or admin, with wishlist edit). */
    public function update(MemberDatesRequest $request, int $member): RedirectResponse
    {
        $data = $request->validated();

        $actorId = $request->user()->id;

        DB::transaction(function () use ($data, $member, $actorId) {
            if (array_key_exists('birth_date', $data)) {
                User::query()->whereKey($member)->update(['birth_date' => $data['birth_date']]);
            }
            if (array_key_exists('nameday', $data)) {
                $existing = WishOccasion::query()->where('owner_id', $member)->where('kind', WishOccasion::NAMEDAY);
                $nameday = $data['nameday'];
                if ($nameday === null || ! in_array($nameday['calendar'], NameDayCalendar::calendars(), true)) {
                    $existing->delete();

                    return;
                }
                $row = $existing->first() ?? new WishOccasion;
                $row->fill([
                    'calendar' => $nameday['calendar'],
                    'name' => $nameday['name'],
                    'month' => (int) $nameday['month'],
                    'day' => (int) $nameday['day'],
                    'year' => null,
                    'title' => null,
                ]);
                $row->forceFill(['owner_id' => $member, 'kind' => WishOccasion::NAMEDAY, 'created_by_id' => $actorId])->save();
            }
        });

        return back(303)->with('toast', ['key' => 'occasions.toast.dates_saved']);
    }
}
