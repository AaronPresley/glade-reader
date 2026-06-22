<?php

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
    Route::post('/logout', [LoginController::class, 'destroy']);
});
