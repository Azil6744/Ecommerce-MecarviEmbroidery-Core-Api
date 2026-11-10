<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KnowledgeBaseBanner extends Model
{
    use HasFactory;

    protected $table = 'knowledge_base_banners';

    /** Page keys for the three public Knowledge Base pages. */
    public const PAGE_KEYS = ['hub', 'topics', 'article'];

    protected $fillable = [
        'page_key',
        'eyebrow',
        'title',
        'highlight',
        'description',
        'search_placeholder',
        'image_path',
        'features',
        'is_active',
    ];

    protected $casts = [
        'features' => 'array',
        'is_active' => 'boolean',
    ];
}
