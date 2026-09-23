<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('company_assets', function (Blueprint $table) {
            $table->id();
            $table->string('asset_tag')->unique(); // e.g. ASSET-001
            $table->string('name'); // e.g. Tajima Embroidery Machine
            $table->string('category'); // e.g. Production Equipment, Computers & IT, Office Equipment, Furniture & Fixtures, Other Equipment
            $table->string('brand')->nullable(); // e.g. Tajima, Dell, HP
            $table->string('model')->nullable(); // e.g. TMAR-K1506C
            $table->string('serial_number')->nullable();
            $table->date('purchase_date')->nullable();
            $table->string('condition')->default('New'); // New, Good, Fair, Poor
            $table->string('status')->default('in_use'); // in_use, under_maintenance, out_of_service, draft, disposed
            $table->string('purchase_type')->default('Purchased'); // Purchased, Leased, Rented
            $table->string('manufacturer')->nullable();
            $table->string('location')->nullable(); // Brooklyn, NY, Forest Park, GA, etc.
            $table->string('department')->nullable(); // Production, IT, Administration, Maintenance
            $table->string('assigned_to')->nullable(); // Production Team, Design Team, etc.
            $table->decimal('purchase_cost', 12, 2)->default(0.00);
            $table->string('invoice_number')->nullable();
            $table->date('invoice_date')->nullable();
            $table->text('vendor_name_address')->nullable();
            $table->string('receipt_url')->nullable();
            $table->string('warranty_status')->default('Active'); // Active, Expired, Not Applicable
            $table->string('warranty_provider')->nullable();
            $table->date('warranty_start_date')->nullable();
            $table->date('warranty_expiration_date')->nullable();
            $table->string('warranty_reference_number')->nullable();
            $table->text('description')->nullable();
            $table->json('images')->nullable(); // Array of image URLs
            $table->json('documents')->nullable(); // Array of documents [{name, size, type, url}]
            $table->boolean('has_maintenance_schedule')->default(false);
            $table->string('service_type')->nullable(); // Preventive Maintenance, Routine Inspection, Calibration, Repair
            $table->string('service_frequency')->nullable(); // Every Month, Every 3 Months, Every 6 Months, Every Year
            $table->date('next_service_date')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('asset_activity_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('asset_id')->nullable();
            $table->string('action_type'); // created, maintenance_updated, info_updated, out_of_service, deleted
            $table->string('title'); // e.g. "New asset added"
            $table->string('target_name')->nullable(); // e.g. "Tool Kit (ASSET-013)"
            $table->text('description')->nullable();
            $table->string('performed_by')->default('Admin'); // Admin, Technician, Staff
            $table->timestamps();

            $table->foreign('asset_id')->references('id')->on('company_assets')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('asset_activity_logs');
        Schema::dropIfExists('company_assets');
    }
};
