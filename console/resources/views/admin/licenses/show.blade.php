@extends('layouts.admin')

@section('title', 'License Details')
@section('header_title', 'License: ' . $license->uuid)

@section('content')
<div class="flex-between" style="margin-bottom: 20px;">
    <div>
        <a href="{{ route('admin.licenses.index') }}" style="color: #60a5fa; text-decoration: none; font-size: 0.85rem;">← Back to Licenses</a>
        <h2 style="font-size: 1.3rem; margin-top: 4px;">License Detail</h2>
    </div>
    <div style="display: flex; gap: 10px;">
        <!-- Renew Button -->
        <form action="{{ route('admin.licenses.renew', $license) }}" method="POST">
            @csrf
            <button type="submit" class="btn btn-primary" onclick="return confirm('Renew license from max(expiry, today)?')">
                🔄 Renew License
            </button>
        </form>

        <!-- Suspend Button -->
        <form action="{{ route('admin.licenses.suspend', $license) }}" method="POST">
            @csrf
            <button type="submit" class="btn btn-secondary">
                {{ $license->status === 'suspended' ? 'Unsuspend' : 'Suspend' }}
            </button>
        </form>

        <!-- Revoke Button (Super Admin only) -->
        @if (auth()->user()?->isSuperAdmin() && $license->status !== 'revoked')
            <button type="button" class="btn btn-danger" onclick="document.getElementById('revoke-modal').style.display='block'">
                ⛔ Revoke License
            </button>
        @endif
    </div>
</div>

@if ($reissueWarning)
    <div class="alert alert-warning">
        ⚠️ <strong>Frequent Device Reissue Warning:</strong> This license has been re-issued <strong>{{ $reissueCountYear }} times</strong> within the past 12 months. Please verify hardware replacement legitimacy.
    </div>
@endif

