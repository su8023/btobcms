<?php

namespace Modules\GeoBoost\Services;

use App\Models\Post;
use App\Services\Ai\LlmManager;
use App\Support\PluginGuard;
use Modules\AiContentWriter\Services\ArticleStudio;
use Modules\AiContentWriter\Support\JsonExtractor;
use Modules\AiContentWriter\Support\LlmOutput;
use Modules\GeoBoost\Models\GeoFaq;

/**
 * FAQ 生成器：文章正文 → N 组买家问答（FAQPage JSON-LD + 前台渲染共用）。
 * 一次 LLM 调用；生成结果整体替换该语言已有的 AI 来源 FAQ（manual 来源不动）。
 */
class FaqGenerator
{
    public function __construct(
        private readonly LlmManager $llm,
    ) {
        // 自定义模型覆盖来自 AiContentWriter 的设置：插件被停用（类仍在）时
        // 不应继续借用其配置烧 LLM 额度，走 PluginGuard 判定（类存在 + 模块启用）
        if (PluginGuard::available(ArticleStudio::class, 'AiContentWriter')) {
            ArticleStudio::applyCustomProvider();
        }
    }

    /**
     * 为文章生成 FAQ 并落库，返回写入条数。
     *
     * @throws \RuntimeException AI 返回无法解析时
     */
    public function generateFor(Post $post, string $locale): int
    {
        $content = (string) $post->getTranslation('content', $locale, false);
        if ($content === '') {
            throw new \RuntimeException('该语言还没有正文，先填充内容再生成 FAQ');
        }

        // 输入护栏：超长文不加截断会把 prompt 顶爆（对比 AutoSeo 截 3000 字符）；
        // FAQ 只需要内容主旨，去标签后截前 6000 字符足够
        $content = mb_substr(strip_tags($content), 0, 6000);

        $count = (int) config('geo-boost.faq_count', 5);
        $title = (string) $post->getTranslation('title', $locale, false);

        $prompt = <<<PROMPT
根据以下文章生成 {$count} 组海外买家常见问答（{$locale}）。

标题：{$title}
正文：
{$content}

要求：
1. 问题来自采购决策场景：MOQ、交期、认证、定制、物流、售后等；
2. 答案 40~80 词，信息密度高，可直接被 AI 搜索引擎引用；
3. 不编造具体参数；
4. 输出 JSON：{"faqs":[{"q":"...","a":"..."}]}
PROMPT;

        // 5 组答案的 JSON 转义面不小（引号/英文标点），一次漏转义整包报废——
        // 与写作室同源的失败模式：自动重试一次，失败留档原始响应（复核 B-P1）
        $faqs = collect();
        $lastRaw = '';

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $raw = $this->llm->chat([
                ['role' => 'system', 'content' => '你是 B2B 外贸 SEO 专家。只输出 JSON，不要输出任何其他文本或代码块标记。'],
                ['role' => 'user', 'content' => $prompt],
            ], purpose: 'geo.faq');

            $lastRaw = $raw;

            $data = json_decode($this->extractJson($raw), true);
            $faqs = collect($data['faqs'] ?? [])->filter(
                fn ($f) => is_array($f) && filled($f['q'] ?? null) && filled($f['a'] ?? null),
            );

            if ($faqs->isNotEmpty()) {
                break;
            }
        }

        if ($faqs->isEmpty()) {
            $dump = LlmOutput::dumpRaw($lastRaw, 'geoboost-faq');

            throw new \RuntimeException("AI 返回的 FAQ 无法解析（重试后仍失败），原始响应已存档：{$dump}");
        }

        \DB::transaction(function () use ($post, $locale, $faqs): void {
            // 只替换 AI 来源：人工编辑过的 FAQ 保留
            GeoFaq::where('faqable_type', Post::class)
                ->where('faqable_id', $post->id)
                ->where('locale', $locale)
                ->where('source', 'ai')
                ->delete();

            foreach ($faqs->values() as $i => $faq) {
                GeoFaq::create([
                    'faqable_type' => Post::class,
                    'faqable_id' => $post->id,
                    'locale' => $locale,
                    'sort' => $i,
                    'question' => mb_substr((string) $faq['q'], 0, 500),
                    'answer' => (string) $faq['a'],
                    'source' => 'ai',
                ]);
            }
        });

        cache()->forget('geoboost.faqs.'.$post->id.'.'.$locale);

        return $faqs->count();
    }

    protected function extractJson(string $raw): string
    {
        return JsonExtractor::extract($raw) ?? $raw;
    }
}
