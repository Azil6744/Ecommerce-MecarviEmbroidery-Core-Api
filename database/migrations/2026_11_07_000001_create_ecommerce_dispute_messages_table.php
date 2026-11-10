<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ecommerce_dispute_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ecommerce_dispute_id')->constrained('ecommerce_disputes')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('sender_type', 20); // customer | admin
            $table->string('sender_name')->nullable();
            $table->text('body');
            $table->boolean('is_internal')->default(false);
            $table->timestamps();

            $table->index(['ecommerce_dispute_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ecommerce_dispute_messages');
    }
};
