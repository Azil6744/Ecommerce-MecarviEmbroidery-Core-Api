<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class AdminFileFolder extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'parent_id', 'department_id', 'name', 'color', 'tone', 'image_path', 'created_by',
        'is_protected', 'password_hash', 'is_active', 'trash_root', 'trash_batch',
    ];

    protected $hidden = ['password_hash'];

    protected $casts = [
        'is_protected' => 'boolean',
        'is_active' => 'boolean',
        'trash_root' => 'boolean',
    ];

    public function files(): HasMany
    {
        return $this->hasMany(AdminFile::class, 'folder_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(FmTaxonomy::class, 'department_id');
    }
}
