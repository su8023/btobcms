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
        // inquiry_enrichments 属于 LeadLens 模块：插件可被停用或删除，
        // 核心迁移绝不能假设它存在，否则全新库迁移会整体中断
        // （nwidart 只注册「已启用」模块的迁移目录）。
        // 该索引改由 LeadLens 自己的迁移随表创建，这里只做兜底补齐。
        // 注意：hasIndex 的字符串参数是【索引名】，列名要传数组。
        if (Schema::hasTable('inquiry_enrichments')
            && ! Schema::hasIndex('inquiry_enrichments', ['tier'])) {
            Schema::table('inquiry_enrichments', function (Blueprint $table) {
                $table->index('tier');
            });
        }
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $t) => $t->dropIndex(['slug']));
        Schema::table('posts', function (Blueprint $t) {
            $t->dropIndex(['slug']);
            $t->dropIndex(['status', 'published_at']);
        });
        Schema::table('categories', fn (Blueprint $t) => $t->dropIndex(['slug']));

        if (Schema::hasTable('inquiry_enrichments')
            && Schema::hasIndex('inquiry_enrichments', ['tier'])) {
            Schema::table('inquiry_enrichments', fn (Blueprint $t) => $t->dropIndex(['tier']));
        }
    }
};
