<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20)->default('post');
            $table->string('slug');
            $table->string('status', 20)->default('draft');
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_ai_generated')->default(false);
            $table->string('cover')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->unsignedBigInteger('views')->default(0);
            $table->json('title');
            $table->json('excerpt')->nullable();
            $table->json('content')->nullable();
            $table->timestamps();
            $table->index(['site_id', 'status', 'published_at']);
        });

        Schema::create('seo_meta', function (Blueprint $table) {
            $table->id();
            $table->morphs('metable');
            $table->json('title')->nullable();
            $table->json('description')->nullable();
            $table->json('keywords')->nullable();
            $table->string('canonical', 500)->nullable();
            $table->string('robots', 100)->nullable();
            $table->json('og')->nullable();
            $table->timestamps();
            $table->unique(['metable_type', 'metable_id']);
        });

        Schema::create('redirects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->string('from_path', 500);
            $table->string('to_path', 500);
            $table->unsignedSmallInteger('code')->default(301);
            $table->unsignedBigInteger('hits')->default(0);
            $table->timestamps();
            $table->index('from_path');
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('key', 128);
            $table->json('value')->nullable();
            $table->timestamps();
            $table->unique(['site_id', 'key']);
        });

        Schema::create('ai_calls', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 32);
            $table->string('model', 64);
            $table->string('purpose', 64);
            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('completion_tokens')->default(0);
            $table->string('status', 20)->default('ok');
            $table->text('error')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_calls');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('redirects');
        Schema::dropIfExists('seo_meta');
        Schema::dropIfExists('posts');
    }
};
