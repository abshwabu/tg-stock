@extends('layouts.admin')

@section('title', 'Audit Logs')
@section('header_title', 'Security & Operational Audit Trail')

@section('content')
<div class="flex-between" style="margin-bottom: 20px;">
    <div>
        <h2 style="font-size: 1.3rem;">Audit Trail</h2>
        <p style="color: var(--text-muted); font-size: 0.85rem;">Full audit log recording every sensitive action, token issuance, key rotation, and payment approval.</p>
    </div>
</div>

<!-- Filters -->
<div class="card" style="padding: 16px;">
    <form action="{{ route('admin.audit_logs.index') }}" method="GET" style="display: flex; gap: 12px; flex-wrap: wrap;">
        <input type="text" name="search" value="{{ $search }}" placeholder="Search by user, action, resource ID, IP..." style="flex: 2; min-width: 220px;">
        <select name="action" style="flex: 1; min-width: 180px;">
            <option value="">All Actions</option>
            @foreach ($distinctActions as $act)
                <option value="{{ $act }}" {{ $selectedAction === $act ? 'selected' : '' }}>{{ $act }}</option>
            @endforeach
        </select>
        <button type="submit" class="btn btn-secondary">Filter</button>
        @if ($search || $selectedAction)
            <a href="{{ route('admin.audit_logs.index') }}" class="btn btn-secondary">Reset</a>
        @endif
    </form>
</div>

<!-- Table -->
<div class="card" style="padding: 0; overflow: hidden;">
    <table>
        <thead>
            <tr>
                <th>Timestamp</th>
                <th>User / Agent</th>
                <th>Action</th>
                <th>Resource</th>
                <th>IP Address</th>
                <th>Details</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($logs as $l)
                <tr>
                    <td>{{ $l->created_at?->format('Y-m-d H:i:s') }}</td>
                    <td>
                        <strong>{{ $l->user_name ?? ($l->user?->name ?? 'System/API') }}</strong>
                        @if ($l->user)
                            <div style="font-size: 0.75rem; color: var(--text-muted);">{{ $l->user->role }}</div>
                        @endif
                    </td>
                    <td><span class="badge badge-active">{{ $l->action }}</span></td>
                    <td>
                        @if ($l->resource_type)
                            {{ $l->resource_type }} #{{ $l->resource_id }}
                        @else
                            -
                        @endif
                    </td>
                    <td><code>{{ $l->ip_address ?? '-' }}</code></td>
                    <td>
                        @if ($l->payload)
                            <details style="cursor: pointer; font-size: 0.78rem;">
                                <summary style="color: #60a5fa;">View Payload</summary>
                                <pre style="background: #0b1120; padding: 6px; border-radius: 4px; margin-top: 4px; max-width: 300px; overflow-x: auto;">{{ json_encode($l->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                            </details>
                        @else
                            -
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 30px;">
                        No audit records found.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div style="margin-top: 16px;">
    {{ $logs->links() }}
</div>
@endsection
