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
        if (!Schema::hasTable('admin_todos')) {
            Schema::create('admin_todos', function (Blueprint $table) {
                $table->id();
                $table->string('task_id')->unique()->index();
                $table->string('title');
                $table->text('description')->nullable();
                $table->string('priority')->default('normal'); // urgent, normal, low
                $table->string('status')->default('pending'); // on_hold, pending, in_progress, completed
                $table->date('due_date')->nullable();
                $table->date('reminder_date')->nullable();
                $table->string('reminder_time')->nullable();
                $table->string('assigned_to_name')->nullable()->default('Jason Davis');
                $table->string('assigned_to_email')->nullable()->default('jason@mecarvi.com');
                $table->string('assigned_to_avatar')->nullable()->default('JD');
                $table->boolean('is_today_reminder')->default(false);
                $table->string('reminder_title')->nullable();
                $table->json('checklist_items')->nullable();
                $table->json('attachments')->nullable();
                $table->integer('order_num')->default(0);
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('admin_todos');
    }
};
