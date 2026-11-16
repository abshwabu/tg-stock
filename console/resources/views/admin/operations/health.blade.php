@extends('layouts.admin')

@section('title', 'System Health & Operations')
@section('header_title', 'System Health & Data Operations')

@section('content')
<div class="flex-between" style="margin-bottom: 20px;">
    <div>
        <h2 style="font-size: 1.3rem;">Console Health & Disaster Recovery</h2>
        <p style="color: var(--text-muted); font-size: 0.85rem;">Status diagnostics, database encryption backups, and CSV reporting.</p>
    </div>
</div>

<!-- Diagnostics Grid -->
<div class="grid-4" style="margin-bottom: 24px;">
    <div class="stat-card">
        <div class="stat-title">Database Status</div>
        <div class="stat-value" style="font-size: 1.3rem; color: {{ $dbOk ? '#34d399' : '#f87171' }};">
            {{ $dbOk ? 'ONLINE (SQLite)' : 'ERROR' }}
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-title">Crypto Engine</div>
        <div class="stat-value" style="font-size: 1.3rem; color: {{ $sodiumOk ? '#34d399' : '#f87171' }};">
            {{ $sodiumOk ? 'libsodium Active' : 'MISSING SODIUM' }}
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-title">Active Signing Key</div>
        <div class="stat-value" style="font-size: 1.3rem; color: {{ $activeKey ? '#34d399' : '#f87171' }};">
            {{ $activeKey ? 'kid=' . $activeKey->kid : 'NO KEY' }}
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-title">Storage Writeable</div>
        <div class="stat-value" style="font-size: 1.3rem; color: {{ $diskOk ? '#34d399' : '#f87171' }};">
            {{ $diskOk ? 'OK' : 'READ-ONLY' }}
        </div>
    </div>
</div>

<div class="grid-2">
    <!-- Encrypted Database Backups -->
    <div class="card">
        <h3 style="font-size: 1.05rem; margin-bottom: 10px;">Encrypted Database Backup</h3>
        <p style="color: var(--text-muted); font-size: 0.85rem; margin-bottom: 16px;">
            Download an authenticated, AES-encrypted snapshot of the console SQLite database. Backups can also be scheduled automatically via <code>php artisan console:backup-db</code>.
        </p>

        @if (auth()->user()?->isSuperAdmin())
            <a href="{{ route('admin.backup_db') }}" class="btn btn-primary">
                💾 Download Encrypted DB Backup
            </a>
        @else
            <button class="btn btn-secondary" disabled>Super Admin Required</button>
        @endif
    </div>

    <!-- CSV Exports -->
    <div class="card">
        <h3 style="font-size: 1.05rem; margin-bottom: 10px;">Reporting & Data Exports</h3>
        <p style="color: var(--text-muted); font-size: 0.85rem; margin-bottom: 16px;">
            Export commercial records to CSV format for external bookkeeping and offline reconciliation.
        </p>

        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
            <a href="{{ route('admin.export.customers') }}" class="btn btn-secondary">
                📄 Export Customers CSV
            </a>
            <a href="{{ route('admin.export.licenses') }}" class="btn btn-secondary">
                📄 Export Licenses CSV
            </a>
            <a href="{{ route('admin.export.payments') }}" class="btn btn-secondary">
                📄 Export Payments CSV
            </a>
        </div>
    </div>
</div>
@endsection
