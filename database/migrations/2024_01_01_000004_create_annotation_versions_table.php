<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('annotation_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('annotation_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->jsonb('geometry');
            $table->jsonb('style');
            $table->timestamps();

            $table->unique(['annotation_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('annotation_versions');
    }
};