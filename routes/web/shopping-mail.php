<?php

use App\Http\Controllers\Shopping\MailController;
use Illuminate\Support\Facades\Route;

Route::post('/shopping/lists/{list}/mail', MailController::class)
    ->whereNumber('list')->middleware(['access:module,shopping,edit', 'throttle:shopping-mail'])->name('shopping.lists.mail');
