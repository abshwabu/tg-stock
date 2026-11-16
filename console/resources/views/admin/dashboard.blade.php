@extends('layouts.admin')

@section('title', 'Dashboard')
@section('header_title', 'License Console Dashboard')

@section('content')
<div class="flex-between" style="margin-bottom: 20px;">
    <div>
        <h2 style="font-size: 1.4rem; font-weight: 700;">Commercial Operations Overview</h2>
        <p style="color: var(--text-muted); font-size: 0.85rem;">System Date: {{ $todayDate }} (Addis Ababa Day #{{ $todayDay }})</p>
    </div>
    <div>
        <a href="{{ route('admin.licenses.wizard') }}" class="btn btn-primary">✨ Issue New Token</a>
    </div>
</div>

<!-- Key Metric Cards -->
<div class="grid-4">
    <div class="stat-card">
        <div class="stat-title">Active Licenses</div>
        <div class="stat-value" style="color: #34d399;">{{ $activeCount }}</div>
    </div>
    <div class="stat-card">
        <div class="stat-title">Expiring Soon (≤ 7 Days)</div>
        <div class="stat-value" style="color: #fbbf24;">{{ $expiring7Count }}</div>
    </div>
    <div class="stat-card">
        <div class="stat-title">In Grace Period</div>
        <div class="stat-value" style="color: #fb923c;">{{ $graceCount }}</div>
    </div>
    <div class="stat-card">
        <div class="stat-title">Expired (Read-Only)</div>
        <div class="stat-value" style="color: #f87171;">{{ $expiredCount }}</div>
    </div>
</div>

<div class="grid-4">
    <div class="stat-card">
        <div class="stat-title">Est. Monthly Revenue (MRR)</div>
        <div class="stat-value">{{ number_format($mrr, 2) }} <span style="font-size: 0.9rem; color: var(--text-muted);">ETB</span></div>
    </div>
    <div class="stat-card">
        <div class="stat-title">Approved Revenue (This Month)</div>
        <div class="stat-value">{{ number_format($revenueThisMonth, 2) }} <span style="font-size: 0.9rem; color: var(--text-muted);">ETB</span></div>
    </div>
    <div class="stat-card">
        <div class="stat-title">Trial → Paid Conversion</div>
        <div class="stat-value">{{ $trialConversionRate }}% <span style="font-size: 0.8rem; color: var(--text-muted);">({{ $convertedTrials }}/{{ $totalTrials }})</span></div>
    </div>
    <div class="stat-card">
        <div class="stat-title">Total Customers</div>
        <div class="stat-value">{{ $newCustomersTotal }} <span style="font-size: 0.8rem; color: #34d399;">(+{{ $newCustomersThisMonth }} this mo)</span></div>
    </div>
</div>

<!-- Revenue by Method -->
<div class="card">
    <h3 style="font-size: 1.05rem; margin-bottom: 14px;">Revenue Breakdown by Payment Method (Approved YTD)</h3>
    <div style="display: flex; gap: 20px; flex-wrap: wrap;">
        @forelse (['telebirr' => 'Telebirr', 'cbe' => 'CBE Birr / CBE', 'bank' => 'Bank Transfer', 'cash' => 'Cash', 'other' => 'Other'] as $methodKey => $methodLabel)
            <div style="background: var(--bg-hover); padding: 12px 18px; border-radius: 6px; min-width: 160px;">
                <div style="font-size: 0.78rem; text-transform: uppercase; color: var(--text-muted);">{{ $methodLabel }}</div>
                <div style="font-size: 1.25rem; font-weight: 700; color: var(--text-main); margin-top: 4px;">
                    {{ number_format($revenueByMethod[$methodKey] ?? 0, 2) }} <span style="font-size: 0.75rem;">ETB</span>
                </div>
            </div>
        @empty
            <p style="color: var(--text-muted);">No revenue recorded yet.</p>
        @endforelse
    </div>
</div>

<div class="grid-2">
    <!-- Actionable Expiring Soon / In Grace Table -->
    <div class="card">
        <div class="flex-between" style="margin-bottom: 14px;">
            <h3 style="font-size: 1.05rem;">Action Needed: Expiring Soon & Grace</h3>
            <span class="badge badge-expiring">{{ count($expiringSoonList) }} Licenses</span>
        </div>

        @if (count($expiringSoonList) > 0)
            <table>
                <thead>
                    <tr>
                        <th>Customer / Shop</th>
                        <th>Status</th>
                        <th>Expires</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($expiringSoonList as $lic)
                        @php $state = $lic->getRealtimeState($todayDay); @endphp
                        <tr>
                            <td>
                                <strong><a href="{{ route('admin.customers.show', $lic->customer) }}" style="color: #60a5fa; text-decoration: none;">{{ $lic->customer?->name }}</a></strong>
                                <div style="font-size: 0.78rem; color: var(--text-muted);">{{ $lic->customer?->phone }}</div>
                            </td>
                            <td>
                                <span class="badge {{ $state === 'GRACE' ? 'badge-grace' : 'badge-expiring' }}">
                                    {{ $state }}
                                </span>
                            </td>
                            <td>
                                {{ \App\Services\DateEpochService::dayToDate($lic->current_exp_day) }}
                                <div style="font-size: 0.75rem; color: var(--text-muted);">
                                    {{ $lic->getRemainingDays($todayDay) }} days left
                                </div>
                            </td>
                            <td>
                                <form action="{{ route('admin.licenses.renew', $lic) }}" method="POST" style="display:inline;">
                                    @csrf
                                    <button type="submit" class="btn btn-primary btn-sm" onclick="return confirm('Renew license for another plan cycle?')">Renew</button>
                                </form>
                                @if ($lic->customer?->telegram_id)
                                    <button type="button" class="btn btn-secondary btn-sm" onclick="alert('Telegram reminder queued for @{{ $lic->customer->telegram_id }}')">Bot</button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <p style="color: var(--text-muted); font-size: 0.9rem; padding: 12px 0;">No licenses currently in expiring warning or grace period.</p>
        @endif
    </div>

    <!-- Pending Payment Requests -->
    <div class="card">
        <div class="flex-between" style="margin-bottom: 14px;">
            <h3 style="font-size: 1.05rem;">Recent Pending Payment Claims</h3>
            <a href="{{ route('admin.payments.index', ['status' => 'pending']) }}" style="font-size: 0.8rem; color: #60a5fa;">View All</a>
        </div>

        @if ($pendingPayments->count() > 0)
            <table>
                <thead>
                    <tr>
                        <th>Customer</th>
                        <th>Amount</th>
                        <th>Method / Ref</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($pendingPayments as $p)
                        <tr>
                            <td>
                                <strong>{{ $p->customer?->name }}</strong>
                                <div style="font-size: 0.78rem; color: var(--text-muted);">{{ $p->customer?->phone }}</div>
                            </td>
                            <td>
                                <strong>{{ number_format($p->amount, 2) }}</strong> ETB
                            </td>
                            <td>
                                <span style="text-transform: uppercase; font-size: 0.8rem;">{{ $p->method }}</span>
                                @if ($p->reference)
                                    <div style="font-size: 0.75rem; color: var(--text-muted);">Ref: {{ $p->reference }}</div>
                                @endif
                            </td>
                            <td>
                                @if (auth()->user()?->isSuperAdmin())
                                    <form action="{{ route('admin.payments.approve', $p) }}" method="POST" style="display:inline;">
                                        @csrf
                                        <button type="submit" class="btn btn-success btn-sm">Approve</button>
                                    </form>
                                    <form action="{{ route('admin.payments.reject', $p) }}" method="POST" style="display:inline;">
                                        @csrf
                                        <button type="submit" class="btn btn-danger btn-sm">Reject</button>
                                    </form>
                                @else
                                    <span style="font-size: 0.75rem; color: var(--text-muted);">Review pending</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <p style="color: var(--text-muted); font-size: 0.9rem; padding: 12px 0;">No pending payments to review.</p>
        @endif
    </div>
</div>
@endsection
