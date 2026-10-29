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
        Schema::table('footer_contents', function (Blueprint $table) {
            if (!Schema::hasColumn('footer_contents', 'fax')) {
                $table->string('fax')->nullable()->after('phone');
            }
            if (!Schema::hasColumn('footer_contents', 'corporate_address')) {
                $table->text('corporate_address')->nullable()->after('email');
            }
            if (!Schema::hasColumn('footer_contents', 'company_address')) {
                $table->text('company_address')->nullable()->after('corporate_address');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('footer_contents', function (Blueprint $table) {
            if (Schema::hasColumn('footer_contents', 'company_address')) {
                $table->dropColumn('company_address');
            }
            if (Schema::hasColumn('footer_contents', 'corporate_address')) {
                $table->dropColumn('corporate_address');
            }
            if (Schema::hasColumn('footer_contents', 'fax')) {
                $table->dropColumn('fax');
            }
        });
    }
};
