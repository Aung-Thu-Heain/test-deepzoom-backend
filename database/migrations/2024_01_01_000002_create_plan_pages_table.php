<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('page_number');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->string('dzi_key');
            $table->string('thumbnail_key')->nullable();
            $table->timestamps();

            $table->unique(['plan_id', 'page_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_pages');
    }
};