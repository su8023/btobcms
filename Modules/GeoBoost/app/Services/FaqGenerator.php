<?php

namespace Modules\GeoBoost\Services;

use App\Models\Post;
use App\Services\Ai\LlmManager;
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
        // 自定义模型覆盖来自 AiContentWriter 的设置：模块可独立停用，先做存在性防御
        if (class_exists(\Modules\AiContentWriter\Services\ArticleStudio::class)) {
            \Modules\AiContentWriter\Services\ArticleStudio::applyCustomProvider();
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

        $count = (int) config('geo-boost.faq_count', 5);
        $title = (string) $post->getTranslation('title', $locale, false);

        $raw = $this->llm->chat([
            ['role' => 'system', 'content' => '你是 B2B 外贸 SEO 专家。只输出 JSON，不要输出任何其他文本或代码块标记。'],
            ['role' => 'user', 'content' => <<<PROMPT
根据以下文章生成 {$count} 组海外买家常见问答（{$locale}）。

标题：{$title}
正文：
{$content}

要求：
1. 问题来自采购决策场景：MOQ、交期、认证、定制、物流、售后等；
2. 答案 40~80 词，信息密度高，可直接被 AI 搜索引擎引用；
3. 不编造具体参数；
4. 输出 JSON：{"faqs":[{"q":"...","a":"..."}]}
PROMPT],
        ], purpose: 'geo.faq');

        $data = json_decode($this->extractJson($raw), true);
        $faqs = collect($data['faqs'] ?? [])->filter(
            fn ($f) => is_array($f) && filled($f['q'] ?? null) && filled($f['a'] ?? null),
        );
        if ($faqs->isEmpty()) {
            throw new \RuntimeException('AI 返回的 FAQ 无法解析，请重试');
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
        return preg_match('/\{.*\}/s', $raw, $m) ? $m[0] : $raw;
    }
}
