<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ecommerce_voucher_settings')) {
            return;
        }

        Schema::create('ecommerce_voucher_settings', function (Blueprint $table) {
            $table->id();
            $table->text('settings');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ecommerce_voucher_settings');
    }
};
