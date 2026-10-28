<?php

namespace Modules\AutoSeo\Listeners;

use App\Events\PostPublished;
use App\Models\Post;
use App\Models\SeoMeta;
use App\Services\Ai\LlmManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Modules\AutoSeo\Support\IndexNow;
use Throwable;

/**
 * 全自动 SEO 闭环（队列执行，不阻塞保存/发布动作）：
 *   1. AI 生成 SEO 标题/描述/关键词（按字段缺失补全，已有字段不覆盖）
 *   2. 已发布文章推送全部语言变体到 IndexNow（Bing/Yandex 即时收录）
 * 触发点：发布（PostPublished）。任一步失败仅记日志，不影响站点运行。
 */
class OptimizeAndSubmitPost implements ShouldQueue
{
    public int $timeout = 300;

    public int $tries = 2;

    public function __construct()
    {
        $this->queue = 'default';
    }

    public function handle(PostPublished $event): void
    {
        $post = $event->post;

        $this->generateSeoMeta($post);
        $this->submitToIndexNow($post);
    }

    /**
     * AI 生成 SEO 标题/描述/关键词：逐语言检测缺失并补全（已有内容不覆盖）。
     * 只处理文章实际有翻译内容的语言——空语言不生成孤立 SEO。
     */
    protected function generateSeoMeta(Post $post): void
    {
        try {
            $existing = SeoMeta::firstOrNew(['metable_type' => Post::class, 'metable_id' => $post->id]);

            foreach (site_locales() as $lang) {
                $title = trim((string) $post->getTranslation('title', $lang, false));
                if ($title === '') {
                    continue; // 该语言没有内容，跳过
                }

                $needsTitle = blank($existing->getTranslation('title', $lang, false));
                $needsDesc = blank($existing->getTranslation('description', $lang, false));
                $needsKeywords = blank($existing->getTranslation('keywords', $lang, false));

                if (! ($needsTitle || $needsDesc || $needsKeywords)) {
                    continue; // 该语言 TDK 齐全
                }

                $excerpt = trim((string) $post->getTranslation('excerpt', $lang, false));
                $content = mb_substr(strip_tags((string) $post->getTranslation('content', $lang, false)), 0, 3000);

                $fields = implode('、', array_filter([
                    $needsTitle ? 'seo_title' : '',
                    $needsDesc ? 'seo_description' : '',
                    $needsKeywords ? 'keywords' : '',
                ]));

                $raw = app(LlmManager::class)->chat([
                    ['role' => 'system', 'content' => '你是 SEO 专家。只输出 JSON。'],
                    ['role' => 'user', 'content' => <<<PROMPT
用 {$lang} 为以下文章生成 SEO 元信息（只需输出：{$fields}）。
标题：{$title}
摘要：{$excerpt}
正文开头：{$content}

输出 JSON：{"seo_title":"≤60 字符、含核心关键词","seo_description":"140~155 字符、吸引点击","keywords":"逗号分隔的 5~8 个搜索关键词"}
PROMPT],
                ], purpose: 'seo.meta');

                $data = json_decode((string) (preg_match('/\{.*\}/s', $raw, $m) ? $m[0] : ''), true) ?: [];

                if ($needsTitle && ! blank($data['seo_title'] ?? null)) {
                    $existing->setTranslation('title', $lang, (string) $data['seo_title']);
                }
                if ($needsDesc && ! blank($data['seo_description'] ?? null)) {
                    $existing->setTranslation('description', $lang, (string) $data['seo_description']);
                }
                if ($needsKeywords && ! blank($data['keywords'] ?? null)) {
                    $existing->setTranslation('keywords', $lang, (string) $data['keywords']);
                }
                $existing->save();
            }
        } catch (Throwable $e) {
            Log::warning('AutoSeo: TDK 生成失败', ['post' => $post->id, 'e' => $e->getMessage()]);
        }
    }

    /**
     * IndexNow 推送（仅已发布；草稿跳过）。
     */
    protected function submitToIndexNow(Post $post): void
    {
        if ($post->status !== Post::STATUS_PUBLISHED) {
            return;
        }

        try {
            IndexNow::submitPostUrls('/blog/'.$post->slug);
        } catch (Throwable $e) {
            Log::warning('AutoSeo: IndexNow 推送失败', ['post' => $post->id, 'e' => $e->getMessage()]);
        }
    }
}
