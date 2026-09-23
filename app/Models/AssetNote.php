<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AssetNote extends Model
{
    use HasFactory;

    protected $table = 'asset_notes';

    protected $fillable = [
        'asset_id',
        'author_name',
        'author_initials',
        'author_avatar_bg',
        'category',
        'note_text',
        'attachments',
        'created_by',
    ];

    protected $casts = [
        'attachments' => 'array',
    ];

    public function asset()
    {
        return $this->belongsTo(CompanyAsset::class, 'asset_id');
    }
}
