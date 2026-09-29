<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\Admin\AuditController as AdminAuditController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\InvestigationController as AdminInvestigationController;
use App\Http\Controllers\Admin\PackageController as AdminPackageController;
use App\Http\Controllers\Admin\PaymentController as AdminPaymentController;
use App\Http\Controllers\Admin\SourceController as AdminSourceController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InvestigationController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::view('/privacy', 'legal.privacy')->name('legal.privacy');
Route::view('/terms', 'legal.terms')->name('legal.terms');

Route::get('/dashboard', [DashboardController::class, 'index'])->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

Route::middleware(['auth', 'verified', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [AdminDashboardController::class, 'index'])->name('dashboard');

    Route::get('/users', [AdminUserController::class, 'index'])->name('users.index');
    Route::post('/users/{user}/credits', [AdminUserController::class, 'adjustCredits'])->name('users.adjust-credits');

    Route::resource('packages', AdminPackageController::class)->except(['show']);

    Route::get('/payments', [AdminPaymentController::class, 'index'])->name('payments.index');

    Route::get('/investigations', [AdminInvestigationController::class, 'index'])->name('investigations.index');
    Route::get('/investigations/{investigation}', [AdminInvestigationController::class, 'show'])->name('investigations.show');

    Route::get('/sources', [AdminSourceController::class, 'index'])->name('sources.index');
    Route::post('/sources/{source}/toggle', [AdminSourceController::class, 'toggle'])->name('sources.toggle');

    Route::get('/audit', [AdminAuditController::class, 'index'])->name('audit.index');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/credits', [PaymentController::class, 'index'])->name('payments.index');
    Route::post('/credits/{package}/checkout', [PaymentController::class, 'store'])->middleware('throttle:10,1')->name('payments.store');
    Route::get('/payments/{payment}', [PaymentController::class, 'show'])->name('payments.show');
    Route::post('/payments/{payment}/simulate', [PaymentController::class, 'simulate'])->name('payments.simulate');

    Route::get('/investigations', [InvestigationController::class, 'index'])->name('investigations.index');
    Route::get('/investigations/create', [InvestigationController::class, 'create'])->name('investigations.create');
    Route::post('/investigations', [InvestigationController::class, 'store'])->middleware('throttle:10,1')->name('investigations.store');
    Route::get('/investigations/{investigation}', [InvestigationController::class, 'show'])->name('investigations.show');

    Route::get('/investigations/{investigation}/runs/{run}/report', [ReportController::class, 'show'])->name('investigations.report');

    Route::get('/account/privacy', [AccountController::class, 'privacy'])->name('account.privacy');
    Route::get('/account/export', [AccountController::class, 'export'])->name('account.export');
});

Route::post('/payments/webhook', [PaymentController::class, 'webhook'])->name('payments.webhook');

require __DIR__.'/auth.php';
