<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A File Manager department (kind = department) or category (kind = category). */
class FmTaxonomy extends Model
{
    protected $table = 'fm_taxonomies';

    protected $fillable = ['kind', 'parent_id', 'name', 'icon_path', 'icon_key', 'tone', 'is_active', 'created_by'];

    protected $casts = ['is_active' => 'boolean'];

    public function department(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }
}
