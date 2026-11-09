<?php

use App\Http\Controllers\EvaluationController;
use App\Http\Controllers\EvaluationModuleController;
use App\Http\Controllers\EvaluationReportController;
use App\Http\Controllers\ExpertController;
use App\Http\Controllers\QuestionController;
use App\Http\Controllers\ReportDownloadController;
use App\Http\Controllers\SurveyController;
use Illuminate\Support\Facades\Route;

Route::prefix('modules/evaluation')->middleware('can:access-evaluation')->group(function () {
    Route::get('/', [EvaluationModuleController::class, 'index'])->name('evaluation.home');
    Route::get('/reports', [EvaluationReportController::class, 'center'])->middleware('can:view-expert-reports')->name('evaluation.report-center');
    Route::get('/reports/expert-options', [EvaluationReportController::class, 'expertOptions'])->middleware(['can:view-expert-reports', 'throttle:120,1'])->name('evaluation.report-expert-options');
    Route::match(['get', 'post'], '/reports/download', ReportDownloadController::class)->middleware('can:view-expert-reports')->name('evaluation.report-download');
    Route::get('/reports/experts', [EvaluationReportController::class, 'index'])->middleware('can:view-expert-reports')->name('evaluation.reports');
    Route::resource('experts', ExpertController::class)->except(['index', 'show', 'destroy'])->middleware('can:manage-experts');
    Route::resource('experts', ExpertController::class)->only(['index', 'show'])->middleware('can:view-experts');
    Route::delete('/experts/{expert}', [ExpertController::class, 'destroy'])->middleware('can:delete-experts')->name('experts.destroy');
    Route::get('/surveys', [SurveyController::class, 'index'])->name('surveys.index');
    Route::middleware('can:manage-surveys')->group(function () {
        Route::resource('surveys', SurveyController::class)->except(['index', 'show']);
        Route::resource('questions', QuestionController::class)->except('show');
    });
    Route::get('/surveys/{survey}', [SurveyController::class, 'show'])->name('surveys.show');
    Route::get('/responses', [EvaluationController::class, 'index'])->name('evaluations.index');
    Route::middleware('can:evaluate-experts')->group(function () {
        Route::get('/responses/expert-options', [EvaluationController::class, 'expertOptions'])->middleware('throttle:120,1')->name('evaluations.expert-options');
        Route::get('/responses/create', [EvaluationController::class, 'create'])->name('evaluations.create');
        Route::post('/responses', [EvaluationController::class, 'store'])->middleware('throttle:30,1')->name('evaluations.store');
    });
    Route::get('/responses/{evaluation}', [EvaluationController::class, 'show'])->name('evaluations.show');
});
