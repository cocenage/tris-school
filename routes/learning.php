<?php

use App\Http\Controllers\LearningController;
use App\Http\Middleware\SafeguardRolePreviewData;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'approved'])->prefix('home/learning')->name('learning.')->group(function (): void {
    Route::middleware(SafeguardRolePreviewData::class)->group(function (): void {
        Route::get('/', [LearningController::class, 'index'])->name('index');
        Route::get('/{course}', [LearningController::class, 'show'])->name('show');
        Route::get('/{course}/lessons/{lesson}', [LearningController::class, 'lesson'])->name('lesson');
    });
    Route::post('/{course}/lessons/{lesson}/complete', [LearningController::class, 'complete'])->name('complete');
});
