<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('ecommerce_conversation_messages')) {
            return;
        }
        Schema::table('ecommerce_conversation_messages', function (Blueprint $table) {
            if (!Schema::hasColumn('ecommerce_conversation_messages', 'attachment_path')) {
                $table->string('attachment_path')->nullable();
            }
            if (!Schema::hasColumn('ecommerce_conversation_messages', 'attachment_name')) {
                $table->string('attachment_name')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('ecommerce_conversation_messages')) {
            return;
        }
        Schema::table('ecommerce_conversation_messages', function (Blueprint $table) {
            foreach (['attachment_path', 'attachment_name'] as $col) {
                if (Schema::hasColumn('ecommerce_conversation_messages', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
