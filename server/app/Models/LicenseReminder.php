<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LicenseReminder extends Model
{
    protected $table = 'license_reminders';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'id',
        'shop_id',
        'license_id',
        'milestone',
        'day',
        'sent_at',
    ];

    protected $casts = [
        'day' => 'integer',
        'sent_at' => 'datetime',
    ];
}
