<?php

use App\Domain\Entry\Controllers\ApiEntryController;
use App\Domain\SourceReference\Controllers\ApiSourceReferenceController;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;

Route::middleware(['auth:sanctum', CheckAbilities::class.':read', 'throttle:60,1'])->group(function (): void {
    Route::get('/entries', [ApiEntryController::class, 'index']);
    Route::get('/entries/{entry}', [ApiEntryController::class, 'show'])->whereNumber('entry');
    Route::get('/source-references', [ApiSourceReferenceController::class, 'index']);
    Route::get('/source-references/{sourceReference}', [ApiSourceReferenceController::class, 'show'])->whereNumber('sourceReference');
});

Route::post('/source-references', [ApiSourceReferenceController::class, 'store'])
    ->middleware(['auth:sanctum', CheckAbilities::class.':sources:create', 'throttle:60,1']);

Route::post('/entries/{entry}/regenerate', [ApiEntryController::class, 'regenerate'])
    ->whereNumber('entry')
    ->middleware(['auth:sanctum', CheckAbilities::class.':entries:generate', 'throttle:60,1']);
