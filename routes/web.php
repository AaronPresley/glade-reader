<?php

use App\Domain\Entry\Controllers\EntryController;
use App\Domain\Entry\Controllers\SourceReferenceArticleEntryController;
use App\Domain\SourceReference\Controllers\AddSourceReferenceController;
use App\Domain\SourceReference\Controllers\SourceReferenceController;
use App\Domain\SourceReference\Controllers\SourceReferenceShareTokenController;
use App\Domain\User\Controllers\LoginController;
use App\Domain\User\Controllers\RegisterController;
use Illuminate\Support\Facades\Route;

Route::match(['get', 'post'], '/add/{token}', AddSourceReferenceController::class)
    ->name('source-references.add');

Route::get('/login', [LoginController::class, 'create'])->name('login');
Route::post('/login', [LoginController::class, 'store']);

Route::get('/register', [RegisterController::class, 'create']);
Route::post('/register', [RegisterController::class, 'store']);

Route::middleware('auth')->group(function (): void {
    Route::redirect('/', '/entries')->name('home');
    Route::get('/entries', [EntryController::class, 'index'])->name('entries.index');
    Route::get('/entries/{entry}', [EntryController::class, 'show'])->whereNumber('entry')->name('entries.show');
    Route::get('/entries/{entry}/details', [EntryController::class, 'details'])->whereNumber('entry')->name('entries.details');
    Route::post('/entries/{entry}/archive', [EntryController::class, 'archive'])->whereNumber('entry')->name('entries.archive');
    Route::post('/entries/{entry}/unarchive', [EntryController::class, 'unarchive'])->whereNumber('entry')->name('entries.unarchive');
    Route::delete('/entries/{entry}', [EntryController::class, 'destroy'])->whereNumber('entry')->name('entries.destroy');
    Route::get('/source-references', [SourceReferenceController::class, 'index'])->name('source-references.index');
    Route::get('/source-references/create', [SourceReferenceController::class, 'create'])->name('source-references.create');
    Route::post('/source-references', [SourceReferenceController::class, 'store'])->name('source-references.store');
    Route::post('/source-references/share-token/regenerate', SourceReferenceShareTokenController::class)
        ->name('source-references.share-token.regenerate');
    Route::post('/source-references/{sourceReference}/entries/article', [SourceReferenceArticleEntryController::class, 'store'])
        ->name('source-references.entries.article.store');
    Route::get('/source-references/{sourceReference}', [SourceReferenceController::class, 'show'])->name('source-references.show');
    Route::delete('/source-references/{sourceReference}', [SourceReferenceController::class, 'destroy'])->name('source-references.destroy');
    Route::post('/logout', [LoginController::class, 'destroy']);
});
