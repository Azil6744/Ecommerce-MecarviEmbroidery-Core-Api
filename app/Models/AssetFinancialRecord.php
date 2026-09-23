<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AssetFinancialRecord extends Model
{
    use HasFactory;

    protected $table = 'asset_financial_records';

    protected $fillable = [
        'asset_id',
        'expense_type',
        'description',
        'invoice_number',
        'vendor_name',
        'amount',
        'payment_method',
        'reference_number',
        'is_recurring',
        'expense_date',
        'receipt_url',
        'attachment_name',
        'attachment_size',
    ];

    protected $casts = [
        'expense_date' => 'date:Y-m-d',
        'amount' => 'decimal:2',
        'is_recurring' => 'boolean',
    ];

    public function asset()
    {
        return $this->belongsTo(CompanyAsset::class, 'asset_id');
    }
}
