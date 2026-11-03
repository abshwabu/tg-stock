<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Change extends Model
{
    use HasFactory, BelongsToShop;

    protected $table = 'changes';
    protected $primaryKey = 'seq';
    public $timestamps = false;

    protected $fillable = [
        'seq',
        'shop_id',
        'device_id',
        'entity',
        'entity_id',
        'created_at',
    ];

    protected $casts = [
        'seq' => 'integer',
        'created_at' => 'datetime',
    ];
}
