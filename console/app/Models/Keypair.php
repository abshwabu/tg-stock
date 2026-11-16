<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Keypair extends Model
{
    use HasFactory;

    protected $primaryKey = 'kid';
    public $incrementing = false;
    protected $keyType = 'int';

    protected $fillable = [
        'kid',
        'public_key_hex',
        'encrypted_secret_key',
        'fingerprint',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'kid' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
