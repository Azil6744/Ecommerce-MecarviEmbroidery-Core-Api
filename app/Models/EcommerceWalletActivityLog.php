<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EcommerceWalletActivityLog extends Model
{
    protected $fillable = ['user_id', 'kind', 'title', 'description', 'reference_id', 'performed_by', 'performed_by_id'];
}
