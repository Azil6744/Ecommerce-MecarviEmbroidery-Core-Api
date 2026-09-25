<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DeliveryTime extends Model
{
    use HasFactory;

    protected $table = 'delivery_times';

    protected $fillable = [
        'label',
        'estimated_days',
        'description',
        'color_code',
        'pricing',
        'priority',
        'status',
        'availability',
        'cutoff_time',
        'is_default',
        'icon',
    ];

    protected $casts = [
        'pricing' => 'decimal:2',
        'priority' => 'integer',
        'status' => 'boolean',
        'is_default' => 'boolean',
    ];
}
