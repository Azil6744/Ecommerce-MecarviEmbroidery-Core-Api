<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EcommerceVoucherLog extends Model
{
    protected $fillable = [
        'voucher_id',
        'log_type',
        'action',
        'details',
        'performed_by',
        'performed_by_id',
    ];

    public function voucher()
    {
        return $this->belongsTo(EcommerceVoucher::class, 'voucher_id');
    }
}
