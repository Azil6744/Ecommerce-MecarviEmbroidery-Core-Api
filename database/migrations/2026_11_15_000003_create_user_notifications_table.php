<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('category', 40)->default('general'); // memberships, orders, payments_billing, ...
            $table->string('type', 80);
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('link')->nullable();
            $table->json('data')->nullable();
            $table->string('dedupe_key')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'read_at']);
            $table->index(['user_id', 'created_at']);
            $table->unique(['user_id', 'dedupe_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_notifications');
    }
};
