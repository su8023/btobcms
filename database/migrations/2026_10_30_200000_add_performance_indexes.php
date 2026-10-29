<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 性能索引补齐（三线审查）：slug 详情查询（流量最高的 URL 形态）此前走全表扫——
 * 旧索引均以 site_id 打头而详情查询不带 site_id，左前缀失效。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->index('slug');
        });

        Schema::table('posts', function (Blueprint $table) {
            $table->index('slug');
            // 博客列表 where status + order by published_at
            $table->index(['status', 'published_at']);
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->index('slug');
        });

        // 线索雷达导航徽章：where tier='hot' count，每次后台加载执行
        Schema::table('inquiry_enrichments', function (Blueprint $table) {
            $table->index('tier');
        });
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $t) => $t->dropIndex(['slug']));
        Schema::table('posts', function (Blueprint $t) {
            $t->dropIndex(['slug']);
            $t->dropIndex(['status', 'published_at']);
        });
        Schema::table('categories', fn (Blueprint $t) => $t->dropIndex(['slug']));
        Schema::table('inquiry_enrichments', fn (Blueprint $t) => $t->dropIndex(['tier']));
    }
};
