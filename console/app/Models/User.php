<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'totp_secret',
        'totp_enabled_at',
        'backup_codes',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'totp_secret',
        'backup_codes',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'totp_secret' => 'encrypted',
            'totp_enabled_at' => 'datetime',
            'backup_codes' => 'array',
        ];
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === 'super_admin';
    }

    public function isSupport(): bool
    {
        return $this->role === 'support';
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->totp_enabled_at !== null && !empty($this->totp_secret);
    }
}
