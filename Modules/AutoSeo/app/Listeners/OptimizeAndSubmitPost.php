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
 * 文章发布后的全自动 SEO 闭环（队列执行，不阻塞发布动作）：
 *   1. SEO 标题/描述缺失时用 AI 自动生成（写入 seo_meta，前台立即生效）
 *   2. 推送该文章全部语言变体 URL 到 IndexNow（Bing/Yandex 即时收录）
 * 任一步失败仅记日志，不影响站点运行。
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
        $lang = app()->getLocale();

        // ---- 1. AI 自动生成 SEO 标题/描述（缺失时） ----
        try {
            $title = trim((string) $post->getTranslation('title', $lang));
            $excerpt = trim((string) $post->getTranslation('excerpt', $lang));
            $content = mb_substr(strip_tags((string) $post->getTranslation('content', $lang)), 0, 3000);

            $existing = SeoMeta::where('metable_type', Post::class)->where('metable_id', $post->id)->first();
            $needsTitle = ! $existing || blank($existing->getTranslation('title', $lang));
            $needsDesc = ! $existing || blank($existing->getTranslation('description', $lang));

            if (($needsTitle || $needsDesc) && $title !== '') {
                $seo = app(LlmManager::class)->chat([
                    ['role' => 'system', 'content' => '你是 SEO 专家。只输出 JSON。'],
                    ['role' => 'user', 'content' => <<<PROMPT
为以下文章生成 SEO 元信息。
标题：{$title}
摘要：{$excerpt}
正文开头：{$content}

输出 JSON：{"seo_title":"≤60 字符、含核心关键词","seo_description":"140~155 字符、吸引点击的描述"}
PROMPT],
                ], purpose: 'seo.meta');

                $data = json_decode((string) preg_match('/\{.*\}/s', $seo, $m) ? $m[0] : '', true) ?: [];

                $meta = SeoMeta::firstOrNew(['metable_type' => Post::class, 'metable_id' => $post->id]);
                if ($needsTitle && ! blank($data['seo_title'] ?? null)) {
                    $meta->setTranslation('title', $lang, (string) $data['seo_title']);
                }
                if ($needsDesc && ! blank($data['seo_description'] ?? null)) {
                    $meta->setTranslation('description', $lang, (string) $data['seo_description']);
                }
                $meta->save();
                if (app()->environment("testing")) { logger()->info("tdk saved", ["id" => $meta->id, "title" => mb_substr((string) $meta->getTranslation("title", $lang), 0, 40)]); }
            }
        } catch (Throwable $e) {
            Log::warning('AutoSeo: TDK 生成失败', ['post' => $post->id, 'e' => $e->getMessage()]);
        }

        // ---- 2. IndexNow 推送（TDK 成败不影响收录推送） ----
        try {
            $path = '/blog/'.$post->slug;
            IndexNow::submitPostUrls($path);
        } catch (Throwable $e) {
            Log::warning('AutoSeo: IndexNow 推送失败', ['post' => $post->id, 'e' => $e->getMessage()]);
        }
    }

    public function failed(PostPublished $event, Throwable $e): void
    {
        Log::error('AutoSeo: 任务异常', ['post' => $event->post->id, 'e' => $e->getMessage()]);
    }
}
