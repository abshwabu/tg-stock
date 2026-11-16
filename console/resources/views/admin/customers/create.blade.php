@extends('layouts.admin')

@section('title', 'Add Customer')
@section('header_title', 'Create Customer Profile')

@section('content')
<div style="max-width: 600px; margin: 0 auto;">
    <div class="card">
        <h2 style="font-size: 1.3rem; margin-bottom: 20px;">New Customer</h2>

        <form action="{{ route('admin.customers.store') }}" method="POST">
            @csrf

            <div class="form-group">
                <label for="name">Customer Full Name *</label>
                <input type="text" name="name" id="name" required value="{{ old('name') }}" placeholder="e.g. Abebe Kebede">
            </div>

            <div class="form-group">
                <label for="phone">Phone Number *</label>
                <input type="text" name="phone" id="phone" required value="{{ old('phone') }}" placeholder="0911223344">
            </div>

            <div class="form-group">
                <label for="telegram_id">Telegram Username / ID</label>
                <input type="text" name="telegram_id" id="telegram_id" value="{{ old('telegram_id') }}" placeholder="e.g. abebe_pos">
            </div>

            <div class="form-group">
                <label for="shop_name">Shop / Store Name</label>
                <input type="text" name="shop_name" id="shop_name" value="{{ old('shop_name') }}" placeholder="e.g. Merkato Grocery #2">
            </div>

            <div class="form-group">
                <label for="notes">Internal Notes</label>
                <textarea name="notes" id="notes" rows="3">{{ old('notes') }}</textarea>
            </div>

            <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 24px;">
                <a href="{{ route('admin.customers.index') }}" class="btn btn-secondary">Cancel</a>
                <button type="submit" class="btn btn-primary">Save Customer</button>
            </div>
        </form>
    </div>
</div>
@endsection
