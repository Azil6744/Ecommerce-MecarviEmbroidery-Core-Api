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
        Schema::create('charities', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('tagline')->nullable();
            $table->text('description')->nullable();
            $table->string('contact_person');
            $table->text('address');
            $table->string('phone');
            $table->string('email');
            $table->string('web')->nullable();
            $table->string('fax')->nullable();
            $table->string('category');
            $table->string('status')->default('Active');
            $table->text('assistance_tags')->nullable(); // JSON array
            $table->string('logo_svg_type')->default('generic_charity');
            $table->timestamps();
        });

        Schema::create('donations', function (Blueprint $table) {
            $table->id();
            $table->string('order_id')->nullable();
            $table->string('txn_id')->nullable();
            $table->string('donor_name');
            $table->string('donor_email');
            $table->string('charity_name');
            $table->string('charity_category');
            $table->string('charity_logo_type')->default('generic_charity');
            $table->decimal('amount', 10, 2);
            $table->string('payment_method_brand');
            $table->string('payment_method_details');
            $table->string('payment_method_email')->nullable();
            $table->text('note')->nullable();
            $table->string('status')->default('Pending');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('donations');
        Schema::dropIfExists('charities');
    }
};
