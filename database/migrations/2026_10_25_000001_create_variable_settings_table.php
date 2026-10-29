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
        if (!Schema::hasTable('variable_settings')) {
            Schema::create('variable_settings', function (Blueprint $table) {
                $table->id();
                $table->string('group')->default('general')->index();
                $table->string('type')->index();
                $table->string('name');
                $table->string('parent_name')->nullable();
                $table->string('head_name')->nullable();
                $table->string('head_initials')->nullable();
                $table->longText('icon')->nullable();
                $table->string('color')->nullable()->default('pink');
                $table->text('address')->nullable();
                $table->string('country')->nullable();
                $table->string('sub_department')->nullable();
                $table->string('time_zone')->nullable();
                $table->json('working_days')->nullable();
                $table->json('schedule_periods')->nullable();
                $table->decimal('hours_per_day', 5, 2)->nullable();
                $table->json('extra_data')->nullable();
                $table->integer('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('variable_settings');
    }
};
