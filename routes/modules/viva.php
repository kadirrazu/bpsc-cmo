<?php

use App\Http\Controllers\Viva\BoardController;
use App\Http\Controllers\Viva\CandidateImportController;
use App\Http\Controllers\Viva\PoolController;
use App\Http\Controllers\Viva\ReportController;
use App\Http\Controllers\Viva\SettingsController;
use Illuminate\Support\Facades\Route;

Route::prefix('modules/viva')->name('viva.')->middleware('can:access-viva')->group(function () {
    Route::get('/', fn () => redirect()->route(auth()->user()->can('manage-viva-pools') ? 'viva.pools.index' : 'viva.imports.index'))->name('home');
    Route::resource('pools', PoolController::class)->except('show')->middleware('can:manage-viva-pools');
    Route::get('/boards', [BoardController::class, 'index'])->name('boards.index');
    Route::post('/boards', [BoardController::class, 'store'])->middleware('can:distribute-viva')->name('boards.store');
    Route::delete('/assignments/{assignment}', [BoardController::class, 'destroy'])->middleware('can:distribute-viva')->name('assignments.destroy');
    Route::get('/report', ReportController::class)->name('report');
    Route::middleware('can:manage-system')->group(function () {
        Route::get('/candidates', [CandidateImportController::class, 'index'])->name('imports.index');
        Route::get('/candidates/sample', [CandidateImportController::class, 'sample'])->name('imports.sample');
        Route::post('/candidates/stage', [CandidateImportController::class, 'stage'])->middleware('throttle:10,1')->name('imports.stage');
        Route::get('/candidates/previews/{batch}', [CandidateImportController::class, 'preview'])->name('imports.preview');
        Route::post('/candidates/previews/{batch}/confirm', [CandidateImportController::class, 'confirm'])->name('imports.confirm');
        Route::delete('/candidates/previews/{batch}', [CandidateImportController::class, 'cancel'])->name('imports.cancel');
        Route::delete('/candidates/boards/{board}', [CandidateImportController::class, 'destroy'])->name('imports.destroy');
        Route::get('/settings', [SettingsController::class, 'edit'])->name('settings.edit');
        Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');
    });
});
