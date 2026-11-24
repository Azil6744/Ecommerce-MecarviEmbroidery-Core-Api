<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fields captured by the admin "Issue Gift Card" popup: who the card is from
 * (customer or business), the card kind, the shipping address for physical
 * cards, and how the card was issued (Purchased / Complimentary / Replacement).
 * `issue_type` keeps meaning Manual vs Purchased (admin vs storefront).
 */
return new class extends Migration
{
    private array $columns = [
        'giftcard_type', 'issuance_type', 'expiration_policy', 'fraud_monitoring',
        'sender_type', 'sender_user_id', 'sender_email', 'sender_phone', 'sender_business_name', 'sender_contact_person',
        'shipping_name', 'shipping_address_line1', 'shipping_address_line2', 'shipping_city', 'shipping_state', 'shipping_zip',
    ];

    public function up(): void
    {
        Schema::table('ecommerce_gift_cards', function (Blueprint $table) {
            $add = [
                'giftcard_type' => fn () => $table->string('giftcard_type', 20)->default('egift'),
                'issuance_type' => fn () => $table->string('issuance_type', 30)->nullable(),
                'expiration_policy' => fn () => $table->string('expiration_policy', 80)->nullable(),
                'fraud_monitoring' => fn () => $table->boolean('fraud_monitoring')->default(false),
                'sender_type' => fn () => $table->string('sender_type', 20)->nullable(),
                'sender_user_id' => fn () => $table->unsignedBigInteger('sender_user_id')->nullable()->index(),
                'sender_email' => fn () => $table->string('sender_email')->nullable(),
                'sender_phone' => fn () => $table->string('sender_phone', 50)->nullable(),
                'sender_business_name' => fn () => $table->string('sender_business_name')->nullable(),
                'sender_contact_person' => fn () => $table->string('sender_contact_person')->nullable(),
                'shipping_name' => fn () => $table->string('shipping_name')->nullable(),
                'shipping_address_line1' => fn () => $table->string('shipping_address_line1')->nullable(),
                'shipping_address_line2' => fn () => $table->string('shipping_address_line2')->nullable(),
                'shipping_city' => fn () => $table->string('shipping_city', 120)->nullable(),
                'shipping_state' => fn () => $table->string('shipping_state', 120)->nullable(),
                'shipping_zip' => fn () => $table->string('shipping_zip', 20)->nullable(),
            ];
            foreach ($add as $name => $fn) {
                if (! Schema::hasColumn('ecommerce_gift_cards', $name)) {
                    $fn();
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('ecommerce_gift_cards', function (Blueprint $table) {
            foreach ($this->columns as $col) {
                if (Schema::hasColumn('ecommerce_gift_cards', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
