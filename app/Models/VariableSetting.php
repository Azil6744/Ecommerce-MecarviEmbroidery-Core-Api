<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VariableSetting extends Model
{
    use HasFactory;

    protected $table = 'variable_settings';

    protected $fillable = [
        'group',
        'type',
        'name',
        'parent_name',
        'head_name',
        'head_initials',
        'icon',
        'color',
        'address',
        'country',
        'sub_department',
        'time_zone',
        'working_days',
        'schedule_periods',
        'hours_per_day',
        'extra_data',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'working_days' => 'array',
        'schedule_periods' => 'array',
        'extra_data' => 'array',
        'hours_per_day' => 'float',
        'is_active' => 'boolean',
    ];
}
