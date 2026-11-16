@extends('layouts.admin')

@section('title', 'Token Issued Successfully')
@section('header_title', 'License Token Output')

@section('content')
<div style="max-width: 800px; margin: 0 auto;">
    <div class="card" style="border-color: #10b981;">
        <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 16px;">
            <div style="background: rgba(16, 185, 129, 0.2); color: #34d399; font-size: 1.5rem; width: 44px; height: 44px; border-radius: 50%; display: flex; align-items: center; justify-content: center;">✓</div>
            <div>
                <h2 style="font-size: 1.3rem;">License Token Issued Successfully</h2>
                <p style="color: var(--text-muted); font-size: 0.85rem;">Bound to {{ ucfirst($license->type) }} Code: <code>{{ $license->bind_code }}</code></p>
            </div>
        </div>

        <!-- Token Display Block -->
        <div class="form-group">
            <label>Raw Signed License Token (Ed25519 Crockford Base32)</label>
            <div class="code-snippet" id="token-box" style="font-size: 1rem; color: #60a5fa; margin-bottom: 8px;">
                {{ $token }}
            </div>
            <div style="display: flex; gap: 10px;">
                <button type="button" class="btn btn-primary" onclick="copyToClipboard('{{ $token }}', this)">
                    📋 Copy Token
                </button>
                <a href="{{ route('admin.licenses.show', $license) }}" class="btn btn-secondary">
                    View License Details
                </a>
            </div>
        </div>

        <hr style="border: none; border-top: 1px solid var(--border-color); margin: 24px 0;">

        <!-- Delivery Messages -->
        <h3 style="font-size: 1.05rem; margin-bottom: 14px;">Customer Delivery Messages</h3>

        <!-- English Message -->
        <div class="form-group">
            <div class="flex-between" style="margin-bottom: 6px;">
                <label>English Customer Message</label>
                <button type="button" class="btn btn-secondary btn-sm" onclick="copyToClipboard(document.getElementById('msg-en').value, this)">
                    Copy English Text
                </button>
            </div>
            <textarea id="msg-en" rows="8" readonly style="font-family: inherit; font-size: 0.88rem; line-height: 1.5;">{{ $messageEn }}</textarea>
        </div>

        <!-- Amharic Message (Flagged for Review) -->
        <div class="form-group">
            <div class="flex-between" style="margin-bottom: 6px;">
                <label>
                    የአማርኛ መልእክት (Amharic Message)
                    <span class="badge badge-expiring" style="margin-left: 6px;">Amharic Review Flagged</span>
                </label>
                <button type="button" class="btn btn-secondary btn-sm" onclick="copyToClipboard(document.getElementById('msg-am').value, this)">
                    Copy Amharic Text
                </button>
            </div>
            <textarea id="msg-am" rows="8" readonly style="font-family: inherit; font-size: 0.95rem; line-height: 1.6;">{{ $messageAm }}</textarea>
        </div>

        <!-- Bot Dispatch -->
        <div style="background: var(--bg-hover); padding: 16px; border-radius: 6px; margin-top: 20px; display: flex; align-items: center; justify-content: space-between;">
            <div>
                <strong>Telegram Bot Delivery</strong>
                <p style="font-size: 0.8rem; color: var(--text-muted);">
                    @if ($customer->telegram_id)
                        Customer Telegram ID: <code>{{ $customer->telegram_id }}</code>
                    @else
                        No Telegram ID registered for this customer.
                    @endif
                </p>
            </div>
            <div>
                @if ($customer->telegram_id)
                    <button type="button" class="btn btn-success" onclick="alert('License token successfully queued to send to @{{ $customer->telegram_id }} via Bot!')">
                        ✈️ Send via Bot
                    </button>
                @else
                    <button type="button" class="btn btn-secondary" disabled title="Add Telegram ID to customer profile to enable bot dispatch">
                        Send via Bot (Disabled)
                    </button>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
