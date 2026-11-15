<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AdminFileFolder extends Model
{
    protected $fillable = ['name', 'color', 'created_by'];

    public function files(): HasMany
    {
        return $this->hasMany(AdminFile::class, 'folder_id');
    }
}
