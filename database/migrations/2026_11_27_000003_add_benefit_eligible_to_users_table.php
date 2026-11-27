<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'benefit_eligible')) {
                $table->boolean('benefit_eligible')->default(true);
            }
            if (!Schema::hasColumn('users', 'registration_device_hash')) {
                $table->string('registration_device_hash', 128)->nullable();
            }
            if (!Schema::hasColumn('users', 'registration_ip')) {
                $table->string('registration_ip', 64)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach (['benefit_eligible', 'registration_device_hash', 'registration_ip'] as $col) {
                if (Schema::hasColumn('users', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
