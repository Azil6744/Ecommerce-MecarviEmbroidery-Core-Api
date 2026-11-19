<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ecommerce_vouchers', function (Blueprint $table) {
            if (! Schema::hasColumn('ecommerce_vouchers', 'holder_phone')) {
                $table->string('holder_phone', 40)->nullable();
            }
            if (! Schema::hasColumn('ecommerce_vouchers', 'contact_person')) {
                $table->string('contact_person')->nullable();
            }
            if (! Schema::hasColumn('ecommerce_vouchers', 'order_number')) {
                $table->string('order_number', 60)->nullable()->index();
            }
            if (! Schema::hasColumn('ecommerce_vouchers', 'issue_category')) {
                $table->string('issue_category', 80)->nullable();
            }
            if (! Schema::hasColumn('ecommerce_vouchers', 'issue_summary')) {
                $table->text('issue_summary')->nullable();
            }
            if (! Schema::hasColumn('ecommerce_vouchers', 'compensation_reason')) {
                $table->string('compensation_reason', 120)->nullable();
            }
            if (! Schema::hasColumn('ecommerce_vouchers', 'delivery_methods')) {
                $table->text('delivery_methods')->nullable();
            }
            if (! Schema::hasColumn('ecommerce_vouchers', 'case_id')) {
                $table->string('case_id', 40)->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('ecommerce_vouchers', function (Blueprint $table) {
            $table->dropColumn([
                'holder_phone', 'contact_person', 'order_number', 'issue_category',
                'issue_summary', 'compensation_reason', 'delivery_methods', 'case_id',
            ]);
        });
    }
};
