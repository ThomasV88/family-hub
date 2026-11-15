<?php

use App\Http\Controllers\Todo\TaskController;
use App\Http\Controllers\Todo\TaskSkipController;
use App\Http\Controllers\Todo\TaskStatusController;
use Illuminate\Support\Facades\Route;

Route::get('/todo', [TaskController::class, 'index'])
    ->middleware('access:module,todo,view')->name('todo.index');
Route::post('/todo/tasks', [TaskController::class, 'store'])
    ->middleware(['access:module,todo,edit', 'throttle:60,1'])->name('todo.tasks.store');
Route::patch('/todo/tasks/{task}', [TaskController::class, 'update'])
    ->whereNumber('task')->middleware(['access:module,todo,edit', 'throttle:120,1'])->name('todo.tasks.update');
Route::patch('/todo/tasks/{task}/status', TaskStatusController::class)
    ->whereNumber('task')->middleware(['access:module,todo,edit', 'throttle:120,1'])->name('todo.tasks.status');
Route::post('/todo/tasks/{task}/skip', TaskSkipController::class)
    ->whereNumber('task')->middleware(['access:module,todo,edit', 'throttle:120,1'])->name('todo.tasks.skip');
Route::delete('/todo/tasks/{task}', [TaskController::class, 'destroy'])
    ->whereNumber('task')->middleware(['access:module,todo,edit', 'throttle:60,1'])->name('todo.tasks.destroy');
