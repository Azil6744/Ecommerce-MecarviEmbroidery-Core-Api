<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AssetMaintenanceRecord extends Model
{
    use HasFactory;

    protected $table = 'asset_maintenance_records';

    protected $fillable = [
        'asset_id',
        'record_type',
        'maintenance_type',
        'maintenance_title',
        'service_type',
        'description',
        'status',
        'assigned_to',
        'service_provider',
        'contact_person',
        'phone',
        'email',
        'frequency',
        'start_date',
        'next_service_date',
        'provider_type',
        'technician_name',
        'invoice_number',
        'service_date',
        'completed_at',
        'cost',
        'parts_cost',
        'labor_cost',
        'travel_cost',
        'other_cost',
        'documents',
        'notes',
    ];

    protected $casts = [
        'service_date' => 'date:Y-m-d',
        'start_date' => 'date:Y-m-d',
        'next_service_date' => 'date:Y-m-d',
        'completed_at' => 'datetime',
        'cost' => 'decimal:2',
        'parts_cost' => 'decimal:2',
        'labor_cost' => 'decimal:2',
        'travel_cost' => 'decimal:2',
        'other_cost' => 'decimal:2',
        'documents' => 'array',
    ];

    public function asset()
    {
        return $this->belongsTo(CompanyAsset::class, 'asset_id');
    }
}
