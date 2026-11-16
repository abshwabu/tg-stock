@extends('layouts.admin')

@section('title', 'Customers')
@section('header_title', 'Customer Directory')

@section('content')
<div class="flex-between" style="margin-bottom: 20px;">
    <div>
        <h2 style="font-size: 1.3rem;">Registered Shop Owners & Customers</h2>
        <p style="color: var(--text-muted); font-size: 0.85rem;">Browse customer accounts, contact details, and license ownership.</p>
    </div>
    <a href="{{ route('admin.customers.create') }}" class="btn btn-primary">+ Add Customer</a>
</div>

<!-- Search -->
<div class="card" style="padding: 16px;">
    <form action="{{ route('admin.customers.index') }}" method="GET" style="display: flex; gap: 12px;">
        <input type="text" name="search" value="{{ $search }}" placeholder="Search by name, phone, Telegram username, shop name..." style="flex: 1;">
        <button type="submit" class="btn btn-secondary">Search</button>
        @if ($search)
            <a href="{{ route('admin.customers.index') }}" class="btn btn-secondary">Clear</a>
        @endif
    </form>
</div>

<!-- Table -->
<div class="card" style="padding: 0; overflow: hidden;">
    <table>
        <thead>
            <tr>
                <th>Customer Name</th>
                <th>Phone</th>
                <th>Telegram ID</th>
                <th>Shop Name</th>
                <th>Active Licenses</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($customers as $c)
                <tr>
                    <td><strong><a href="{{ route('admin.customers.show', $c) }}" style="color: #60a5fa; text-decoration: none;">{{ $c->name }}</a></strong></td>
                    <td>{{ $c->phone }}</td>
                    <td>
                        @if ($c->telegram_id)
                            <code>@{{ $c->telegram_id }}</code>
                        @else
                            <span style="color: var(--text-muted);">-</span>
                        @endif
                    </td>
                    <td>{{ $c->shop_name ?? '-' }}</td>
                    <td>
                        <span class="badge badge-active">{{ $c->licenses_count }}</span>
                    </td>
                    <td>
                        <a href="{{ route('admin.customers.show', $c) }}" class="btn btn-secondary btn-sm">View</a>
                        <a href="{{ route('admin.licenses.wizard', ['customer_id' => $c->id]) }}" class="btn btn-primary btn-sm">+ Issue Token</a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 30px;">
                        No customers found.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div style="margin-top: 16px;">
    {{ $customers->links() }}
</div>
@endsection
