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
        if (!Schema::hasTable('charity_payouts')) {
            Schema::create('charity_payouts', function (Blueprint $table) {
                $table->id();
                $table->string('payout_id')->unique();
                $table->unsignedBigInteger('charity_id')->nullable();
                $table->string('charity_name');
                $table->string('charity_tagline')->nullable();
                $table->string('charity_logo_type')->default('generic_charity');
                $table->decimal('amount', 10, 2);
                $table->string('payment_method')->default('Bank Transfer');
                $table->string('reference_or_check')->nullable();
                $table->string('status')->default('Processing'); // Paid, Processing, Canceled
                $table->string('scheduled_date')->nullable();
                $table->string('date_paid_or_expected')->nullable();
                $table->text('notes_to_charity')->nullable();
                $table->text('admin_notes')->nullable();
                $table->string('cancellation_reason')->nullable();
                $table->text('cancellation_notes')->nullable();
                $table->json('selected_donation_ids')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('charity_payouts');
    }
};
