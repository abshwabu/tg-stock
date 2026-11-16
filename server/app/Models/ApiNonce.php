<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApiNonce extends Model
{
    protected $table = 'api_nonces';
    protected $primaryKey = 'nonce';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'nonce',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];
}
