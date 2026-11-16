@extends('layouts.admin')

@section('title', $customer->name)
@section('header_title', 'Customer: ' . $customer->name)

@section('content')
<div class="flex-between" style="margin-bottom: 20px;">
    <div>
        <a href="{{ route('admin.customers.index') }}" style="color: #60a5fa; text-decoration: none; font-size: 0.85rem;">← Back to Customers</a>
        <h2 style="font-size: 1.3rem; margin-top: 4px;">{{ $customer->name }}</h2>
    </div>
    <div style="display: flex; gap: 10px;">
        <a href="{{ route('admin.customers.edit', $customer) }}" class="btn btn-secondary">Edit Customer</a>
        <a href="{{ route('admin.licenses.wizard', ['customer_id' => $customer->id]) }}" class="btn btn-primary">✨ Issue Token</a>
    </div>
</div>

<div class="grid-2">
    <!-- Customer Profile -->
    <div class="card">
        <h3 style="font-size: 1.05rem; margin-bottom: 14px;">Contact & Shop Details</h3>
        <table>
            <tr><td style="color: var(--text-muted); width: 140px;">Full Name</td><td><strong>{{ $customer->name }}</strong></td></tr>
            <tr><td style="color: var(--text-muted);">Phone</td><td>{{ $customer->phone }}</td></tr>
            <tr><td style="color: var(--text-muted);">Telegram ID</td><td><code>{{ $customer->telegram_id ? '@' . $customer->telegram_id : '-' }}</code></td></tr>
            <tr><td style="color: var(--text-muted);">Shop Name</td><td>{{ $customer->shop_name ?? '-' }}</td></tr>
            <tr><td style="color: var(--text-muted);">Created At</td><td>{{ $customer->created_at?->format('Y-m-d H:i') }}</td></tr>
            <tr><td style="color: var(--text-muted);">Notes</td><td>{{ $customer->notes ?? '-' }}</td></tr>
        </table>
    </div>

    <!-- Payment History -->
    <div class="card">
        <div class="flex-between" style="margin-bottom: 14px;">
            <h3 style="font-size: 1.05rem;">Payment History</h3>
            <a href="{{ route('admin.payments.create') }}" class="btn btn-secondary btn-sm">+ Record Payment</a>
        </div>
        @if ($customer->payments->count() > 0)
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Amount</th>
                        <th>Method</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($customer->payments as $p)
                        <tr>
                            <td>{{ $p->created_at?->format('Y-m-d') }}</td>
                            <td><strong>{{ number_format($p->amount, 2) }}</strong> ETB</td>
                            <td><span style="text-transform: uppercase; font-size: 0.8rem;">{{ $p->method }}</span></td>
                            <td><span class="badge {{ $p->status === 'approved' ? 'badge-active' : 'badge-expiring' }}">{{ ucfirst($p->status) }}</span></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <p style="color: var(--text-muted); font-size: 0.88rem;">No payments recorded yet.</p>
        @endif
    </div>
</div>

<!-- Commercial Licenses -->
<div class="card">
    <div class="flex-between" style="margin-bottom: 14px;">
        <h3 style="font-size: 1.05rem;">Assigned Licenses</h3>
        <a href="{{ route('admin.licenses.wizard', ['customer_id' => $customer->id]) }}" class="btn btn-primary btn-sm">+ Issue License</a>
    </div>

    @if ($customer->licenses->count() > 0)
        <table>
            <thead>
                <tr>
                    <th>Plan</th>
                    <th>Type</th>
                    <th>Bind Code</th>
                    <th>Expires</th>
                    <th>State</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($customer->licenses as $lic)
                    <tr>
                        <td><strong>{{ $lic->plan?->name }}</strong></td>
                        <td><span style="text-transform: uppercase; font-size: 0.8rem;">{{ $lic->type }}</span></td>
                        <td><code>{{ $lic->bind_code }}</code></td>
                        <td>{{ \App\Services\DateEpochService::dayToDate($lic->current_exp_day) }}</td>
                        <td><span class="badge badge-active">{{ $lic->getRealtimeState() }}</span></td>
                        <td>
                            <a href="{{ route('admin.licenses.show', $lic) }}" class="btn btn-secondary btn-sm">Manage</a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        <p style="color: var(--text-muted); font-size: 0.88rem;">No licenses issued to this customer yet.</p>
    @endif
</div>
@endsection
