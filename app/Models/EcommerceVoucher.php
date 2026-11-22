<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class EcommerceVoucher extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_REDEEMED = 'redeemed';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_VOIDED = 'voided';

    protected $fillable = [
        'code',
        'user_id',
        'refunded_from_voucher_id',
        'holder_type',
        'holder_name',
        'holder_email',
        'amount',
        'balance',
        'status',
        'usage_type',
        'issued_at',
        'expires_at',
        'currency',
        'notes',
        'void_reason',
        'issued_by_admin_id',
        'last_used_at',
        'holder_phone',
        'contact_person',
        'order_number',
        'issue_category',
        'issue_summary',
        'compensation_reason',
        'delivery_methods',
        'case_id',
        'scheduled_void_at',
        'void_notes',
        'issued_by_name',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'balance' => 'decimal:2',
        'issued_at' => 'date:Y-m-d',
        'expires_at' => 'date:Y-m-d',
        'scheduled_void_at' => 'date:Y-m-d',
        'last_used_at' => 'datetime',
        'user_id' => 'integer',
        'issued_by_admin_id' => 'integer',
        'refunded_from_voucher_id' => 'integer',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function (EcommerceVoucher $voucher) {
            if (empty($voucher->code)) {
                $voucher->code = static::generateCode();
            }
        });
    }

    /** Generates codes like ME-34F3K-T1220-33 using the prefix/format from Voucher Settings. */
    public static function generateCode(): string
    {
        $settings = EcommerceVoucherSetting::current();
        $prefix = $settings['code_prefix'] ?: 'ME';
        $pattern = $settings['code_format'] ?: 'XXXXX-XXXXX-XX';
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ0123456789';

        do {
            $body = '';
            foreach (str_split($pattern) as $char) {
                $body .= $char === 'X' ? $alphabet[random_int(0, strlen($alphabet) - 1)] : $char;
            }
            $code = $prefix . '-' . $body;
        } while (static::where('code', $code)->exists());

        return $code;
    }

    public static function nextCaseId(): string
    {
        $prefix = EcommerceVoucherSetting::current()['code_prefix'] ?: 'ME';

        return sprintf('%s-%06d', $prefix, ((int) static::max('id')) + 1);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** The original voucher whose order refund created this voucher. */
    public function refundedFrom()
    {
        return $this->belongsTo(self::class, 'refunded_from_voucher_id');
    }

    /** New vouchers issued when orders paid with this voucher were refunded. */
    public function refundVouchers()
    {
        return $this->hasMany(self::class, 'refunded_from_voucher_id')->orderBy('id');
    }

    public function logs()
    {
        return $this->hasMany(EcommerceVoucherLog::class, 'voucher_id')->orderBy('created_at')->orderBy('id');
    }
}
