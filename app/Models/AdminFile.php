<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class AdminFile extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'folder_id', 'name', 'original_name', 'description', 'path', 'disk', 'size', 'mime_type', 'extension',
        'department_id', 'category_id', 'is_protected', 'password_hash', 'is_active',
        'uploaded_by', 'uploaded_by_name', 'trash_root', 'trash_batch',
    ];

    protected $hidden = ['password_hash'];

    protected $casts = [
        'size' => 'integer',
        'is_protected' => 'boolean',
        'is_active' => 'boolean',
        'trash_root' => 'boolean',
    ];

    public function folder(): BelongsTo
    {
        return $this->belongsTo(AdminFileFolder::class, 'folder_id')->withTrashed();
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(FmTaxonomy::class, 'department_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(FmTaxonomy::class, 'category_id');
    }
}
