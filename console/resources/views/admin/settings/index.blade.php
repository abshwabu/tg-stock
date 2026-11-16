@extends('layouts.admin')

@section('title', 'Settings')
@section('header_title', 'System & Security Settings')

@section('content')
<div style="max-width: 700px; margin: 0 auto;">
    <div class="card">
        <h2 style="font-size: 1.3rem; margin-bottom: 20px;">Console Configuration</h2>

        <form action="{{ route('admin.settings.update') }}" method="POST">
            @csrf

            <!-- Security: IP Allowlist -->
            <div style="border-bottom: 1px solid var(--border-color); padding-bottom: 20px; margin-bottom: 20px;">
                <h3 style="font-size: 1.05rem; color: #60a5fa; margin-bottom: 8px;">IP Address Restriction</h3>
                <p style="color: var(--text-muted); font-size: 0.85rem; margin-bottom: 12px;">
                    Optionally restrict admin panel access to specific IP addresses (comma or newline separated). Leave blank to allow access from any IP.
                </p>
                <div class="form-group">
                    <label for="ip_allowlist">Allowed IP Addresses</label>
                    <textarea name="ip_allowlist" id="ip_allowlist" rows="3" placeholder="e.g. 197.156.100.5, 127.0.0.1">{{ old('ip_allowlist', $ipAllowlist) }}</textarea>
                    <p style="font-size: 0.78rem; color: var(--text-muted); margin-top: 4px;">Your current IP: <code>{{ request()->ip() }}</code></p>
                </div>
            </div>

            <!-- Operations: Automation & Rules -->
            <div style="border-bottom: 1px solid var(--border-color); padding-bottom: 20px; margin-bottom: 20px;">
                <h3 style="font-size: 1.05rem; color: #60a5fa; margin-bottom: 8px;">Automation & Anti-Piracy Limits</h3>
                
                <div class="form-group">
                    <label style="cursor: pointer; display: flex; align-items: center; gap: 8px;">
                        <input type="checkbox" name="auto_issue_on_payment_approval" value="1" {{ $autoIssue ? 'checked' : '' }}>
                        Auto-issue license token when approving a payment request
                    </label>
                </div>

                <div class="form-group">
                    <label for="reissue_yearly_warning_limit">Yearly Device Reissue Warning Threshold</label>
                    <input type="number" name="reissue_yearly_warning_limit" id="reissue_yearly_warning_limit" value="{{ old('reissue_yearly_warning_limit', $reissueLimit) }}" min="1" max="10">
                    <p style="font-size: 0.78rem; color: var(--text-muted); margin-top: 4px;">Warn admins if a customer requests device replacement more than this number of times per rolling 365 days.</p>
                </div>
            </div>

            <!-- Telegram Bot Integration -->
            <div style="margin-bottom: 24px;">
                <h3 style="font-size: 1.05rem; color: #60a5fa; margin-bottom: 8px;">Telegram Bot Credentials</h3>
                <div class="form-group">
                    <label for="telegram_bot_token">Bot Token</label>
                    <input type="text" name="telegram_bot_token" id="telegram_bot_token" value="{{ old('telegram_bot_token', $botToken) }}" placeholder="123456789:ABCdefGHIjklMNOpqrsTUVwxyz">
                </div>
                <div class="form-group">
                    <label for="telegram_bot_username">Bot Username</label>
                    <input type="text" name="telegram_bot_username" id="telegram_bot_username" value="{{ old('telegram_bot_username', $botUsername) }}" placeholder="tgstock_license_bot">
                </div>
            </div>

            <div style="display: flex; justify-content: flex-end;">
                <button type="submit" class="btn btn-primary">Save Settings</button>
            </div>
        </form>
    </div>
</div>
@endsection
