<?php

use App\Domain\SourceReference\Controllers\SourceReferenceController;
use App\Domain\User\Controllers\LoginController;
use App\Domain\User\Controllers\RegisterController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/login', [LoginController::class, 'create'])->name('login');
Route::post('/login', [LoginController::class, 'store']);

Route::get('/register', [RegisterController::class, 'create']);
Route::post('/register', [RegisterController::class, 'store']);

Route::middleware('auth')->group(function (): void {
    Route::get('/', fn () => Inertia::render('dashboard'));
    Route::get('/source-references', [SourceReferenceController::class, 'index'])->name('source-references.index');
    Route::get('/source-references/create', [SourceReferenceController::class, 'create'])->name('source-references.create');
    Route::post('/source-references', [SourceReferenceController::class, 'store'])->name('source-references.store');
    Route::get('/source-references/{sourceReference}', [SourceReferenceController::class, 'show'])->name('source-references.show');
    Route::delete('/source-references/{sourceReference}', [SourceReferenceController::class, 'destroy'])->name('source-references.destroy');
    Route::post('/logout', [LoginController::class, 'destroy']);
});
