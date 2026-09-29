<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InvestigationController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', [DashboardController::class, 'index'])->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

Route::middleware(['auth', 'verified', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', function () {
        return view('admin.dashboard');
    })->name('dashboard');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/credits', [PaymentController::class, 'index'])->name('payments.index');
    Route::post('/credits/{package}/checkout', [PaymentController::class, 'store'])->name('payments.store');
    Route::get('/payments/{payment}', [PaymentController::class, 'show'])->name('payments.show');
    Route::post('/payments/{payment}/simulate', [PaymentController::class, 'simulate'])->name('payments.simulate');

    Route::resource('investigations', InvestigationController::class)->only(['index', 'create', 'store', 'show']);

    Route::get('/investigations/{investigation}/runs/{run}/report', [ReportController::class, 'show'])->name('investigations.report');
});

Route::post('/payments/webhook', [PaymentController::class, 'webhook'])->name('payments.webhook');

require __DIR__.'/auth.php';
