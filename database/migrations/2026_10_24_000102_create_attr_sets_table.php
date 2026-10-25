<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attr_sets', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->timestamps();
        });

        Schema::create('attributes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attr_set_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->json('name');
            $table->string('type', 20)->default('text');
            $table->string('unit', 32)->nullable();
            $table->json('options')->nullable();
            $table->boolean('is_filterable')->default(true);
            $table->boolean('is_required')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['attr_set_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attributes');
        Schema::dropIfExists('attr_sets');
    }
};
