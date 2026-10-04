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
        'mileage_tiers',
        'upcharge',
        'priority',
        'status',
        'availability',
        'cutoff_time',
        'is_default',
        'icon',
    ];

    protected $casts = [
        'pricing' => 'decimal:2',
        'upcharge' => 'decimal:2',
        'mileage_tiers' => 'array',
        'priority' => 'integer',
        'status' => 'boolean',
        'is_default' => 'boolean',
    ];
}
