@extends('layouts.admin')

@section('title', 'Record Payment')
@section('header_title', 'Record Manual Customer Payment')

@section('content')
<div style="max-width: 600px; margin: 0 auto;">
    <div class="card">
        <h2 style="font-size: 1.3rem; margin-bottom: 20px;">Manual Payment Entry</h2>

        <form action="{{ route('admin.payments.store') }}" method="POST" enctype="multipart/form-data">
            @csrf

            <div class="form-group">
                <label for="customer_id">Select Customer *</label>
                <select name="customer_id" id="customer_id" required>
                    <option value="">-- Choose Customer --</option>
                    @foreach ($customers as $c)
                        <option value="{{ $c->id }}" {{ old('customer_id') == $c->id ? 'selected' : '' }}>
                            {{ $c->name }} ({{ $c->phone }}) {{ $c->shop_name ? '- ' . $c->shop_name : '' }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="grid-2">
                <div class="form-group">
                    <label for="amount">Amount (ETB) *</label>
                    <input type="number" step="0.01" name="amount" id="amount" required value="{{ old('amount', '500.00') }}">
                </div>
                <div class="form-group">
                    <label for="method">Payment Method *</label>
                    <select name="method" id="method" required>
                        <option value="telebirr">Telebirr</option>
                        <option value="cbe">CBE Birr / CBE</option>
                        <option value="bank">Bank Transfer</option>
                        <option value="cash">Cash</option>
                        <option value="other">Other</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label for="reference">Transaction Reference / Receipt ID</label>
                <input type="text" name="reference" id="reference" value="{{ old('reference') }}" placeholder="e.g. TB99887766">
            </div>

            <div class="form-group">
                <label for="proof_file">Proof of Payment Upload (Image / PDF, max 10MB)</label>
                <input type="file" name="proof_file" id="proof_file" accept=".jpg,.jpeg,.png,.pdf">
            </div>

            <div class="form-group">
                <label for="notes">Notes / Memo</label>
                <textarea name="notes" id="notes" rows="3">{{ old('notes') }}</textarea>
            </div>

            <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 24px;">
                <a href="{{ route('admin.payments.index') }}" class="btn btn-secondary">Cancel</a>
                <button type="submit" class="btn btn-primary">Record Payment</button>
            </div>
        </form>
    </div>
</div>
@endsection
