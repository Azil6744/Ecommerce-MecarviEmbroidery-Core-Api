<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminFile extends Model
{
    protected $fillable = ['folder_id', 'name', 'path', 'disk', 'size', 'mime_type', 'extension', 'uploaded_by'];

    protected $casts = [
        'size' => 'integer',
    ];

    public function folder(): BelongsTo
    {
        return $this->belongsTo(AdminFileFolder::class, 'folder_id');
    }
}