<div class="grid-2">
    <!-- Info Card -->
    <div class="card">
        <h3 style="font-size: 1.05rem; margin-bottom: 14px;">License Metadata</h3>
        <table>
            <tr><td style="color: var(--text-muted); width: 140px;">UUID</td><td><code>{{ $license->uuid }}</code></td></tr>
            <tr><td style="color: var(--text-muted);">Customer</td><td><strong><a href="{{ route('admin.customers.show', $license->customer) }}" style="color: #60a5fa;">{{ $license->customer?->name }}</a></strong> ({{ $license->customer?->phone }})</td></tr>
            <tr><td style="color: var(--text-muted);">Plan</td><td>{{ $license->plan?->name }} ({{ ucfirst($license->plan?->kind) }})</td></tr>
            <tr><td style="color: var(--text-muted);">Type</td><td><span style="text-transform: uppercase;">{{ $license->type }}</span> ({{ $license->plan?->seats }} seat)</td></tr>
            <tr><td style="color: var(--text-muted);">Bind Code</td><td><code>{{ $license->bind_code }}</code></td></tr>
            <tr><td style="color: var(--text-muted);">Status</td><td><span class="badge {{ $license->status === 'active' ? 'badge-active' : 'badge-danger' }}">{{ strtoupper($license->status) }}</span></td></tr>
            <tr><td style="color: var(--text-muted);">Operational State</td><td><span class="badge badge-active">{{ $license->getRealtimeState($todayDay) }}</span></td></tr>
            <tr><td style="color: var(--text-muted);">Expiry Day</td><td>{{ \App\Services\DateEpochService::dayToDate($license->current_exp_day) }} (Day #{{ $license->current_exp_day }})</td></tr>
            <tr><td style="color: var(--text-muted);">Remaining</td><td>{{ $license->getRemainingDays($todayDay) }} days</td></tr>
        </table>
    </div>

    <!-- Reissue / Device Change Card -->
    <div class="card">
        <h3 style="font-size: 1.05rem; margin-bottom: 10px;">Change Device (Reissue)</h3>
        <p style="color: var(--text-muted); font-size: 0.85rem; margin-bottom: 16px;">
            Move this commercial license to a new customer device code. Total re-issues: <strong>{{ $reissueCountYear }}</strong> this year.
        </p>

        <form action="{{ route('admin.licenses.reissue', $license) }}" method="POST">
            @csrf
            <div class="form-group">
                <label for="new_bind_code">New {{ ucfirst($license->type) }} Code *</label>
                <input type="text" name="new_bind_code" id="new_bind_code" required placeholder="DXXX-XXXX-XXXX-XXX" style="font-family: monospace;">
            </div>

            <div class="form-group">
                <label for="reason">Reason for Replacement *</label>
                <input type="text" name="reason" id="reason" required placeholder="e.g. Phone lost, screen broken, POS hardware upgraded">
            </div>

            <button type="submit" class="btn btn-secondary" onclick="return confirm('Confirm device change and generate new token?')">
                🔄 Reissue for New Code
            </button>
        </form>
    </div>
</div>

<!-- Token History -->
<div class="card">
    <h3 style="font-size: 1.05rem; margin-bottom: 14px;">Token History</h3>
    @if ($license->tokens->count() > 0)
        <table>
            <thead>
                <tr>
                    <th>Issued At</th>
                    <th>Key ID</th>
                    <th>Valid Window</th>
                    <th>Token (Ed25519)</th>
                    <th>Issuer</th>
                    <th>Copy</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($license->tokens as $tok)
                    <tr>
                        <td>{{ $tok->created_at?->format('Y-m-d H:i') }}</td>
                        <td><code>kid={{ $tok->kid }}</code></td>
                        <td>Day {{ $tok->nbf_day }} → Day {{ $tok->exp_day === 0xFFFF ? 'Never' : $tok->exp_day }}</td>
                        <td>
                            <code style="font-size: 0.78rem;">{{ substr($tok->token, 0, 32) }}...{{ substr($tok->token, -10) }}</code>
                        </td>
                        <td>{{ $tok->issuer?->name ?? 'System' }}</td>
                        <td>
                            <button type="button" class="btn btn-secondary btn-sm" data-copy-text="{{ $tok->token }}" onclick="copyToClipboard('{{ $tok->token }}', this)">
                                Copy
                            </button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        <p style="color: var(--text-muted);">No tokens found.</p>
    @endif
</div>

<!-- Reissues History -->
@if ($license->reissues->count() > 0)
    <div class="card">
        <h3 style="font-size: 1.05rem; margin-bottom: 14px;">Device Reissue History</h3>
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Old Bind Code</th>
                    <th>New Bind Code</th>
                    <th>Reason</th>
                    <th>Authorized By</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($license->reissues as $r)
                    <tr>
                        <td>{{ $r->created_at?->format('Y-m-d H:i') }}</td>
                        <td><code>{{ $r->old_bind_code }}</code></td>
                        <td><code>{{ $r->new_bind_code }}</code></td>
                        <td>{{ $r->reason }}</td>
                        <td>{{ $r->issuer?->name ?? 'Admin' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif

<!-- Revoke Modal -->
<div id="revoke-modal" style="display:none; position: fixed; inset: 0; background: rgba(0,0,0,0.7); z-index: 1000; align-items: center; justify-content: center; padding: 20px;">
    <div style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 8px; max-width: 450px; margin: 100px auto; padding: 24px;">
        <h3 style="color: #f87171; margin-bottom: 10px;">Confirm License Revocation</h3>
        <p style="font-size: 0.88rem; color: var(--text-muted); margin-bottom: 16px;">
            Revoking will permanently invalidate this license, add its identifier to the signed revocation list, and block future renewals.
        </p>

        <form action="{{ route('admin.licenses.revoke', $license) }}" method="POST">
            @csrf
            <div class="form-group">
                <label for="revoke-reason">Reason for Revocation *</label>
                <input type="text" name="reason" id="revoke-reason" required placeholder="e.g. Refund issued, fraudulent chargeback">
            </div>
            <div style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('revoke-modal').style.display='none'">Cancel</button>
                <button type="submit" class="btn btn-danger">Confirm Revoke</button>
            </div>
        </form>
    </div>
</div>
@endsection
