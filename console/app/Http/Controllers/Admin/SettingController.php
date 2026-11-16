<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Models\AuditLog;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class SettingController extends Controller
{
    public function index()
    {
        if (!auth()->user()->isSuperAdmin()) {
            abort(403, 'Unauthorized. Super Admin role required.');
        }

        return view('admin.settings.index', [
            'ipAllowlist' => Setting::get('ip_allowlist', ''),
            'autoIssue' => (bool)Setting::get('auto_issue_on_payment_approval', true),
            'reissueLimit' => (int)Setting::get('reissue_yearly_warning_limit', 2),
            'botToken' => Setting::get('telegram_bot_token', config('license.telegram_bot_token', '')),
            'botUsername' => Setting::get('telegram_bot_username', 'bina_stock_bot'),
        ]);
    }

    public function update(Request $request)
    {
        if (!auth()->user()->isSuperAdmin()) {
            abort(403, 'Unauthorized. Super Admin role required.');
        }

        $validated = $request->validate([
            'ip_allowlist' => 'nullable|string',
            'auto_issue_on_payment_approval' => 'nullable|boolean',
            'reissue_yearly_warning_limit' => 'required|integer|min:1',
            'telegram_bot_token' => 'nullable|string',
            'telegram_bot_username' => 'nullable|string',
        ]);

        Setting::set('ip_allowlist', $validated['ip_allowlist'] ?? '', 'Admin IP allowlist');
        Setting::set('auto_issue_on_payment_approval', $request->boolean('auto_issue_on_payment_approval') ? '1' : '0', 'Auto issue token on payment approval');
        Setting::set('reissue_yearly_warning_limit', (string)$validated['reissue_yearly_warning_limit'], 'Yearly reissue warning threshold');
        Setting::set('telegram_bot_token', $validated['telegram_bot_token'] ?? '', 'Telegram bot token for customer notifications');
        Setting::set('telegram_bot_username', $validated['telegram_bot_username'] ?? '', 'Telegram bot username');

        AuditLog::log('settings_updated', 'Setting', 'all', [
            'ip_allowlist' => !empty($validated['ip_allowlist']),
            'auto_issue' => $request->boolean('auto_issue_on_payment_approval'),
        ]);

        return back()->with('success', 'Settings updated successfully.');
    }
}
