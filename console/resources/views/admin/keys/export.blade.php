@extends('layouts.admin')

@section('title', 'Export Key Backup')
@section('header_title', 'Export Encrypted Key Backup (kid=' . $keypair->kid . ')')

@section('content')
<div style="max-width: 600px; margin: 0 auto;">
    <div class="card" style="border-color: #f59e0b;">
        <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 16px;">
            <div style="background: rgba(245, 158, 11, 0.2); color: #fbbf24; font-size: 1.5rem; width: 44px; height: 44px; border-radius: 50%; display: flex; align-items: center; justify-content: center;">🔒</div>
            <div>
                <h2 style="font-size: 1.3rem;">Export Encrypted Key Backup</h2>
                <p style="color: var(--text-muted); font-size: 0.85rem;">Key ID: <code>kid={{ $keypair->kid }}</code></p>
            </div>
        </div>

        <div class="alert alert-warning" style="font-size: 0.85rem;">
            <strong>Security Notice:</strong> The raw private key is <strong>never displayed in plaintext</strong>. It will be exported as an encrypted JSON backup file derived using your export passphrase via libsodium <code>crypto_pwhash</code> (Argon2id) and <code>crypto_secretbox</code>.
        </div>

        <form action="{{ route('admin.keys.export', $keypair) }}" method="POST">
            @csrf

            <div class="form-group">
                <label for="password">Re-enter Admin Password *</label>
                <input type="password" name="password" id="password" required autofocus autocomplete="current-password">
            </div>

            <div class="form-group">
                <label for="totp_code">Current 2FA TOTP Code (6 digits) *</label>
                <input type="text" name="totp_code" id="totp_code" maxlength="6" required placeholder="123456" inputmode="numeric" style="letter-spacing: 2px; font-weight: 700;">
            </div>

            <div class="form-group">
                <label for="export_passphrase">Choose Strong Export Passphrase * (min 8 chars)</label>
                <input type="password" name="export_passphrase" id="export_passphrase" required minlength="8" placeholder="Passphrase to encrypt the backup file">
                <p style="font-size: 0.78rem; color: var(--text-muted); margin-top: 4px;">You will need this passphrase if you ever need to restore the private key in disaster recovery.</p>
            </div>

            <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 24px;">
                <a href="{{ route('admin.keys.index') }}" class="btn btn-secondary">Cancel</a>
                <button type="submit" class="btn btn-primary">
                    📦 Download Encrypted Backup File
                </button>
            </div>
        </form>
    </div>
</div>
@endsection
