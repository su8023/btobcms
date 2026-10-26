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
     * AI 生成 SEO 标题/描述/关键词：仅补缺失字段，已有内容不覆盖。
     */
    protected function generateSeoMeta(Post $post): void
    {
        try {
            $lang = app()->getLocale();
            $title = trim((string) $post->getTranslation('title', $lang));
            $excerpt = trim((string) $post->getTranslation('excerpt', $lang));
            $content = mb_substr(strip_tags((string) $post->getTranslation('content', $lang)), 0, 3000);

            $existing = SeoMeta::where('metable_type', Post::class)->where('metable_id', $post->id)->first();
            $needsTitle = ! $existing || blank($existing->getTranslation('title', $lang));
            $needsDesc = ! $existing || blank($existing->getTranslation('description', $lang));
            $needsKeywords = ! $existing || blank($existing->getTranslation('keywords', $lang));

            if (($needsTitle || $needsDesc || $needsKeywords) && $title !== '') {
                $fields = implode('、', array_filter([
                    $needsTitle ? 'seo_title' : '',
                    $needsDesc ? 'seo_description' : '',
                    $needsKeywords ? 'keywords' : '',
                ]));

                $raw = app(LlmManager::class)->chat([
                    ['role' => 'system', 'content' => '你是 SEO 专家。只输出 JSON。'],
                    ['role' => 'user', 'content' => <<<PROMPT
为以下文章生成 SEO 元信息（只需输出：{$fields}）。
标题：{$title}
摘要：{$excerpt}
正文开头：{$content}

输出 JSON：{"seo_title":"≤60 字符、含核心关键词","seo_description":"140~155 字符、吸引点击","keywords":"逗号分隔的 5~8 个搜索关键词"}
PROMPT],
                ], purpose: 'seo.meta');

                $data = json_decode((string) (preg_match('/\{.*\}/s', $raw, $m) ? $m[0] : ''), true) ?: [];

                $meta = SeoMeta::firstOrNew(['metable_type' => Post::class, 'metable_id' => $post->id]);
                if ($needsTitle && ! blank($data['seo_title'] ?? null)) {
                    $meta->setTranslation('title', $lang, (string) $data['seo_title']);
                }
                if ($needsDesc && ! blank($data['seo_description'] ?? null)) {
                    $meta->setTranslation('description', $lang, (string) $data['seo_description']);
                }
                if ($needsKeywords && ! blank($data['keywords'] ?? null)) {
                    $meta->setTranslation('keywords', $lang, (string) $data['keywords']);
                }
                $meta->save();
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
