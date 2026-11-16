<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\TotpService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(
        protected TotpService $totp
    ) {}

    public function showLogin()
    {
        if (Auth::check() && session('2fa_verified')) {
            return redirect()->route('admin.dashboard');
        }
        return view('auth.login');
    }

    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $throttleKey = 'login:' . $request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            AuditLog::log('login_throttled', 'Auth', null, [
                'email' => $request->input('email'),
                'seconds' => $seconds,
            ], $request);

            throw ValidationException::withMessages([
                'email' => ["Too many login attempts. Please try again in {$seconds} seconds."],
            ]);
        }

        if (!Auth::attempt($request->only('email', 'password'), false)) {
            RateLimiter::hit($throttleKey, 900); // 15-minute lock on 5 failures
            AuditLog::log('login_failed', 'Auth', null, [
                'email' => $request->input('email'),
            ], $request);

            throw ValidationException::withMessages([
                'email' => ['Invalid credentials.'],
            ]);
        }

        RateLimiter::clear($throttleKey);
        $user = Auth::user();

        AuditLog::log('login_password_success', 'User', (string)$user->id, [
            'email' => $user->email,
            'role' => $user->role,
        ], $request, $user);

        // Check if 2FA is already enabled
        if ($user->hasTwoFactorEnabled()) {
            session(['2fa_verified' => false]);
            return redirect()->route('2fa.challenge');
        }

        // If 2FA is not yet set up, enforce setup immediately
        return redirect()->route('2fa.setup');
    }

    public function showTwoFactorChallenge()
    {
        if (!Auth::check()) {
            return redirect()->route('login');
        }
        return view('auth.two-factor-challenge');
    }

    public function verifyTwoFactor(Request $request)
    {
        if (!Auth::check()) {
            return redirect()->route('login');
        }

        $user = Auth::user();
        $code = $request->input('code');
        $backupCode = $request->input('backup_code');

        if (!empty($backupCode)) {
            $backupCodes = $user->backup_codes ?? [];
            if ($this->totp->verifyAndConsumeBackupCode($backupCodes, $backupCode)) {
                $user->backup_codes = $backupCodes;
                $user->save();

                session(['2fa_verified' => true]);
                AuditLog::log('2fa_backup_code_used', 'User', (string)$user->id, [], $request, $user);

                return redirect()->intended(route('admin.dashboard'));
            }

            AuditLog::log('2fa_backup_code_failed', 'User', (string)$user->id, [], $request, $user);
            return back()->withErrors(['backup_code' => 'Invalid or already used backup code.']);
        }

        $request->validate(['code' => 'required|string']);

        if (!$user->totp_secret || !$this->totp->verify($user->totp_secret, $code)) {
            AuditLog::log('2fa_code_failed', 'User', (string)$user->id, [], $request, $user);
            return back()->withErrors(['code' => 'Invalid two-factor authentication code.']);
        }

        session(['2fa_verified' => true]);
        AuditLog::log('2fa_verified', 'User', (string)$user->id, [], $request, $user);

        return redirect()->intended(route('admin.dashboard'));
    }

    public function showTwoFactorSetup()
    {
        if (!Auth::check()) {
            return redirect()->route('login');
        }

        $user = Auth::user();
        $secret = session('setup_totp_secret');
        $backupCodes = session('setup_backup_codes');

        if (!$secret || !$backupCodes) {
            $secret = $this->totp->generateSecret();
            $backupCodes = $this->totp->generateBackupCodes(8);
            session([
                'setup_totp_secret' => $secret,
                'setup_backup_codes' => $backupCodes,
            ]);
        }

        $qrUri = $this->totp->getOtpAuthUrl('TGStock-Console', $user->email, $secret);

        return view('auth.two-factor-setup', [
            'secret' => $secret,
            'qrUri' => $qrUri,
            'backupCodes' => $backupCodes['plain'],
        ]);
    }

    public function confirmTwoFactorSetup(Request $request)
    {
        if (!Auth::check()) {
            return redirect()->route('login');
        }

        $request->validate(['code' => 'required|string']);

        $secret = session('setup_totp_secret');
        $backupCodes = session('setup_backup_codes');

        if (!$secret || !$backupCodes) {
            return redirect()->route('2fa.setup')->withErrors(['code' => 'Setup session expired. Please retry.']);
        }

        if (!$this->totp->verify($secret, $request->input('code'))) {
            return back()->withErrors(['code' => 'Verification code did not match. Please check device time and retry.']);
        }

        $user = Auth::user();
        $user->totp_secret = $secret;
        $user->totp_enabled_at = now();
        $user->backup_codes = $backupCodes['hashed'];
        $user->save();

        session()->forget(['setup_totp_secret', 'setup_backup_codes']);
        session(['2fa_verified' => true]);

        AuditLog::log('2fa_enabled', 'User', (string)$user->id, [], $request, $user);

        return redirect()->route('admin.dashboard')->with('success', 'Two-Factor Authentication successfully enabled!');
    }

    public function logout(Request $request)
    {
        $userId = Auth::id();
        AuditLog::log('logout', 'User', (string)$userId, [], $request);

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
