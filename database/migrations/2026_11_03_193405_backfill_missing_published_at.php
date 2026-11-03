<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 修复存量「已发布但无发布时间」的文章。
 *
 * WHY：旧版本编辑页状态下拉改「已发布」→ 保存时没人填 published_at，
 * published() 作用域（要求 published_at <= now）把这些文章全部过滤——
 * 文章实际处于"已发布但全站 404"的半发布态（线上实锤：AI 填充流程
 * 产生的草稿走这条路保存后前台不可见）。
 * Post 模型的 saving 兜底已堵住增量；这里修存量。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('posts')) {
            return;
        }

        // PHP 侧回填（跨 SQLite/MySQL 方言安全；行数极少）。
        // 优先 updated_at（最接近真实上线时刻），缺失退 created_at，再退 now()。
        DB::table('posts')
            ->where('status', 'published')
            ->whereNull('published_at')
            ->select(['id', 'updated_at', 'created_at'])
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    DB::table('posts')->where('id', $row->id)->update([
                        'published_at' => $row->updated_at ?: ($row->created_at ?: now()),
                    ]);
                }
            });
    }

    public function down(): void
    {
        // 回填的时间戳无法与"本来就有的"区分，不回滚
    }
};
