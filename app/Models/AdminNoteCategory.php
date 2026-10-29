<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AdminNoteCategory extends Model
{
    use HasFactory;

    protected $table = 'admin_note_categories';

    protected $fillable = [
        'name',
        'color',
        'icon',
        'order_num',
    ];
}
