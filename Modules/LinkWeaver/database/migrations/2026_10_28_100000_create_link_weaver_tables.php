<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('link_terms', function (Blueprint $table) {
            $table->id();
            $table->string('keyword');                                  // 触发关键词（英文小写匹配）
            $table->string('target', 500);                              // 目标 URL（如 /product/gate-valve）
            $table->string('title', 191)->nullable();                   // 链接 title 属性（可选）
            $table->string('locale', 8)->nullable();                    // 限定语言；null = 所有语言正文都织
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->index(['enabled', 'locale']);
        });

        Schema::create('applied_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('term_id')->constrained('link_terms')->cascadeOnDelete();
            $table->string('locale', 8);
            $table->timestamps();

            // 幂等锚点：同一词在同一文章同一语言只织一次
            $table->unique(['post_id', 'term_id', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('applied_links');
        Schema::dropIfExists('link_terms');
    }
};
