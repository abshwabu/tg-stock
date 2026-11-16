<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Models\AuditLog;
use App\Models\Keypair;
use App\Services\KeyManagerService;
use App\Services\TotpService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;

class KeyController extends Controller
{
    public function __construct(
        protected KeyManagerService $keyManager,
        protected TotpService $totp
    ) {}

    public function index()
    {
        $activeKey = $this->keyManager->getActiveKeypair();
        $allKeys = Keypair::orderByDesc('kid')->get();

        return view('admin.keys.index', [
            'activeKey' => $activeKey,
            'allKeys' => $allKeys,
        ]);
    }

    public function rotate(Request $request)
    {
        if (!auth()->user()->isSuperAdmin()) {
            abort(403, 'Unauthorized. Support role cannot rotate root keys.');
        }

        $newKeypair = $this->keyManager->rotateKey();

        return redirect()->route('admin.keys.index')
            ->with('success', "Root signing key rotated successfully to key ID {$newKeypair->kid}.");
    }

    public function showExportForm(Keypair $keypair)
    {
        if (!auth()->user()->isSuperAdmin()) {
            abort(403, 'Unauthorized. Support role cannot export keys.');
        }

        return view('admin.keys.export', [
            'keypair' => $keypair,
        ]);
    }

    public function exportBackup(Request $request, Keypair $keypair)
    {
        if (!auth()->user()->isSuperAdmin()) {
            abort(403, 'Unauthorized. Support role cannot export keys.');
        }

        $request->validate([
            'password' => 'required|string',
            'totp_code' => 'required|string',
            'export_passphrase' => 'required|string|min:8',
        ]);

        $user = auth()->user();

        // 1. Password check
        if (!Hash::check($request->input('password'), $user->password)) {
            AuditLog::log('key_export_failed_bad_password', 'Keypair', (string)$keypair->kid);
            return back()->withErrors(['password' => 'Incorrect admin password.']);
        }

        // 2. 2FA TOTP check
        if (!$this->totp->verify($user->totp_secret, $request->input('totp_code'))) {
            AuditLog::log('key_export_failed_bad_totp', 'Keypair', (string)$keypair->kid);
            return back()->withErrors(['totp_code' => 'Invalid 2FA authentication code.']);
        }

        // 3. Export encrypted backup JSON
        $backupData = $this->keyManager->exportEncryptedBackup($keypair->kid, $request->input('export_passphrase'));
        $filename = "tgstock-key-backup-kid{$keypair->kid}-" . date('Ymd-His') . ".json";

        return response()->streamDownload(function () use ($backupData) {
            echo json_encode($backupData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }, $filename, [
            'Content-Type' => 'application/json',
        ]);
    }
}
