@extends('layouts.admin')

@section('title', 'Edit ' . $plan->name)
@section('header_title', 'Edit Commercial Plan')

@section('content')
<div style="max-width: 650px; margin: 0 auto;">
    <div class="card">
        <h2 style="font-size: 1.3rem; margin-bottom: 20px;">Edit Plan: {{ $plan->name }}</h2>

        <form action="{{ route('admin.plans.update', $plan) }}" method="POST">
            @csrf
            @method('PUT')

            <div class="form-group">
                <label for="name">Plan Name *</label>
                <input type="text" name="name" id="name" required value="{{ old('name', $plan->name) }}">
            </div>

            <div class="grid-2">
                <div class="form-group">
                    <label for="kind">Plan Kind *</label>
                    <select name="kind" id="kind" required>
                        <option value="monthly" {{ $plan->kind === 'monthly' ? 'selected' : '' }}>Monthly</option>
                        <option value="yearly" {{ $plan->kind === 'yearly' ? 'selected' : '' }}>Yearly</option>
                        <option value="lifetime" {{ $plan->kind === 'lifetime' ? 'selected' : '' }}>Lifetime</option>
                        <option value="trial" {{ $plan->kind === 'trial' ? 'selected' : '' }}>Trial</option>
                        <option value="custom" {{ $plan->kind === 'custom' ? 'selected' : '' }}>Custom</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="duration_days">Duration (Days) * (0 for lifetime)</label>
                    <input type="number" name="duration_days" id="duration_days" required value="{{ old('duration_days', $plan->duration_days) }}">
                </div>
            </div>

            <div class="grid-2">
                <div class="form-group">
                    <label for="seats">Seats (Active Terminals) *</label>
                    <input type="number" name="seats" id="seats" required value="{{ old('seats', $plan->seats) }}" min="1">
                </div>
                <div class="form-group">
                    <label for="grace_days">Grace Period (Days) *</label>
                    <input type="number" name="grace_days" id="grace_days" required value="{{ old('grace_days', $plan->grace_days) }}" min="0">
                </div>
            </div>

            <div class="form-group">
                <label for="price_etb">Price (ETB) *</label>
                <input type="number" step="0.01" name="price_etb" id="price_etb" required value="{{ old('price_etb', $plan->price_etb) }}">
            </div>

            <div class="form-group">
                <label>Enabled Optional Features (Bitmask)</label>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 8px;">
                    <label style="font-weight: normal; cursor: pointer;">
                        <input type="checkbox" name="features[]" value="1" {{ ($plan->features & 1) ? 'checked' : '' }}> Online Sync (bit0)
                    </label>
                    <label style="font-weight: normal; cursor: pointer;">
                        <input type="checkbox" name="features[]" value="2" {{ ($plan->features & 2) ? 'checked' : '' }}> Telegram Bot (bit1)
                    </label>
                    <label style="font-weight: normal; cursor: pointer;">
                        <input type="checkbox" name="features[]" value="4" {{ ($plan->features & 4) ? 'checked' : '' }}> Team Roles (bit2)
                    </label>
                    <label style="font-weight: normal; cursor: pointer;">
                        <input type="checkbox" name="features[]" value="8" {{ ($plan->features & 8) ? 'checked' : '' }}> Multi Location (bit3)
                    </label>
                    <label style="font-weight: normal; cursor: pointer;">
                        <input type="checkbox" name="features[]" value="16" {{ ($plan->features & 16) ? 'checked' : '' }}> Export PDF/Excel (bit4)
                    </label>
                </div>
            </div>

            <div class="form-group">
                <label style="cursor: pointer; display: flex; align-items: center; gap: 8px;">
                    <input type="checkbox" name="active" value="1" {{ $plan->active ? 'checked' : '' }}>
                    Plan is Active and selectable in wizard
                </label>
            </div>

            <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 24px;">
                <a href="{{ route('admin.plans.index') }}" class="btn btn-secondary">Cancel</a>
                <button type="submit" class="btn btn-primary">Update Plan</button>
            </div>
        </form>
    </div>
</div>
@endsection
