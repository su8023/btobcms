<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // FAQ：多态挂在文章（后续可挂产品/分类），FAQPage JSON-LD + 前台渲染共用
        Schema::create('geo_faqs', function (Blueprint $table) {
            $table->id();
            $table->morphs('faqable');
            $table->string('locale', 8);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->string('question', 500);
            $table->text('answer');
            $table->string('source', 8)->default('ai');  // ai / manual
            $table->timestamps();

            $table->index(['faqable_type', 'faqable_id', 'locale']);
        });

        // AI 引擎来访：referrer 命中引擎清单才写入（每命中一次页面浏览一条）
        Schema::create('ai_visits', function (Blueprint $table) {
            $table->id();
            $table->string('engine', 32);        // ChatGPT / Perplexity / ...
            $table->string('path', 500);         // 落地路径（含查询串前截断）
            $table->string('referer', 500)->nullable();
            $table->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_visits');
        Schema::dropIfExists('geo_faqs');
    }
};
