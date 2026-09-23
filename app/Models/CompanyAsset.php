<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CompanyAsset extends Model
{
    use HasFactory;

    protected $table = 'company_assets';

    protected $fillable = [
        'asset_tag',
        'name',
        'category',
        'brand',
        'model',
        'serial_number',
        'purchase_date',
        'condition',
        'status',
        'purchase_type',
        'manufacturer',
        'location',
        'department',
        'assigned_to',
        'purchase_cost',
        'invoice_number',
        'invoice_date',
        'vendor_name_address',
        'receipt_url',
        'warranty_status',
        'warranty_provider',
        'warranty_start_date',
        'warranty_expiration_date',
        'warranty_reference_number',
        'description',
        'images',
        'documents',
        'has_maintenance_schedule',
        'service_type',
        'service_frequency',
        'next_service_date',
        'notes',
    ];

    protected $casts = [
        'images' => 'array',
        'documents' => 'array',
        'has_maintenance_schedule' => 'boolean',
        'purchase_cost' => 'decimal:2',
        'purchase_date' => 'date:Y-m-d',
        'invoice_date' => 'date:Y-m-d',
        'warranty_start_date' => 'date:Y-m-d',
        'warranty_expiration_date' => 'date:Y-m-d',
        'next_service_date' => 'date:Y-m-d',
    ];

    public function activityLogs()
    {
        return $this->hasMany(AssetActivityLog::class, 'asset_id');
    }

    public function maintenanceRecords()
    {
        return $this->hasMany(AssetMaintenanceRecord::class, 'asset_id');
    }

    public function financialRecords()
    {
        return $this->hasMany(AssetFinancialRecord::class, 'asset_id');
    }

    public function notesList()
    {
        return $this->hasMany(AssetNote::class, 'asset_id')->orderBy('created_at', 'desc');
    }
}
