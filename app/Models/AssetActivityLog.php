<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AssetActivityLog extends Model
{
    use HasFactory;

    protected $table = 'asset_activity_logs';

    protected $fillable = [
        'asset_id',
        'action_type',
        'title',
        'target_name',
        'before_value',
        'after_value',
        'location',
        'reference_number',
        'description',
        'performed_by',
    ];

    public function asset()
    {
        return $this->belongsTo(CompanyAsset::class, 'asset_id');
    }
}
