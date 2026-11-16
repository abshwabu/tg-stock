<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\KeyController;
use App\Http\Controllers\Admin\LicenseController;
use App\Http\Controllers\Admin\OperationsController;
use App\Http\Controllers\Admin\PaymentController;
use App\Http\Controllers\Admin\PlanController;
use App\Http\Controllers\Admin\SettingController;
use Illuminate\Support\Facades\Route;

// Guest authentication routes
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->name('login.post');
});

Route::post('/logout', [AuthController::class, 'logout'])->name('logout')->middleware('auth');

// 2FA Challenge & Setup routes
Route::middleware('auth')->group(function () {
    Route::get('/2fa/challenge', [AuthController::class, 'showTwoFactorChallenge'])->name('2fa.challenge');
    Route::post('/2fa/challenge', [AuthController::class, 'verifyTwoFactor'])->name('2fa.verify');
    Route::get('/2fa/setup', [AuthController::class, 'showTwoFactorSetup'])->name('2fa.setup');
    Route::post('/2fa/setup', [AuthController::class, 'confirmTwoFactorSetup'])->name('2fa.confirm');
});

// Authenticated Admin Dashboard & Operations
Route::middleware(['auth', 'admin.auth', 'ip.allowlist'])->prefix('admin')->as('admin.')->group(function () {
    Route::get('/', function () {
        return redirect()->route('admin.dashboard');
    });

    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Customers
    Route::resource('customers', CustomerController::class);

    // Plans (Plans are data, never hardcoded)
    Route::resource('plans', PlanController::class);

    // License Token Wizard & Actions
    Route::get('/licenses/wizard', [LicenseController::class, 'showWizard'])->name('licenses.wizard');
    Route::post('/licenses/generate', [LicenseController::class, 'generateToken'])->name('licenses.generate');
    Route::post('/licenses/{license}/renew', [LicenseController::class, 'renew'])->name('licenses.renew');
    Route::post('/licenses/{license}/reissue', [LicenseController::class, 'reissue'])->name('licenses.reissue');
    Route::post('/licenses/{license}/suspend', [LicenseController::class, 'suspend'])->name('licenses.suspend');
    Route::post('/licenses/{license}/revoke', [LicenseController::class, 'revoke'])->name('licenses.revoke')->middleware('role.super_admin');
    Route::resource('licenses', LicenseController::class)->only(['index', 'show']);

    // Payments
    Route::get('/payments', [PaymentController::class, 'index'])->name('payments.index');
    Route::get('/payments/create', [PaymentController::class, 'create'])->name('payments.create');
    Route::post('/payments', [PaymentController::class, 'store'])->name('payments.store');
    Route::post('/payments/{payment}/approve', [PaymentController::class, 'approve'])->name('payments.approve')->middleware('role.super_admin');
    Route::post('/payments/{payment}/reject', [PaymentController::class, 'reject'])->name('payments.reject')->middleware('role.super_admin');
    Route::get('/payments/{payment}/proof', [PaymentController::class, 'downloadProof'])->name('payments.proof');

    // Keys (super_admin only for modifications/exports)
    Route::get('/keys', [KeyController::class, 'index'])->name('keys.index');
    Route::post('/keys/rotate', [KeyController::class, 'rotate'])->name('keys.rotate')->middleware('role.super_admin');
    Route::get('/keys/{keypair}/export', [KeyController::class, 'showExportForm'])->name('keys.export_form')->middleware('role.super_admin');
    Route::post('/keys/{keypair}/export', [KeyController::class, 'exportBackup'])->name('keys.export')->middleware('role.super_admin');

    // Settings
    Route::get('/settings', [SettingController::class, 'index'])->name('settings.index')->middleware('role.super_admin');
    Route::post('/settings', [SettingController::class, 'update'])->name('settings.update')->middleware('role.super_admin');

    // Audit Logs
    Route::get('/audit-logs', [AuditLogController::class, 'index'])->name('audit_logs.index');

    // Operations, Health & Exports
    Route::get('/health', [OperationsController::class, 'health'])->name('health');
    Route::get('/operations/backup-db', [OperationsController::class, 'downloadEncryptedBackup'])->name('backup_db')->middleware('role.super_admin');
    Route::get('/export/customers', [OperationsController::class, 'exportCustomersCsv'])->name('export.customers');
    Route::get('/export/licenses', [OperationsController::class, 'exportLicensesCsv'])->name('export.licenses');
    Route::get('/export/payments', [OperationsController::class, 'exportPaymentsCsv'])->name('export.payments');
});

// Root fallback
Route::get('/', function () {
    return redirect()->route('admin.dashboard');
});
