@extends('layouts.admin')

@section('title', 'Licenses')
@section('header_title', 'License Management')

@section('content')
<div class="flex-between" style="margin-bottom: 20px;">
    <div>
        <h2 style="font-size: 1.3rem;">All Commercial Licenses</h2>
        <p style="color: var(--text-muted); font-size: 0.85rem;">Manage active, expiring, grace, and expired license subscriptions.</p>
    </div>
    <a href="{{ route('admin.licenses.wizard') }}" class="btn btn-primary">✨ Issue New Token</a>
</div>

<!-- Filters -->
<div class="card" style="padding: 16px;">
    <form action="{{ route('admin.licenses.index') }}" method="GET" style="display: flex; gap: 12px; flex-wrap: wrap;">
        <input type="text" name="search" value="{{ $search }}" placeholder="Search by customer, phone, code, UUID..." style="flex: 2; min-width: 220px;">
        <select name="plan_id" style="flex: 1; min-width: 160px;">
            <option value="">All Plans</option>
            @foreach ($plans as $p)
                <option value="{{ $p->id }}" {{ (string)$selectedPlan === (string)$p->id ? 'selected' : '' }}>{{ $p->name }}</option>
            @endforeach
        </select>
        <select name="type" style="flex: 1; min-width: 140px;">
            <option value="">All Types</option>
            <option value="device" {{ $selectedType === 'device' ? 'selected' : '' }}>Device</option>
            <option value="shop" {{ $selectedType === 'shop' ? 'selected' : '' }}>Shop</option>
        </select>
        <select name="status" style="flex: 1; min-width: 140px;">
            <option value="">All Statuses</option>
            <option value="active" {{ $selectedStatus === 'active' ? 'selected' : '' }}>Active</option>
            <option value="suspended" {{ $selectedStatus === 'suspended' ? 'selected' : '' }}>Suspended</option>
            <option value="revoked" {{ $selectedStatus === 'revoked' ? 'selected' : '' }}>Revoked</option>
        </select>
        <button type="submit" class="btn btn-secondary">Filter</button>
        @if ($search || $selectedPlan || $selectedType || $selectedStatus)
            <a href="{{ route('admin.licenses.index') }}" class="btn btn-secondary">Reset</a>
        @endif
    </form>
</div>

<!-- Table -->
<div class="card" style="padding: 0; overflow: hidden;">
    <table>
        <thead>
            <tr>
                <th>Customer / Shop</th>
                <th>Plan & Type</th>
                <th>Bind Code</th>
                <th>State (Real-time)</th>
                <th>Expires</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($licenses as $lic)
                @php $state = $lic->getRealtimeState($todayDay); @endphp
                <tr>
                    <td>
                        <strong><a href="{{ route('admin.customers.show', $lic->customer) }}" style="color: #60a5fa; text-decoration: none;">{{ $lic->customer?->name }}</a></strong>
                        <div style="font-size: 0.78rem; color: var(--text-muted);">{{ $lic->customer?->shop_name ?? $lic->customer?->phone }}</div>
                    </td>
                    <td>
                        <div><strong>{{ $lic->plan?->name }}</strong></div>
                        <span style="font-size: 0.75rem; text-transform: uppercase; color: var(--text-muted);">{{ $lic->type }} ({{ $lic->plan?->seats }} seat)</span>
                    </td>
                    <td>
                        <code style="font-size: 0.82rem;">{{ $lic->bind_code }}</code>
                    </td>
                    <td>
                        <span class="badge 
                            @if($state === 'ACTIVE') badge-active
                            @elseif($state === 'EXPIRING') badge-expiring
                            @elseif($state === 'GRACE') badge-grace
                            @elseif($state === 'EXPIRED') badge-expired
                            @elseif($state === 'REVOKED') badge-revoked
                            @else badge-suspended @endif">
                            {{ $state }}
                        </span>
                    </td>
                    <td>
                        {{ \App\Services\DateEpochService::dayToDate($lic->current_exp_day) }}
                        <div style="font-size: 0.75rem; color: var(--text-muted);">
                            {{ $lic->getRemainingDays($todayDay) }} days
                        </div>
                    </td>
                    <td>
                        <a href="{{ route('admin.licenses.show', $lic) }}" class="btn btn-secondary btn-sm">Details</a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 30px;">
                        No licenses found matching current filters.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div style="margin-top: 16px;">
    {{ $licenses->links() }}
</div>
@endsection
