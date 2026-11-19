<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ecommerce_vouchers', function (Blueprint $table) {
            if (! Schema::hasColumn('ecommerce_vouchers', 'scheduled_void_at')) {
                $table->date('scheduled_void_at')->nullable();
            }
            if (! Schema::hasColumn('ecommerce_vouchers', 'void_notes')) {
                $table->text('void_notes')->nullable();
            }
            if (! Schema::hasColumn('ecommerce_vouchers', 'issued_by_name')) {
                $table->string('issued_by_name')->nullable();
            }
        });

        if (! Schema::hasTable('ecommerce_voucher_logs')) {
            Schema::create('ecommerce_voucher_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('voucher_id')->index();
                $table->string('log_type', 20)->default('activity')->index(); // activity | note
                $table->string('action', 120);
                $table->text('details');
                $table->string('performed_by')->nullable();
                $table->unsignedBigInteger('performed_by_id')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ecommerce_voucher_logs');

        Schema::table('ecommerce_vouchers', function (Blueprint $table) {
            $table->dropColumn(['scheduled_void_at', 'void_notes', 'issued_by_name']);
        });
    }
};
