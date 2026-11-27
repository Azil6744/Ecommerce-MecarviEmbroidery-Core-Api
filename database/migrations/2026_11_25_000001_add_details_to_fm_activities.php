<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fm_activities', function (Blueprint $table) {
            // size / extension / path / from / to, shown in the Activity Log popup
            $table->json('details')->nullable()->after('entity_name');
            $table->index('actor_id');
        });
    }

    public function down(): void
    {
        Schema::table('fm_activities', function (Blueprint $table) {
            $table->dropIndex(['actor_id']);
            $table->dropColumn('details');
        });
    }
};
