<?php

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
