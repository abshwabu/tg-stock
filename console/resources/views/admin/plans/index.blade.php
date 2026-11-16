@extends('layouts.admin')

@section('title', 'Plans')
@section('header_title', 'Commercial Plans (Database Configured)')

@section('content')
<div class="flex-between" style="margin-bottom: 20px;">
    <div>
        <h2 style="font-size: 1.3rem;">Subscription & License Plans</h2>
        <p style="color: var(--text-muted); font-size: 0.85rem;">Plans are stored as database records and can be extended without code changes.</p>
    </div>
    @if (auth()->user()?->isSuperAdmin())
        <a href="{{ route('admin.plans.create') }}" class="btn btn-primary">+ Create New Plan</a>
    @endif
</div>

<div class="card" style="padding: 0; overflow: hidden;">
    <table>
        <thead>
            <tr>
                <th>Plan Name</th>
                <th>Kind</th>
                <th>Duration</th>
                <th>Seats</th>
                <th>Enabled Features</th>
                <th>Price (ETB)</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($plans as $p)
                <tr>
                    <td><strong>{{ $p->name }}</strong></td>
                    <td><span class="badge badge-active">{{ ucfirst($p->kind) }}</span></td>
                    <td>{{ $p->duration_days > 0 ? $p->duration_days . ' days' : 'Lifetime' }}</td>
                    <td>{{ $p->seats }}</td>
                    <td>
                        <div style="display: flex; gap: 4px; flex-wrap: wrap;">
                            @foreach ($p->featureList() as $feat)
                                <span style="font-size: 0.72rem; background: var(--bg-hover); padding: 2px 6px; border-radius: 4px;">{{ $feat }}</span>
                            @endforeach
                        </div>
                    </td>
                    <td><strong>{{ number_format($p->price_etb, 2) }}</strong> ETB</td>
                    <td>
                        <span class="badge {{ $p->active ? 'badge-active' : 'badge-suspended' }}">
                            {{ $p->active ? 'Active' : 'Inactive' }}
                        </span>
                    </td>
                    <td>
                        @if (auth()->user()?->isSuperAdmin())
                            <a href="{{ route('admin.plans.edit', $p) }}" class="btn btn-secondary btn-sm">Edit</a>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
@endsection
