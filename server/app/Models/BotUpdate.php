<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BotUpdate extends Model
{
    public $timestamps = false;
    public $incrementing = false;
    protected $primaryKey = 'update_id';
    protected $keyType = 'int';

    protected $fillable = [
        'update_id',
        'created_at',
    ];

    protected $casts = [
        'update_id' => 'integer',
        'created_at' => 'datetime',
    ];
}
