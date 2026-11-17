<?php

use App\Http\Controllers\Profile\ProfileController;
use App\Http\Controllers\Profile\ProfilePhotoController;
use Illuminate\Support\Facades\Route;

// Profile photos: the controller and the FormRequest decide per target (self, admin or manager for a child).
Route::post('/people/{user}/photo', [ProfilePhotoController::class, 'store'])
    ->whereNumber('user')->middleware(['access:authenticated', 'throttle:20,1,profile.photo.store'])->name('profile.photo.store');
Route::delete('/people/{user}/photo', [ProfilePhotoController::class, 'destroy'])
    ->whereNumber('user')->middleware('access:authenticated')->name('profile.photo.destroy');
Route::get('/people/{user}/photo/{variant}', [ProfilePhotoController::class, 'show'])
    ->whereNumber('user')->whereIn('variant', ['thumb', 'full'])
    ->middleware('access:authenticated')->name('profile.photo.show');

// Own profile page and display name. The request authorizes per target through ProfileAccess.
Route::get('/profile', [ProfileController::class, 'show'])
    ->middleware('access:authenticated')->name('profile.show');
// A child the viewer manages (or any child for an admin); everything else is a uniform 404 in the controller.
Route::get('/profile/people/{user}', [ProfileController::class, 'person'])
    ->whereNumber('user')->middleware('access:authenticated')->name('profile.people.show');
Route::put('/people/{user}/name', [ProfileController::class, 'updateName'])
    ->whereNumber('user')->middleware(['access:authenticated', 'throttle:30,1,profile.name.update'])->name('profile.name.update');
