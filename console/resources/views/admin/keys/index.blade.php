@extends('layouts.admin')

@section('title', 'Root Keys')
@section('header_title', 'Root Cryptographic Keys (Ed25519)')

@section('content')
<div class="flex-between" style="margin-bottom: 20px;">
    <div>
        <h2 style="font-size: 1.3rem;">Ed25519 Root Keypairs</h2>
        <p style="color: var(--text-muted); font-size: 0.85rem;">Cryptographic keys used to sign all commercial licenses. Private keys are encrypted at rest with libsodium.</p>
    </div>
    @if (auth()->user()?->isSuperAdmin())
        <form action="{{ route('admin.keys.rotate') }}" method="POST" onsubmit="return confirm('Rotate root key? This creates a new active kid for all future tokens while existing keys remain active for verification.')">
            @csrf
            <button type="submit" class="btn btn-primary">🔄 Rotate to New Key</button>
        </form>
    @endif
</div>

<!-- Active Key Card -->
<div class="card" style="border-color: #3b82f6;">
    <div class="flex-between" style="margin-bottom: 12px;">
        <div style="display: flex; align-items: center; gap: 10px;">
            <span class="badge badge-active" style="font-size: 0.85rem; padding: 4px 10px;">ACTIVE ROOT SIGNING KEY</span>
            <span style="font-size: 1.1rem; font-weight: 700;">Key ID: {{ $activeKey->kid }}</span>
        </div>
        @if (auth()->user()?->isSuperAdmin())
            <a href="{{ route('admin.keys.export_form', $activeKey) }}" class="btn btn-secondary btn-sm">
                📦 Export Encrypted Backup
            </a>
        @endif
    </div>

    <div class="form-group" style="margin-bottom: 12px;">
        <label>Public Key (Hex / 32 Bytes)</label>
        <div class="code-snippet">{{ $activeKey->public_key_hex }}</div>
    </div>

    <div class="grid-2">
        <div>
            <label>Public Key Fingerprint (SHA-256)</label>
            <div class="code-snippet" style="font-size: 0.8rem;">{{ $activeKey->fingerprint }}</div>
        </div>
        <div>
            <label>Key Status & Lifecycle</label>
            <div style="font-size: 0.88rem; color: var(--text-muted); padding-top: 8px;">
                Initialized: {{ $activeKey->created_at?->format('Y-m-d H:i T') }}<br>
                Embedded in app client release as <code>kid={{ $activeKey->kid }}</code>
            </div>
        </div>
    </div>
</div>

<!-- Historical / Rotating Keys Table -->
<div class="card">
    <h3 style="font-size: 1.05rem; margin-bottom: 14px;">All Deployed Root Keypairs</h3>
    <p style="color: var(--text-muted); font-size: 0.85rem; margin-bottom: 16px;">
        Older keys are retained in storage so previously issued tokens continue to verify without interruption.
    </p>

    <table>
        <thead>
            <tr>
                <th>Key ID (kid)</th>
                <th>Status</th>
                <th>Public Key (Hex)</th>
                <th>Created At</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($allKeys as $k)
                <tr>
                    <td><strong>kid = {{ $k->kid }}</strong></td>
                    <td>
                        <span class="badge {{ $k->is_active ? 'badge-active' : 'badge-suspended' }}">
                            {{ $k->is_active ? 'Active' : 'Retired' }}
                        </span>
                    </td>
                    <td><code style="font-size: 0.8rem;">{{ substr($k->public_key_hex, 0, 20) }}...{{ substr($k->public_key_hex, -12) }}</code></td>
                    <td>{{ $k->created_at?->format('Y-m-d H:i') }}</td>
                    <td>
                        @if (auth()->user()?->isSuperAdmin())
                            <a href="{{ route('admin.keys.export_form', $k) }}" class="btn btn-secondary btn-sm">Export Backup</a>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
@endsection
