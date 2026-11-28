<?php

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\MonitorController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FindingController;
use App\Http\Controllers\FixController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectReviewerController;
use App\Http\Controllers\ScanController;
use App\Http\Controllers\UploadController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check() ? redirect()->route('dashboard') : redirect()->route('login');
});

// ---------- Guests only
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->name('login.attempt');

    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:10,1')->name('register.attempt');

    Route::get('/forgot-password', [PasswordController::class, 'showForgot'])->name('password.request');
    Route::post('/forgot-password', [PasswordController::class, 'sendLink'])->middleware('throttle:5,1')->name('password.email');

    // Route name "password.reset" is required by Laravel's reset-link notification.
    Route::get('/reset-password/{token}', [PasswordController::class, 'showReset'])->name('password.reset');
    Route::post('/reset-password', [PasswordController::class, 'reset'])->name('password.update');
});

// ---------- Logged-in users
Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::resource('projects', ProjectController::class);
    Route::post('/projects/{project}/reviewers', [ProjectReviewerController::class, 'store'])->name('projects.reviewers.store');
    Route::delete('/projects/{project}/reviewers/{user}', [ProjectReviewerController::class, 'destroy'])->name('projects.reviewers.destroy');

    // Uploads (Day 2)
    Route::post('/projects/{project}/uploads', [UploadController::class, 'store'])->middleware('throttle:30,1')->name('projects.uploads.store');
    Route::get('/projects/{project}/uploads/{upload}', [UploadController::class, 'show'])->name('projects.uploads.show');
    Route::get('/projects/{project}/uploads/{upload}/download', [UploadController::class, 'download'])->name('projects.uploads.download');
    Route::delete('/projects/{project}/uploads/{upload}', [UploadController::class, 'destroy'])->name('projects.uploads.destroy');

    // Scans, findings and reports (Day 3)
    Route::post('/projects/{project}/uploads/{upload}/scans', [ScanController::class, 'store'])->middleware('throttle:20,1')->name('projects.uploads.scans.store');
    Route::get('/scans/{scan}', [ScanController::class, 'show'])->name('scans.show');
    Route::get('/scans/{scan}/status', [ScanController::class, 'status'])->name('scans.status');
    Route::get('/scans/{scan}/report', [ScanController::class, 'report'])->name('scans.report');
    Route::get('/scans/{scan}/compare/{otherScan}', [ScanController::class, 'compare'])->name('scans.compare');
    Route::get('/findings/{finding}', [FindingController::class, 'show'])->name('findings.show');
    Route::patch('/findings/{finding}/status', [FindingController::class, 'updateStatus'])->name('findings.status');

    // Automatic fixing and verification (Day 4)
    Route::post('/scans/{scan}/fixes', [FixController::class, 'store'])->middleware('throttle:15,1')->name('scans.fixes.store');
    Route::get('/fixes/{fix}', [FixController::class, 'show'])->name('fixes.show');
    Route::post('/fixes/{fix}/build', [FixController::class, 'build'])->name('fixes.build');
    Route::get('/fixes/{fix}/download', [FixController::class, 'download'])->name('fixes.download');

    // ---------- Admin only
    Route::middleware('role:admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/users', [UserController::class, 'index'])->name('users');
        Route::patch('/users/{user}/role', [UserController::class, 'updateRole'])->name('users.role');
        Route::post('/users/{user}/toggle', [UserController::class, 'toggleStatus'])->name('users.toggle');
        Route::post('/users/{user}/reset-password', [UserController::class, 'resetPassword'])->name('users.reset-password');
        Route::get('/monitor', [MonitorController::class, 'monitor'])->name('monitor');
        Route::get('/settings', [MonitorController::class, 'settings'])->name('settings');
        Route::post('/settings', [MonitorController::class, 'updateSettings'])->name('settings.update');
        Route::get('/audit-log', [AuditLogController::class, 'index'])->name('audit');
    });
});
