<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('slug');
            $table->string('sku', 64)->nullable();
            $table->string('status', 20)->default('published');
            $table->json('attrs')->nullable();
            $table->decimal('price_min', 12)->nullable();
            $table->decimal('price_max', 12)->nullable();
            $table->string('currency', 8)->default('USD');
            $table->string('moq', 64)->nullable();
            $table->string('unit', 32)->nullable();
            $table->unsignedBigInteger('views')->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('name');
            $table->json('summary')->nullable();
            $table->json('description')->nullable();
            $table->timestamps();
            $table->index(['site_id', 'slug']);
            $table->index(['category_id', 'status']);
        });

        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->morphs('attachable');
            $table->string('disk', 32)->default('public');
            $table->string('path');
            $table->string('original_name')->nullable();
            $table->string('mime', 128)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->json('alt')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
        Schema::dropIfExists('products');
    }
};
