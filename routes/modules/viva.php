<?php

use App\Http\Controllers\Viva\BoardController;
use App\Http\Controllers\Viva\CandidateImportController;
use App\Http\Controllers\Viva\CandidateReportController;
use App\Http\Controllers\Viva\DistributionReportController;
use App\Http\Controllers\Viva\MyBoardController;
use App\Http\Controllers\Viva\PoolController;
use App\Http\Controllers\Viva\ReportController;
use App\Http\Controllers\Viva\SettingsController;
use Illuminate\Support\Facades\Route;

Route::prefix('modules/viva')->name('viva.')->middleware('can:access-viva')->group(function () {
    Route::get('/', fn () => redirect()->route(auth()->user()->can('view-own-viva-board') ? 'viva.my-board.index' : (auth()->user()->can('manage-viva-pools') ? 'viva.pools.index' : 'viva.imports.index')))->name('home');
    Route::get('/pools/{pool}/removal', [PoolController::class, 'removal'])->middleware('can:manage-viva-pools')->name('pools.removal');
    Route::resource('pools', PoolController::class)->except('show')->middleware('can:manage-viva-pools');
    Route::get('/boards', [BoardController::class, 'index'])->middleware('can:view-viva-distributions')->name('boards.index');
    Route::post('/boards', [BoardController::class, 'store'])->middleware('can:distribute-viva')->name('boards.store');
    Route::delete('/assignments/{assignment}', [BoardController::class, 'destroy'])->middleware('can:distribute-viva')->name('assignments.destroy');
    Route::get('/report', ReportController::class)->middleware('can:download-viva-summary')->name('report');
    Route::get('/distribution-reports', DistributionReportController::class)->middleware('can:download-viva-distributions')->name('distribution-reports');
    Route::get('/my-board', [MyBoardController::class, 'index'])->middleware('can:view-own-viva-board')->name('my-board.index');
    Route::get('/my-board/download', [MyBoardController::class, 'download'])->middleware('can:view-own-viva-board')->name('my-board.download');
    Route::middleware('can:manage-system')->group(function () {
        Route::get('/candidates', [CandidateImportController::class, 'index'])->name('imports.index');
        Route::get('/candidates/removal', [CandidateImportController::class, 'removal'])->name('imports.removal');
        Route::get('/candidates/report', CandidateReportController::class)->middleware('can:download-viva-candidates')->name('imports.report');
        Route::delete('/candidates/selection', [CandidateImportController::class, 'destroyScope'])->name('imports.destroy-scope');
        Route::delete('/candidates/all', [CandidateImportController::class, 'destroyAll'])->name('imports.destroy-all');
        Route::get('/candidates/sample', [CandidateImportController::class, 'sample'])->name('imports.sample');
        Route::post('/candidates/stage', [CandidateImportController::class, 'stage'])->middleware('throttle:10,1')->name('imports.stage');
        Route::get('/candidates/previews/{batch}', [CandidateImportController::class, 'preview'])->name('imports.preview');
        Route::post('/candidates/previews/{batch}/confirm', [CandidateImportController::class, 'confirm'])->name('imports.confirm');
        Route::delete('/candidates/previews/{batch}', [CandidateImportController::class, 'cancel'])->name('imports.cancel');
        Route::delete('/candidates/boards/{board}', [CandidateImportController::class, 'destroy'])->name('imports.destroy');
        Route::get('/settings', [SettingsController::class, 'edit'])->name('settings.edit');
        Route::put('/settings/downloads', [SettingsController::class, 'downloads'])->name('settings.downloads');
        Route::put('/settings/timing', [SettingsController::class, 'timing'])->name('settings.timing');
        Route::put('/settings/distribution-downloads', [SettingsController::class, 'distributionDownloads'])->name('settings.distribution-downloads');
        Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');
    });
});
