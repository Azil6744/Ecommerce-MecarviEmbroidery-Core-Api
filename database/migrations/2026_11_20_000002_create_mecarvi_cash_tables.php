<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Extend the existing wallet ledger so one table carries wallet + store-credit rows.
        Schema::table('ecommerce_wallet_transactions', function (Blueprint $table) {
            if (! Schema::hasColumn('ecommerce_wallet_transactions', 'balance_type')) {
                $table->string('balance_type', 20)->default('wallet')->index(); // wallet | store_credit
            }
            if (! Schema::hasColumn('ecommerce_wallet_transactions', 'category')) {
                $table->string('category', 40)->nullable()->index(); // topup|payment|adjustment|credit_issued|credit_reversed|refund
            }
            if (! Schema::hasColumn('ecommerce_wallet_transactions', 'reason')) {
                $table->string('reason', 120)->nullable();
            }
            if (! Schema::hasColumn('ecommerce_wallet_transactions', 'internal_note')) {
                $table->text('internal_note')->nullable();
            }
            if (! Schema::hasColumn('ecommerce_wallet_transactions', 'expires_at')) {
                $table->date('expires_at')->nullable();
            }
            if (! Schema::hasColumn('ecommerce_wallet_transactions', 'performed_by')) {
                $table->string('performed_by')->nullable();
            }
            if (! Schema::hasColumn('ecommerce_wallet_transactions', 'performed_by_id')) {
                $table->unsignedBigInteger('performed_by_id')->nullable();
            }
        });

        if (! Schema::hasTable('ecommerce_wallet_accounts')) {
            Schema::create('ecommerce_wallet_accounts', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->unique();
                $table->string('wallet_code', 30)->unique();
                $table->string('wallet_type', 20)->default('customer'); // customer | business
                $table->string('status', 20)->default('active')->index(); // active | inactive
                $table->decimal('store_credit_balance', 12, 2)->default(0);
                $table->decimal('store_credit_issued_total', 12, 2)->default(0);
                $table->decimal('store_credit_redeemed_total', 12, 2)->default(0);
                $table->string('disable_reason', 120)->nullable();
                $table->text('disable_description')->nullable();
                $table->date('disable_effective_at')->nullable(); // set => scheduled disable
                $table->string('disable_funds_action', 20)->nullable(); // void | refund
                $table->timestamp('disabled_at')->nullable();
                $table->string('disabled_by')->nullable();
                $table->timestamp('last_activity_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ecommerce_wallet_notes')) {
            Schema::create('ecommerce_wallet_notes', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->index();
                $table->unsignedBigInteger('parent_id')->nullable()->index();
                $table->unsignedBigInteger('author_id')->nullable();
                $table->string('author_name');
                $table->text('body');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ecommerce_wallet_activity_logs')) {
            Schema::create('ecommerce_wallet_activity_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->index();
                $table->string('kind', 30); // issued|payment|adjust|topup|reversed|disabled|enabled
                $table->string('title', 120);
                $table->text('description')->nullable();
                $table->string('reference_id', 40)->nullable();
                $table->string('performed_by')->nullable();
                $table->unsignedBigInteger('performed_by_id')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ecommerce_wallet_settings')) {
            Schema::create('ecommerce_wallet_settings', function (Blueprint $table) {
                $table->id();
                $table->text('settings');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ecommerce_wallet_settings');
        Schema::dropIfExists('ecommerce_wallet_activity_logs');
        Schema::dropIfExists('ecommerce_wallet_notes');
        Schema::dropIfExists('ecommerce_wallet_accounts');

        Schema::table('ecommerce_wallet_transactions', function (Blueprint $table) {
            $cols = array_filter(
                ['balance_type', 'category', 'reason', 'internal_note', 'expires_at', 'performed_by', 'performed_by_id'],
                fn ($c) => Schema::hasColumn('ecommerce_wallet_transactions', $c)
            );
            if ($cols) {
                $table->dropColumn(array_values($cols));
            }
        });
    }
};
