<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Concerns\BelongsToWorkspaceOwner;
use Illuminate\Database\Eloquent\Model;

class AdminNoteCategory extends Model
{
    use HasFactory, BelongsToWorkspaceOwner;

    protected $table = 'admin_note_categories';

    protected $fillable = [
        'name',
        'color',
        'icon',
        'order_num',
    ];
}
