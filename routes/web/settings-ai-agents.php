<?php

use App\Http\Controllers\Settings\AiAgentsController;
use Illuminate\Support\Facades\Route;

Route::get('/settings/ai-agents', [AiAgentsController::class, 'index'])->middleware('access:admin')->name('settings.ai_agents');
Route::patch('/settings/ai-agents/{agent}', [AiAgentsController::class, 'update'])
    ->middleware(['access:admin', 'access:recent', 'throttle:30,1,settings.ai_agents.update'])->name('settings.ai_agents.update');
Route::post('/settings/ai-agents/{agent}/move', [AiAgentsController::class, 'move'])
    ->middleware(['access:admin', 'access:recent', 'throttle:30,1,settings.ai_agents.move'])->name('settings.ai_agents.move');

Route::get('/settings/ai-agents/bulk', [AiAgentsController::class, 'bulk'])
    ->middleware(['access:admin', 'throttle:60,1,settings.ai_agents.bulk'])->name('settings.ai_agents.bulk');
Route::post('/settings/ai-agents/bulk/dry-run', [AiAgentsController::class, 'bulkDryRun'])
    ->middleware(['access:admin', 'access:recent', 'throttle:12,1,settings.ai_agents.bulk.dry_run'])->name('settings.ai_agents.bulk.dry_run');
Route::post('/settings/ai-agents/bulk', [AiAgentsController::class, 'bulkStart'])
    ->middleware(['access:admin', 'access:recent', 'throttle:6,1,settings.ai_agents.bulk.start'])->name('settings.ai_agents.bulk.start');
Route::post('/settings/ai-agents/bulk/{run}/pause', [AiAgentsController::class, 'bulkPause'])
    ->middleware(['access:admin', 'access:recent', 'throttle:12,1,settings.ai_agents.bulk.pause'])->name('settings.ai_agents.bulk.pause');
Route::post('/settings/ai-agents/bulk/{run}/resume', [AiAgentsController::class, 'bulkResume'])
    ->middleware(['access:admin', 'access:recent', 'throttle:12,1,settings.ai_agents.bulk.resume'])->name('settings.ai_agents.bulk.resume');
Route::post('/settings/ai-agents/bulk/{run}/cancel', [AiAgentsController::class, 'bulkCancel'])
    ->middleware(['access:admin', 'access:recent', 'throttle:12,1,settings.ai_agents.bulk.cancel'])->name('settings.ai_agents.bulk.cancel');
