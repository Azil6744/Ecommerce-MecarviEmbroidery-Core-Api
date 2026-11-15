<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Every free-trial request: granted ones are what later signups are checked against.
        Schema::create('membership_trial_claims', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->unsignedBigInteger('plan_id')->nullable();
            $table->string('status', 20)->index(); // granted | refused
            $table->string('matched_rule', 30)->nullable();
            $table->unsignedBigInteger('matched_claim_id')->nullable();
            $table->string('email_normalized')->nullable()->index();
            $table->string('phone_normalized', 30)->nullable()->index();
            $table->string('card_fingerprint', 100)->nullable()->index();
            $table->string('device_hash', 64)->nullable()->index();
            $table->string('ip_address', 45)->nullable()->index();
            $table->boolean('ip_flagged')->default(false);
            $table->unsignedBigInteger('central_membership_id')->nullable();
            $table->timestamps();
        });

        if (Schema::hasTable('ecommerce_payment_methods') && ! Schema::hasColumn('ecommerce_payment_methods', 'card_fingerprint')) {
            Schema::table('ecommerce_payment_methods', function (Blueprint $table) {
                $table->string('card_fingerprint', 100)->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_trial_claims');
        if (Schema::hasColumn('ecommerce_payment_methods', 'card_fingerprint')) {
            Schema::table('ecommerce_payment_methods', function (Blueprint $table) {
                $table->dropColumn('card_fingerprint');
            });
        }
    }
};
