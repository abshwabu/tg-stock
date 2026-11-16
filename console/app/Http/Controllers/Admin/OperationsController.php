<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Keypair;
use App\Models\License;
use App\Models\Payment;
use App\Services\DateEpochService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OperationsController extends Controller
{
    public function health()
    {
        $dbOk = false;
        try {
            DB::connection()->getPdo();
            $dbOk = true;
        } catch (\Throwable $e) {
            $dbOk = false;
        }

        $sodiumOk = extension_loaded('sodium');
        $activeKey = Keypair::where('is_active', true)->first();
        $diskOk = is_writable(storage_path('app'));
        $pendingPaymentsCount = Payment::where('status', 'pending')->count();

        $allOk = $dbOk && $sodiumOk && ($activeKey !== null) && $diskOk;

        return view('admin.operations.health', [
            'allOk' => $allOk,
            'dbOk' => $dbOk,
            'sodiumOk' => $sodiumOk,
            'activeKey' => $activeKey,
            'diskOk' => $diskOk,
            'pendingPaymentsCount' => $pendingPaymentsCount,
            'serverTime' => (new \DateTimeImmutable('now', DateEpochService::getTimeZone()))->format('Y-m-d H:i:s T'),
            'todayDay' => DateEpochService::todayDay(),
        ]);
    }

    public function exportCustomersCsv(): StreamedResponse
    {
        $fileName = 'customers-' . date('Ymd-His') . '.csv';

        AuditLog::log('export_customers_csv', 'Export');

        return response()->streamDownload(function () {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['ID', 'Name', 'Phone', 'Telegram ID', 'Shop Name', 'Notes', 'Created At']);

            Customer::chunk(500, function ($customers) use ($handle) {
                foreach ($customers as $c) {
                    fputcsv($handle, [
                        $c->id,
                        $c->name,
                        $c->phone,
                        $c->telegram_id,
                        $c->shop_name,
                        $c->notes,
                        $c->created_at?->toIso8601String(),
                    ]);
                }
            });

            fclose($handle);
        }, $fileName, ['Content-Type' => 'text/csv']);
    }

    public function exportLicensesCsv(): StreamedResponse
    {
        $fileName = 'licenses-' . date('Ymd-His') . '.csv';
        $today = DateEpochService::todayDay();

        AuditLog::log('export_licenses_csv', 'Export');

        return response()->streamDownload(function () use ($today) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, [
                'UUID', 'Customer Name', 'Customer Phone', 'Plan', 'Type',
                'Bind Code', 'Status', 'Expiry Date', 'Operational State', 'Created At'
            ]);

            License::with(['customer', 'plan'])->chunk(500, function ($licenses) use ($handle, $today) {
                foreach ($licenses as $l) {
                    fputcsv($handle, [
                        $l->uuid,
                        $l->customer?->name,
                        $l->customer?->phone,
                        $l->plan?->name,
                        $l->type,
                        $l->bind_code,
                        $l->status,
                        DateEpochService::dayToDate($l->current_exp_day),
                        $l->getRealtimeState($today),
                        $l->created_at?->toIso8601String(),
                    ]);
                }
            });

            fclose($handle);
        }, $fileName, ['Content-Type' => 'text/csv']);
    }

    public function exportPaymentsCsv(): StreamedResponse
    {
        $fileName = 'payments-' . date('Ymd-His') . '.csv';

        AuditLog::log('export_payments_csv', 'Export');

        return response()->streamDownload(function () {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, [
                'ID', 'Customer Name', 'Amount (ETB)', 'Method', 'Reference',
                'Status', 'Approved By', 'Created At'
            ]);

            Payment::with(['customer', 'approver'])->chunk(500, function ($payments) use ($handle) {
                foreach ($payments as $p) {
                    fputcsv($handle, [
                        $p->id,
                        $p->customer?->name,
                        $p->amount,
                        $p->method,
                        $p->reference,
                        $p->status,
                        $p->approver?->name,
                        $p->created_at?->toIso8601String(),
                    ]);
                }
            });

            fclose($handle);
        }, $fileName, ['Content-Type' => 'text/csv']);
    }

    public function downloadEncryptedBackup(): StreamedResponse
    {
        if (!auth()->user()->isSuperAdmin()) {
            abort(403, 'Unauthorized. Super Admin role required for database backups.');
        }

        $dbPath = database_path('database.sqlite');
        if (!file_exists($dbPath)) {
            abort(404, 'Database file not found.');
        }

        $rawDb = file_get_contents($dbPath);
        $key = hash('sha256', config('license.backup_encryption_key', 'tgstock-offline-backup-aes-key-v1-2026'), true);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $encrypted = sodium_crypto_secretbox($rawDb, $nonce, $key);

        $payload = json_encode([
            'backup_version' => 1,
            'created_at' => now()->toIso8601String(),
            'nonce_hex' => bin2hex($nonce),
            'ciphertext_hex' => bin2hex($encrypted),
        ], JSON_THROW_ON_ERROR);

        $fileName = 'tgstock-console-db-backup-' . date('Ymd-His') . '.enc.json';

        AuditLog::log('database_backup_downloaded', 'Operations');

        return response()->streamDownload(function () use ($payload) {
            echo $payload;
        }, $fileName, ['Content-Type' => 'application/json']);
    }
}
