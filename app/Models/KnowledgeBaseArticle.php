<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class KnowledgeBaseArticle extends Model
{
    use HasFactory;

    protected $table = 'knowledge_base_articles';

    protected $fillable = [
        'title',
        'slug',
        'category_id',
        'subcategory_id',
        'tag',
        'summary',
        'body',
        'content_blocks',
        'attachments',
        'cover_image_path',
        'meta_title',
        'meta_description',
        'status',
        'icon',
        'tone',
        'views',
        'helpful_count',
        'published_at',
    ];

    protected $casts = [
        'views' => 'integer',
        'helpful_count' => 'integer',
        'published_at' => 'datetime',
        'content_blocks' => 'array',
        'attachments' => 'array',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(KnowledgeBaseCategory::class, 'category_id');
    }

    public function subcategory(): BelongsTo
    {
        return $this->belongsTo(KnowledgeBaseSubcategory::class, 'subcategory_id');
    }

    public function relatedArticles(): BelongsToMany
    {
        return $this->belongsToMany(
            KnowledgeBaseArticle::class,
            'knowledge_base_article_relations',
            'article_id',
            'related_article_id'
        )->withTimestamps();
    }
}
