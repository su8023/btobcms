<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 修复存量超长 SEO 关键词。
 *
 * WHY：后台 SEO 关键词字段是 maxLength(255) 的 TextInput，而 AutoSeo 发布时
 * 直接把 LLM 输出写库、不做长度约束（实测生成 290 字符）。超长值会让后续
 * 每次编辑都校验失败，而错误提示藏在默认折叠的 SEO 区域里 →
 * 运营点「保存」没有任何反馈，文章完全无法编辑。
 *
 * 这里把超限的关键词截断到 255，与 AutoSeo 写入时的截断口径一致。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('seo_meta')) {
            return;
        }

        DB::table('seo_meta')
            ->whereNotNull('keywords')
            ->orderBy('id')
            ->chunk(200, function ($rows): void {
                foreach ($rows as $row) {
                    $decoded = json_decode((string) $row->keywords, true);

                    if (! is_array($decoded)) {
                        continue;
                    }

                    $changed = false;

                    foreach ($decoded as $locale => $value) {
                        if (! is_string($value) || mb_strlen($value) <= 255) {
                            continue;
                        }

                        $decoded[$locale] = mb_substr($value, 0, 255);
                        $changed = true;
                    }

                    if ($changed) {
                        DB::table('seo_meta')->where('id', $row->id)->update([
                            'keywords' => json_encode($decoded, JSON_UNESCAPED_UNICODE),
                        ]);
                    }
                }
            });
    }

    public function down(): void
    {
        // 截断不可逆（原文已丢），不回滚
    }
};
