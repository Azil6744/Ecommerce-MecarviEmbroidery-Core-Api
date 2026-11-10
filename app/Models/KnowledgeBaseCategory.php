<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KnowledgeBaseCategory extends Model
{
    use HasFactory;

    protected $table = 'knowledge_base_categories';

    protected $fillable = [
        'name',
        'slug',
        'meta_title',
        'meta_description',
        'status',
        'icon_path',
        'cover_image_path',
        'icon',
        'tone',
        'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    public function articles(): HasMany
    {
        return $this->hasMany(KnowledgeBaseArticle::class, 'category_id');
    }

    public function subcategories(): HasMany
    {
        return $this->hasMany(KnowledgeBaseSubcategory::class, 'category_id');
    }
}
