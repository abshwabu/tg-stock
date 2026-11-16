@extends('layouts.admin')

@section('title', 'Payments')
@section('header_title', 'Payment Receipts & Claims')

@section('content')
<div class="flex-between" style="margin-bottom: 20px;">
    <div>
        <h2 style="font-size: 1.3rem;">Payment Records</h2>
        <p style="color: var(--text-muted); font-size: 0.85rem;">Verify customer payments via Telebirr, CBE Birr, and Bank Transfers.</p>
    </div>
    <a href="{{ route('admin.payments.create') }}" class="btn btn-primary">+ Record Manual Payment</a>
</div>

<!-- Filters -->
<div class="card" style="padding: 16px;">
    <form action="{{ route('admin.payments.index') }}" method="GET" style="display: flex; gap: 12px; flex-wrap: wrap;">
        <select name="status" style="flex: 1; min-width: 150px;">
            <option value="">All Statuses</option>
            <option value="pending" {{ $selectedStatus === 'pending' ? 'selected' : '' }}>Pending</option>
            <option value="approved" {{ $selectedStatus === 'approved' ? 'selected' : '' }}>Approved</option>
            <option value="rejected" {{ $selectedStatus === 'rejected' ? 'selected' : '' }}>Rejected</option>
        </select>
        <select name="method" style="flex: 1; min-width: 150px;">
            <option value="">All Methods</option>
            <option value="telebirr" {{ $selectedMethod === 'telebirr' ? 'selected' : '' }}>Telebirr</option>
            <option value="cbe" {{ $selectedMethod === 'cbe' ? 'selected' : '' }}>CBE Birr / CBE</option>
            <option value="bank" {{ $selectedMethod === 'bank' ? 'selected' : '' }}>Bank Transfer</option>
            <option value="cash" {{ $selectedMethod === 'cash' ? 'selected' : '' }}>Cash</option>
            <option value="other" {{ $selectedMethod === 'other' ? 'selected' : '' }}>Other</option>
        </select>
        <button type="submit" class="btn btn-secondary">Filter</button>
        @if ($selectedStatus || $selectedMethod)
            <a href="{{ route('admin.payments.index') }}" class="btn btn-secondary">Reset</a>
        @endif
    </form>
</div>

<!-- Payments Table -->
<div class="card" style="padding: 0; overflow: hidden;">
    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Customer</th>
                <th>Amount (ETB)</th>
                <th>Method / Reference</th>
                <th>Proof</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($payments as $p)
                <tr>
                    <td>{{ $p->created_at?->format('Y-m-d H:i') }}</td>
                    <td>
                        <strong><a href="{{ route('admin.customers.show', $p->customer) }}" style="color: #60a5fa; text-decoration: none;">{{ $p->customer?->name }}</a></strong>
                        <div style="font-size: 0.78rem; color: var(--text-muted);">{{ $p->customer?->phone }}</div>
                    </td>
                    <td><strong style="font-size: 1rem;">{{ number_format($p->amount, 2) }}</strong> ETB</td>
                    <td>
                        <span style="text-transform: uppercase; font-size: 0.8rem; font-weight: 600;">{{ $p->method }}</span>
                        @if ($p->reference)
                            <div style="font-size: 0.78rem; color: var(--text-muted);">Ref: {{ $p->reference }}</div>
                        @endif
                    </td>
                    <td>
                        @if ($p->proof_path)
                            <a href="{{ route('admin.payments.proof', $p) }}" target="_blank" class="btn btn-secondary btn-sm">📎 View Proof</a>
                        @else
                            <span style="color: var(--text-muted); font-size: 0.8rem;">None</span>
                        @endif
                    </td>
                    <td>
                        <span class="badge 
                            @if($p->status === 'approved') badge-active
                            @elseif($p->status === 'pending') badge-expiring
                            @else badge-danger @endif">
                            {{ ucfirst($p->status) }}
                        </span>
                        @if ($p->approver)
                            <div style="font-size: 0.72rem; color: var(--text-muted);">By: {{ $p->approver->name }}</div>
                        @endif
                    </td>
                    <td>
                        @if ($p->status === 'pending' && auth()->user()?->isSuperAdmin())
                            <form action="{{ route('admin.payments.approve', $p) }}" method="POST" style="display:inline;">
                                @csrf
                                <button type="submit" class="btn btn-success btn-sm">Approve</button>
                            </form>
                            <form action="{{ route('admin.payments.reject', $p) }}" method="POST" style="display:inline;">
                                @csrf
                                <button type="submit" class="btn btn-danger btn-sm">Reject</button>
                            </form>
                        @elseif ($p->status === 'pending')
                            <span style="font-size: 0.78rem; color: var(--text-muted);">Super Admin only</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" style="text-align: center; color: var(--text-muted); padding: 30px;">
                        No payments found.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div style="margin-top: 16px;">
    {{ $payments->links() }}
</div>
@endsection
