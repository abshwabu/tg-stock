<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BotState extends Model
{
    public $incrementing = false;
    protected $primaryKey = 'chat_id';
    protected $keyType = 'int';

    protected $fillable = [
        'chat_id',
        'flow',
        'step',
        'data',
        'expires_at',
    ];

    protected $casts = [
        'chat_id' => 'integer',
        'data' => 'array',
        'expires_at' => 'datetime',
    ];

    /**
     * Check if this conversation state has expired (>30m).
     */
    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
