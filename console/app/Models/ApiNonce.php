<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApiNonce extends Model
{
    public $timestamps = false;
    protected $primaryKey = 'nonce';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'nonce',
        'created_at',
    ];
}
