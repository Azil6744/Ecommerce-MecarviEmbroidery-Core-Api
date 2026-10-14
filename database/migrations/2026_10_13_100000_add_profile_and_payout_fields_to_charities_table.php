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
        Schema::table('charities', function (Blueprint $table) {
            if (!Schema::hasColumn('charities', 'position_title')) {
                $table->string('position_title')->nullable()->after('contact_person');
            }
            if (!Schema::hasColumn('charities', 'year_established')) {
                $table->string('year_established')->nullable()->after('status');
            }
            if (!Schema::hasColumn('charities', 'city')) {
                $table->string('city')->nullable()->after('address');
            }
            if (!Schema::hasColumn('charities', 'state_province')) {
                $table->string('state_province')->nullable()->after('city');
            }
            if (!Schema::hasColumn('charities', 'postal_code')) {
                $table->string('postal_code')->nullable()->after('state_province');
            }
            if (!Schema::hasColumn('charities', 'country')) {
                $table->string('country')->nullable()->default('United States')->after('postal_code');
            }
            if (!Schema::hasColumn('charities', 'preferred_payout_method')) {
                $table->string('preferred_payout_method')->nullable()->default('Bank Transfer')->after('country');
            }
            if (!Schema::hasColumn('charities', 'bank_name')) {
                $table->string('bank_name')->nullable()->after('preferred_payout_method');
            }
            if (!Schema::hasColumn('charities', 'account_type')) {
                $table->string('account_type')->nullable()->after('bank_name');
            }
            if (!Schema::hasColumn('charities', 'routing_number')) {
                $table->string('routing_number')->nullable()->after('account_type');
            }
            if (!Schema::hasColumn('charities', 'account_number')) {
                $table->string('account_number')->nullable()->after('routing_number');
            }
            if (!Schema::hasColumn('charities', 'check_payable_to')) {
                $table->string('check_payable_to')->nullable()->after('account_number');
            }
            if (!Schema::hasColumn('charities', 'check_address_same_as_charity')) {
                $table->boolean('check_address_same_as_charity')->default(true)->after('check_payable_to');
            }
            if (!Schema::hasColumn('charities', 'check_address')) {
                $table->string('check_address')->nullable()->after('check_address_same_as_charity');
            }
            if (!Schema::hasColumn('charities', 'check_city')) {
                $table->string('check_city')->nullable()->after('check_address');
            }
            if (!Schema::hasColumn('charities', 'check_state_province')) {
                $table->string('check_state_province')->nullable()->after('check_city');
            }
            if (!Schema::hasColumn('charities', 'check_postal_code')) {
                $table->string('check_postal_code')->nullable()->after('check_state_province');
            }
            if (!Schema::hasColumn('charities', 'check_country')) {
                $table->string('check_country')->nullable()->default('United States')->after('check_postal_code');
            }
            if (!Schema::hasColumn('charities', 'check_memo_notes')) {
                $table->text('check_memo_notes')->nullable()->after('check_country');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('charities', function (Blueprint $table) {
            $table->dropColumn([
                'position_title',
                'year_established',
                'city',
                'state_province',
                'postal_code',
                'country',
                'preferred_payout_method',
                'bank_name',
                'account_type',
                'routing_number',
                'account_number',
                'check_payable_to',
                'check_address_same_as_charity',
                'check_address',
                'check_city',
                'check_state_province',
                'check_postal_code',
                'check_country',
                'check_memo_notes',
            ]);
        });
    }
};
