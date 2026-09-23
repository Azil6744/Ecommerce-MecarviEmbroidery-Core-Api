<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AssetSetting extends Model
{
    use HasFactory;

    protected $table = 'asset_settings';

    protected $fillable = [
        'category_type',
        'name',
        'dot_color',
        'contact_person',
        'phone',
        'email',
        'address',
        'extra_data',
        'sort_order',
    ];

    protected $casts = [
        'extra_data' => 'array',
        'sort_order' => 'integer',
    ];
}
