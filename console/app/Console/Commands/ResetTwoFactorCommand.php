<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Console\Command;

class ResetTwoFactorCommand extends Command
{
    protected $signature = 'admin:reset-2fa {email : The email address of the admin}';

    protected $description = 'Reset 2FA for an admin user so they can set up a new authenticator device';

    public function handle(): int
    {
        $email = strtolower(trim((string)$this->argument('email')));
        $user = User::where('email', $email)->first();

        if (!$user) {
            $this->error("No admin user found with email: {$email}");
            return 1;
        }

        $user->totp_secret = null;
        $user->totp_enabled_at = null;
        $user->backup_codes = null;
        $user->save();

        AuditLog::log('cli_admin_2fa_reset', 'User', (string)$user->id, [
            'email' => $user->email,
        ]);

        $this->info("2FA successfully reset for [{$user->email}].");
        $this->info("The user will be prompted to set up 2FA with a new QR code upon their next login.");

        return 0;
    }
}
