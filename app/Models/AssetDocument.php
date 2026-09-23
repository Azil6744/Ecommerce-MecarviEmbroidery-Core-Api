<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AssetDocument extends Model
{
    use HasFactory;

    protected $table = 'asset_documents';

    protected $fillable = [
        'asset_id',
        'title',
        'file_name',
        'file_url',
        'file_type',
        'file_size',
        'category',
        'document_date',
        'description',
    ];

    protected $casts = [
        'document_date' => 'date:Y-m-d',
    ];

    public function asset()
    {
        return $this->belongsTo(CompanyAsset::class, 'asset_id');
    }
}
