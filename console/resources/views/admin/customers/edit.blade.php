@extends('layouts.admin')

@section('title', 'Edit ' . $customer->name)
@section('header_title', 'Edit Customer')

@section('content')
<div style="max-width: 600px; margin: 0 auto;">
    <div class="card">
        <h2 style="font-size: 1.3rem; margin-bottom: 20px;">Edit Customer Details</h2>

        <form action="{{ route('admin.customers.update', $customer) }}" method="POST">
            @csrf
            @method('PUT')

            <div class="form-group">
                <label for="name">Customer Full Name *</label>
                <input type="text" name="name" id="name" required value="{{ old('name', $customer->name) }}">
            </div>

            <div class="form-group">
                <label for="phone">Phone Number *</label>
                <input type="text" name="phone" id="phone" required value="{{ old('phone', $customer->phone) }}">
            </div>

            <div class="form-group">
                <label for="telegram_id">Telegram Username / ID</label>
                <input type="text" name="telegram_id" id="telegram_id" value="{{ old('telegram_id', $customer->telegram_id) }}">
            </div>

            <div class="form-group">
                <label for="shop_name">Shop / Store Name</label>
                <input type="text" name="shop_name" id="shop_name" value="{{ old('shop_name', $customer->shop_name) }}">
            </div>

            <div class="form-group">
                <label for="notes">Internal Notes</label>
                <textarea name="notes" id="notes" rows="3">{{ old('notes', $customer->notes) }}</textarea>
            </div>

            <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 24px;">
                <a href="{{ route('admin.customers.show', $customer) }}" class="btn btn-secondary">Cancel</a>
                <button type="submit" class="btn btn-primary">Update Customer</button>
            </div>
        </form>
    </div>
</div>
@endsection
