<?php

use App\Http\Controllers\Settings\AiAgentsController;
use Illuminate\Support\Facades\Route;

Route::get('/settings/ai-agents', [AiAgentsController::class, 'index'])->middleware('access:admin')->name('settings.ai_agents');
Route::patch('/settings/ai-agents/{agent}', [AiAgentsController::class, 'update'])
    ->middleware(['access:admin', 'access:recent', 'throttle:30,1,settings.ai_agents.update'])->name('settings.ai_agents.update');
Route::post('/settings/ai-agents/{agent}/move', [AiAgentsController::class, 'move'])
    ->middleware(['access:admin', 'access:recent', 'throttle:30,1,settings.ai_agents.move'])->name('settings.ai_agents.move');
