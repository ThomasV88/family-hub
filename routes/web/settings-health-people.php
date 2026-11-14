<?php

use App\Http\Controllers\Settings\HealthPeopleController;
use Illuminate\Support\Facades\Route;

// Membership, linking and the own-record switch decide who can open a person's medical record: admin plus the
// recent-confirmation window (06-REVIEW CR-01). No GET here: the names arrive as a prop on settings.members.
Route::prefix('/settings/health-people')->middleware(['access:admin', 'access:recent'])->group(function () {
    Route::put('/{person}/members', [HealthPeopleController::class, 'members'])->middleware('throttle:30,1,settings.health_people.members.update')->name('settings.health_people.members.update');
    Route::put('/{person}/link', [HealthPeopleController::class, 'link'])->middleware('throttle:30,1,settings.health_people.link.update')->name('settings.health_people.link.update');
});
