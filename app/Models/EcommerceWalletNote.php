<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EcommerceWalletNote extends Model
{
    protected $fillable = ['user_id', 'parent_id', 'author_id', 'author_name', 'body'];

    public function replies()
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('id');
    }
}
