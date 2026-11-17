<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A File Manager department (kind = department) or category (kind = category). */
class FmTaxonomy extends Model
{
    protected $table = 'fm_taxonomies';

    protected $fillable = ['kind', 'name', 'icon_path', 'icon_key', 'tone', 'is_active', 'created_by'];

    protected $casts = ['is_active' => 'boolean'];
}
