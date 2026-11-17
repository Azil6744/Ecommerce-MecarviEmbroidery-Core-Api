<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FmActivity extends Model
{
    protected $table = 'fm_activities';

    protected $fillable = ['actor_id', 'actor_name', 'action', 'entity_type', 'entity_id', 'entity_name'];
}
