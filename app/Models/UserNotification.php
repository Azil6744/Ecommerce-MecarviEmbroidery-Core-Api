<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserNotification extends Model
{
    protected $fillable = ['user_id', 'category', 'type', 'title', 'body', 'link', 'data', 'dedupe_key', 'read_at'];

    protected $casts = [
        'data' => 'array',
        'read_at' => 'datetime',
    ];
}
