<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderIntake extends Model
{
    protected $table = 'order_intakes';

    protected $fillable = [
        'order_number',
        'drop_off_date',
        'pick_up_date',
        'status',
        'accent_color',
        'assigned_staff',
        'priority_status',
        'customer',
        'items',
        'drop_off',
        'pick_up',
        'internal_notes',
    ];

    protected $casts = [
        'customer' => 'array',
        'items' => 'array',
        'drop_off' => 'array',
        'pick_up' => 'array',
    ];
}
