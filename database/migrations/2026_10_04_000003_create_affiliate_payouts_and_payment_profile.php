<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('ecommerce_affiliate_payouts')) {
            Schema::create('ecommerce_affiliate_payouts', function (Blueprint $table) {
                $table->id();
                $table->string('payout_number', 40)->unique();
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('affiliate_id')->nullable();
                $table->decimal('amount', 10, 2);
                $table->string('method', 30);              // bank_transfer | paypal | cash_app | mecarvi_wallet | payoneer
                $table->string('method_detail', 255)->nullable();
                $table->string('status', 20)->default('pending'); // pending | approved | paid | rejected
                $table->string('source', 10)->default('user');    // user | admin
                $table->boolean('auto_process')->default(false);
                $table->json('commission_ids')->nullable();
                $table->date('scheduled_for')->nullable();
                $table->string('reference_id', 100)->nullable();
                $table->text('notes')->nullable();
                $table->string('reject_reason', 255)->nullable();
                $table->unsignedBigInteger('processed_by')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->timestamp('rejected_at')->nullable();
                $table->timestamps();

                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
                $table->index(['status', 'scheduled_for']);
            });
        }

        Schema::table('ecommerce_affiliates', function (Blueprint $table) {
            if (!Schema::hasColumn('ecommerce_affiliates', 'payout_method')) {
                $table->string('payout_method', 30)->nullable();
                $table->text('payout_details')->nullable();   // JSON (account / email / handle)
                $table->text('tax_info')->nullable();         // JSON (legal name, tax id, country ...)
                $table->timestamp('tax_info_submitted_at')->nullable();
            }
        });

        Schema::table('ecommerce_affiliate_applications', function (Blueprint $table) {
            if (!Schema::hasColumn('ecommerce_affiliate_applications', 'payout_method')) {
                $table->string('payout_method', 30)->nullable();
                $table->text('payout_details')->nullable();
                $table->text('tax_info')->nullable();
                $table->string('review_note', 255)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('ecommerce_affiliate_applications', function (Blueprint $table) {
            if (Schema::hasColumn('ecommerce_affiliate_applications', 'payout_method')) {
                $table->dropColumn(['payout_method', 'payout_details', 'tax_info', 'review_note']);
            }
        });
        Schema::table('ecommerce_affiliates', function (Blueprint $table) {
            if (Schema::hasColumn('ecommerce_affiliates', 'payout_method')) {
                $table->dropColumn(['payout_method', 'payout_details', 'tax_info', 'tax_info_submitted_at']);
            }
        });
        Schema::dropIfExists('ecommerce_affiliate_payouts');
    }
};
