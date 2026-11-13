<?php

use App\Http\Controllers\Todo\TaskController;
use Illuminate\Support\Facades\Route;

Route::get('/todo', [TaskController::class, 'index'])
    ->middleware('access:module,todo,view')->name('todo.index');
Route::post('/todo/tasks', [TaskController::class, 'store'])
    ->middleware(['access:module,todo,edit', 'throttle:60,1'])->name('todo.tasks.store');
