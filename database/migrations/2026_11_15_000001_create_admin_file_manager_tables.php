<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_file_folders', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('color')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->unique('name');
        });

        Schema::create('admin_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('folder_id')->nullable()->constrained('admin_file_folders')->nullOnDelete();
            $table->string('name');
            $table->string('path');
            $table->string('disk')->default('local');
            $table->unsignedBigInteger('size')->default(0);
            $table->string('mime_type')->nullable();
            $table->string('extension', 20)->nullable();
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->timestamps();
            $table->index('folder_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_files');
        Schema::dropIfExists('admin_file_folders');
    }
};
