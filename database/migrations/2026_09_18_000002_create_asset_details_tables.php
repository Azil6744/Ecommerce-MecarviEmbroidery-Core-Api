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
        // 1. Asset Maintenance Records (Schedule & History)
        Schema::create('asset_maintenance_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('asset_id');
            $table->string('record_type')->default('schedule'); // 'schedule', 'history'
            $table->string('service_type'); // 'Routine Service', 'Calibration', 'Full Service', 'Part Replacement', 'Inspection'
            $table->text('description')->nullable();
            $table->string('status')->default('Scheduled'); // 'Scheduled', 'Completed', 'In Progress', 'Overdue', 'Cancelled'
            $table->string('assigned_to')->default('Technician');
            $table->date('service_date');
            $table->timestamp('completed_at')->nullable();
            $table->string('technician_name')->nullable();
            $table->decimal('cost', 10, 2)->default(0.00);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('asset_id')->references('id')->on('company_assets')->onDelete('cascade');
        });

        // 2. Asset Financial Records (Expenses & Invoices)
        Schema::create('asset_financial_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('asset_id');
            $table->string('expense_type')->default('Purchase'); // 'Purchase', 'Labor', 'Parts', 'Travel', 'Other'
            $table->string('description');
            $table->string('invoice_number')->nullable();
            $table->string('vendor_name')->nullable();
            $table->decimal('amount', 12, 2)->default(0.00);
            $table->date('expense_date');
            $table->string('receipt_url')->nullable();
            $table->timestamps();

            $table->foreign('asset_id')->references('id')->on('company_assets')->onDelete('cascade');
        });

        // 3. Asset Notes & Observations
        Schema::create('asset_notes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('asset_id');
            $table->string('author_name')->default('Admin User');
            $table->string('author_initials')->default('AD');
            $table->string('author_avatar_bg')->default('bg-slate-700');
            $table->string('category')->default('General'); // 'General', 'Maintenance', 'Performance', 'Issue', 'Configuration'
            $table->text('note_text');
            $table->json('attachments')->nullable(); // Array of [{name, size, type, preview, url}]
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('asset_id')->references('id')->on('company_assets')->onDelete('cascade');
        });

        // 4. Update asset_activity_logs with audit fields if not present
        if (!Schema::hasColumn('asset_activity_logs', 'before_value')) {
            Schema::table('asset_activity_logs', function (Blueprint $table) {
                $table->string('before_value')->nullable()->after('target_name');
                $table->string('after_value')->nullable()->after('before_value');
                $table->string('location')->nullable()->after('after_value');
                $table->string('reference_number')->nullable()->after('location');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('asset_notes');
        Schema::dropIfExists('asset_financial_records');
        Schema::dropIfExists('asset_maintenance_records');

        if (Schema::hasColumn('asset_activity_logs', 'before_value')) {
            Schema::table('asset_activity_logs', function (Blueprint $table) {
                $table->dropColumn(['before_value', 'after_value', 'location', 'reference_number']);
            });
        }
    }
};
