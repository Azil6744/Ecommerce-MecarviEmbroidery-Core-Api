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
        // 1. Asset Documents Table
        if (!Schema::hasTable('asset_documents')) {
            Schema::create('asset_documents', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('asset_id');
                $table->string('title');
                $table->string('file_name');
                $table->string('file_url')->nullable();
                $table->string('file_type')->default('pdf'); // 'pdf', 'jpg', 'png', 'docx', 'xlsx'
                $table->string('file_size')->default('200 KB');
                $table->string('category')->default('purchase'); // 'purchase', 'warranty', 'manual', 'maintenance', 'photo', 'other'
                $table->date('document_date')->nullable();
                $table->text('description')->nullable();
                $table->timestamps();

                $table->foreign('asset_id')->references('id')->on('company_assets')->onDelete('cascade');
                $table->index(['asset_id', 'category']);
            });
        }

        // 2. Expand asset_maintenance_records for Schedule & Log Maintenance modals
        Schema::table('asset_maintenance_records', function (Blueprint $table) {
            if (!Schema::hasColumn('asset_maintenance_records', 'maintenance_type')) {
                $table->string('maintenance_type')->nullable()->after('record_type');
            }
            if (!Schema::hasColumn('asset_maintenance_records', 'maintenance_title')) {
                $table->string('maintenance_title')->nullable()->after('maintenance_type');
            }
            if (!Schema::hasColumn('asset_maintenance_records', 'service_provider')) {
                $table->string('service_provider')->nullable()->after('assigned_to');
            }
            if (!Schema::hasColumn('asset_maintenance_records', 'contact_person')) {
                $table->string('contact_person')->nullable()->after('service_provider');
            }
            if (!Schema::hasColumn('asset_maintenance_records', 'phone')) {
                $table->string('phone')->nullable()->after('contact_person');
            }
            if (!Schema::hasColumn('asset_maintenance_records', 'email')) {
                $table->string('email')->nullable()->after('phone');
            }
            if (!Schema::hasColumn('asset_maintenance_records', 'frequency')) {
                $table->string('frequency')->nullable()->after('email');
            }
            if (!Schema::hasColumn('asset_maintenance_records', 'start_date')) {
                $table->date('start_date')->nullable()->after('frequency');
            }
            if (!Schema::hasColumn('asset_maintenance_records', 'next_service_date')) {
                $table->date('next_service_date')->nullable()->after('start_date');
            }
            if (!Schema::hasColumn('asset_maintenance_records', 'provider_type')) {
                $table->string('provider_type')->nullable()->after('next_service_date');
            }
            if (!Schema::hasColumn('asset_maintenance_records', 'invoice_number')) {
                $table->string('invoice_number')->nullable()->after('technician_name');
            }
            if (!Schema::hasColumn('asset_maintenance_records', 'parts_cost')) {
                $table->decimal('parts_cost', 10, 2)->default(0.00)->after('cost');
            }
            if (!Schema::hasColumn('asset_maintenance_records', 'labor_cost')) {
                $table->decimal('labor_cost', 10, 2)->default(0.00)->after('parts_cost');
            }
            if (!Schema::hasColumn('asset_maintenance_records', 'travel_cost')) {
                $table->decimal('travel_cost', 10, 2)->default(0.00)->after('labor_cost');
            }
            if (!Schema::hasColumn('asset_maintenance_records', 'other_cost')) {
                $table->decimal('other_cost', 10, 2)->default(0.00)->after('travel_cost');
            }
            if (!Schema::hasColumn('asset_maintenance_records', 'documents')) {
                $table->json('documents')->nullable()->after('other_cost');
            }
        });

        // 3. Expand asset_financial_records for Add Expense modal
        Schema::table('asset_financial_records', function (Blueprint $table) {
            if (!Schema::hasColumn('asset_financial_records', 'payment_method')) {
                $table->string('payment_method')->nullable()->after('amount');
            }
            if (!Schema::hasColumn('asset_financial_records', 'reference_number')) {
                $table->string('reference_number')->nullable()->after('payment_method');
            }
            if (!Schema::hasColumn('asset_financial_records', 'is_recurring')) {
                $table->boolean('is_recurring')->default(false)->after('reference_number');
            }
            if (!Schema::hasColumn('asset_financial_records', 'attachment_name')) {
                $table->string('attachment_name')->nullable()->after('receipt_url');
            }
            if (!Schema::hasColumn('asset_financial_records', 'attachment_size')) {
                $table->string('attachment_size')->nullable()->after('attachment_name');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('asset_documents');

        Schema::table('asset_maintenance_records', function (Blueprint $table) {
            $table->dropColumn([
                'maintenance_type',
                'maintenance_title',
                'service_provider',
                'contact_person',
                'phone',
                'email',
                'frequency',
                'start_date',
                'next_service_date',
                'provider_type',
                'invoice_number',
                'parts_cost',
                'labor_cost',
                'travel_cost',
                'other_cost',
                'documents',
            ]);
        });

        Schema::table('asset_financial_records', function (Blueprint $table) {
            $table->dropColumn([
                'payment_method',
                'reference_number',
                'is_recurring',
                'attachment_name',
                'attachment_size',
            ]);
        });
    }
};
