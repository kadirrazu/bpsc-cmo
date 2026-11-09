<?php

use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DesignationController;
use App\Http\Controllers\StaffProfileController;
use App\Http\Controllers\UnitController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route(auth()->check() ? 'dashboard' : 'login'))->name('home');
Route::middleware('auth')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/profile', [StaffProfileController::class, 'edit'])->name('staff-profile.edit');
    Route::put('/profile', [StaffProfileController::class, 'update'])->name('staff-profile.update');
    Route::get('/profile/password', [StaffProfileController::class, 'password'])->name('staff-profile.password');
    Route::put('/profile/password', [StaffProfileController::class, 'updatePassword'])->name('staff-profile.update-password');
    Route::middleware('can:manage-system')->group(function () {
        Route::resource('units', UnitController::class)->except('show');
        Route::resource('designations', DesignationController::class)->except('show');
        Route::get('/users/{user}/delete-confirm', [UserController::class, 'confirmDelete'])->name('users.confirm-delete');
        Route::resource('users', UserController::class);
        Route::get('/roles', fn () => view('roles'))->name('roles.index');
        Route::get('/audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');
    });
    require __DIR__.'/modules/evaluation.php';
});
