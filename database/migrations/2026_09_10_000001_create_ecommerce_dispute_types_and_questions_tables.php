<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('ecommerce_dispute_types')) {
            Schema::create('ecommerce_dispute_types', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('code')->unique();
                $table->text('description')->nullable();
                $table->boolean('is_active')->default(true);
                $table->integer('sort_order')->default(0);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('ecommerce_dispute_questions')) {
            Schema::create('ecommerce_dispute_questions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('dispute_type_id')->constrained('ecommerce_dispute_types')->cascadeOnDelete();
                $table->string('label');
                $table->string('field_key');
                $table->string('input_type'); // text, textarea, number, select, radio, checkbox, date, file
                $table->string('placeholder')->nullable();
                $table->text('help_text')->nullable();
                $table->json('options')->nullable(); // For select, radio, checkbox
                $table->boolean('is_required')->default(false);
                $table->integer('sort_order')->default(0);
                $table->timestamps();
            });
        }

        Schema::table('ecommerce_disputes', function (Blueprint $table) {
            if (!Schema::hasColumn('ecommerce_disputes', 'dispute_type_id')) {
                $table->unsignedBigInteger('dispute_type_id')->nullable()->after('customer_name');
            }
            if (!Schema::hasColumn('ecommerce_disputes', 'dispute_type_name')) {
                $table->string('dispute_type_name')->nullable()->after('dispute_type_id');
            }
            if (!Schema::hasColumn('ecommerce_disputes', 'items')) {
                $table->json('items')->nullable()->after('description');
            }
            if (!Schema::hasColumn('ecommerce_disputes', 'answers')) {
                $table->json('answers')->nullable()->after('items');
            }
            if (!Schema::hasColumn('ecommerce_disputes', 'expected_resolution')) {
                $table->string('expected_resolution')->nullable()->after('answers');
            }
            if (!Schema::hasColumn('ecommerce_disputes', 'admin_notes')) {
                $table->text('admin_notes')->nullable()->after('expected_resolution');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ecommerce_disputes', function (Blueprint $table) {
            $drops = [];
            foreach (['dispute_type_id', 'dispute_type_name', 'items', 'answers', 'expected_resolution', 'admin_notes'] as $col) {
                if (Schema::hasColumn('ecommerce_disputes', $col)) {
                    $drops[] = $col;
                }
            }
            if (!empty($drops)) {
                $table->dropColumn($drops);
            }
        });

        Schema::dropIfExists('ecommerce_dispute_questions');
        Schema::dropIfExists('ecommerce_dispute_types');
    }
};
