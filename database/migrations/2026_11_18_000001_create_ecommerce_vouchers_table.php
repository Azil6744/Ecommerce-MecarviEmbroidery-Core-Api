<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ecommerce_vouchers')) {
            return;
        }

        Schema::create('ecommerce_vouchers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            // customer | business
            $table->string('holder_type', 20)->default('customer')->index();
            $table->string('holder_name');
            $table->string('holder_email')->nullable()->index();
            $table->decimal('amount', 12, 2);
            $table->decimal('balance', 12, 2);
            // active | redeemed | expired | voided
            $table->string('status', 20)->default('active')->index();
            // single | multi
            $table->string('usage_type', 20)->default('single');
            $table->date('issued_at');
            $table->date('expires_at')->nullable();
            $table->string('currency', 10)->default('USD');
            $table->text('notes')->nullable();
            $table->string('void_reason')->nullable();
            $table->unsignedBigInteger('issued_by_admin_id')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ecommerce_vouchers');
    }
};
