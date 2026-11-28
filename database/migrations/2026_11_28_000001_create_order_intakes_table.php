<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('order_intakes')) {
            return;
        }

        Schema::create('order_intakes', function (Blueprint $table) {
            $table->id();
            $table->string('order_number', 100)->unique();
            $table->string('drop_off_date', 64)->nullable();
            $table->string('pick_up_date', 64)->nullable();
            $table->string('status', 50)->default('In Progress');
            $table->string('accent_color', 32)->default('#F97316');
            $table->string('assigned_staff', 120)->nullable();
            $table->string('priority_status', 50)->nullable();
            $table->json('customer')->nullable();
            $table->json('items')->nullable();
            $table->json('drop_off')->nullable();
            $table->json('pick_up')->nullable();
            $table->text('internal_notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_intakes');
    }
};
