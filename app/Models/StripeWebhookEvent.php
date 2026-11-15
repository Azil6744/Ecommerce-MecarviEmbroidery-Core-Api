<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StripeWebhookEvent extends Model
{
    protected $fillable = ['event_id', 'type', 'status', 'attempts', 'last_error', 'payload', 'processed_at'];

    protected $casts = [
        'payload' => 'array',
        'processed_at' => 'datetime',
        'attempts' => 'integer',
    ];
}
