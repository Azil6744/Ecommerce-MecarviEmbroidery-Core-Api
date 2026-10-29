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
        if (!Schema::hasTable('charity_activity_logs')) {
            Schema::create('charity_activity_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('charity_id')->index();
                $table->string('charity_name')->nullable();
                $table->string('action_type')->index(); // status_change, payout_created, payout_completed, payout_reversed, donation_received, donation_reversed, profile_update, created, deleted
                $table->string('title');
                $table->text('description')->nullable();
                $table->unsignedBigInteger('staff_id')->nullable();
                $table->string('staff_name')->default('Admin (Superadmin)');
                $table->string('staff_role')->default('Administrator');
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('charity_activity_logs');
    }
};
