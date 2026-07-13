<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebhookLog extends Model
{
    //
    protected $fillable = [
        'provider',
        'event_id',
        'event_type',
        'payload',
        'processed_at',
        'attempts',
        'last_error',
    ];

    protected $casts = [
        'payload' => 'array',
        'processed_at' => 'datetime',
        'attempts' => 'integer',
        'last_error' => 'string',
    ];

}
