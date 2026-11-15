<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('membership_team_seats', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('owner_user_id');
            $table->unsignedBigInteger('central_membership_id')->nullable();
            $table->unsignedBigInteger('local_membership_id')->nullable();
            $table->string('member_email');
            $table->unsignedBigInteger('member_user_id')->nullable();
            $table->string('member_name')->nullable();
            $table->string('status', 20)->default('invited'); // invited | active | declined | removed
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();

            $table->index(['owner_user_id', 'status']);
            $table->index(['member_email', 'status']);
            $table->index(['member_user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_team_seats');
    }
};
