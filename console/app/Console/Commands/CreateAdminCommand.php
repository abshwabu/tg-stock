<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class CreateAdminCommand extends Command
{
    protected $signature = 'admin:create 
                            {email? : The admin email address} 
                            {--name= : The admin full name} 
                            {--role=super_admin : The admin role (super_admin or support)} 
                            {--password= : The initial password}';

    protected $description = 'Create a new admin user for the License Console';

    public function handle(): int
    {
        $email = $this->argument('email') ?: $this->ask('Enter admin email');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Invalid email format.');
            return 1;
        }

        $name = $this->option('name') ?: $this->ask('Enter admin name', 'Admin');
        $role = $this->option('role');
        if (!in_array($role, ['super_admin', 'support'], true)) {
            $role = $this->choice('Select role', ['super_admin', 'support'], 0);
        }

        $password = $this->option('password') ?: $this->secret('Enter admin password');
        if (strlen($password) < 8) {
            $this->error('Password must be at least 8 characters long.');
            return 1;
        }

        $user = User::updateOrCreate(
            ['email' => strtolower(trim($email))],
            [
                'name' => trim($name),
                'role' => $role,
                'password' => Hash::make($password),
                'totp_secret' => null,
                'totp_enabled_at' => null,
                'backup_codes' => null,
            ]
        );

        AuditLog::log('cli_admin_created', 'User', (string)$user->id, [
            'email' => $user->email,
            'role' => $user->role,
        ]);

        $this->info("Admin user [{$user->email}] successfully created/updated with role [{$user->role}].");
        $this->info("2FA will be configured on first login via QR code scanning.");

        return 0;
    }
}
