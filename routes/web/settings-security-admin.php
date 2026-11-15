<?php

use App\Http\Controllers\Settings\MemberSecondFactorController;
use App\Http\Controllers\Settings\SecurityPolicyController;
use Illuminate\Support\Facades\Route;

Route::put('/settings/security/policy', [SecurityPolicyController::class, 'update'])
    ->middleware(['access:admin', 'access:recent', 'throttle:20,1'])->name('settings.security.policy');
Route::delete('/settings/members/{user}/second-factor', [MemberSecondFactorController::class, 'destroy'])
    ->middleware(['access:admin', 'access:recent', 'throttle:20,1'])->whereNumber('user')->name('settings.members.second_factor.destroy');
