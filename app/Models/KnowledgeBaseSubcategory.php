<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KnowledgeBaseSubcategory extends Model
{
    use HasFactory;

    protected $table = 'knowledge_base_subcategories';

    protected $fillable = [
        'category_id',
        'name',
        'slug',
        'meta_title',
        'meta_description',
        'display_order',
        'status',
        'icon_path',
    ];

    protected $casts = [
        'display_order' => 'integer',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(KnowledgeBaseCategory::class, 'category_id');
    }

    public function articles(): HasMany
    {
        return $this->hasMany(KnowledgeBaseArticle::class, 'subcategory_id');
    }
}
