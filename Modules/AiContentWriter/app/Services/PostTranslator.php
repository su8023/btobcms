<?php

namespace Modules\AiContentWriter\Services;

use App\Models\Post;
use App\Services\Ai\LlmManager;
use Illuminate\Support\Facades\DB;
use Modules\AiContentWriter\Support\JsonExtractor;
use RuntimeException;

/**
 * 文章一键翻译：把已有内容（标题/摘要/正文/SEO 元数据）翻译到目标语言。
 * 每语言一次 LLM 调用返回 JSON；只新增语言不覆盖已有翻译。
 * 译文完整落库（模型层事务由调用方保证幂等：updateOrCreate 语义天然可重跑）。
 */
class PostTranslator
{
    public function __construct(
        private readonly LlmManager $llm,
    ) {
        // 与写作室一致：插件自定义模型优先
        ArticleStudio::applyCustomProvider();
    }

    /**
     * @param  list<string>  $locales
     * @return list<string> 实际翻译成功的语言
     */
    public function translate(Post $post, array $locales): array
    {
        $sourceLang = $this->sourceLang($post);
        $source = [
            'title' => (string) $post->getTranslation('title', $sourceLang, false),
            'excerpt' => (string) $post->getTranslation('excerpt', $sourceLang, false),
            'content' => (string) $post->getTranslation('content', $sourceLang, false),
        ];

        if ($source['title'] === '' || $source['content'] === '') {
            throw new RuntimeException('文章缺少源语言标题或正文，无法翻译');
        }

        // 成本护栏：整篇 HTML 进 prompt，超长文一次翻译的 token 成本失控且输出易被截断
        $wordCount = str_word_count(strip_tags($source['content']));
        if ($wordCount > 8000) {
            throw new RuntimeException("正文约 {$wordCount} 词，超过单次翻译上限（8000 词）。请精简后翻译，或分段处理。");
        }

        $meta = $post->seoMeta;
        $translated = [];

        foreach ($locales as $locale) {
            try {
                $data = $this->translateLocale($source, $sourceLang, $locale, $meta);
            } catch (\Throwable $e) {
                // 单语言失败不阻塞其余语言，最后统一汇报
                report($e);
                continue;
            }

            foreach (['title', 'excerpt', 'content'] as $field) {
                if (filled($data[$field] ?? null)) {
                    $post->setTranslation($field, $locale, (string) $data[$field]);
                }
            }

            // 元数据与正文一起提交：
            // 之前 meta->save() 与 post->save() 是两次独立写入，
            // 中间失败就会留下"元数据已翻译、正文还是旧语言"的半翻译状态。
            // 闭包返回 $meta：新建的元数据行要带回外层，下一轮语言才能复用同一行。
            $meta = DB::transaction(function () use ($data, $locale, $post, $meta) {
                if ($meta === null) {
                    // 文章从没填过 SEO：创建空元数据行，让翻译结果有落点
                    $meta = $post->seoMeta()->create();
                }

                $metaTranslations = [
                    'title' => [$locale => (string) ($data['seo_title'] ?? '')],
                    'description' => [$locale => (string) ($data['seo_description'] ?? '')],
                    'keywords' => [$locale => (string) ($data['seo_keywords'] ?? '')],
                ];
                foreach ($metaTranslations as $field => $values) {
                    if (filled($values[$locale])) {
                        $meta->setTranslation($field, $locale, $values[$locale]);
                    }
                }
                $meta->save();

                $post->save();

                return $meta;
            });

            $translated[] = $locale;
        }

        return $translated;
    }

    /**
     * @return array{title: string, excerpt: string, content: string, seo_title: string, seo_description: string, seo_keywords: string}
     */
    protected function translateLocale(array $source, string $from, string $to, $meta): array
    {
        $seoText = $meta !== null ? sprintf(
            "\nSEO 标题：%s\nSEO 描述：%s",
            (string) $meta->getTranslation('title', $from, false),
            (string) $meta->getTranslation('description', $from, false),
        ) : '';

        $raw = $this->llm->chat([
            ['role' => 'system', 'content' => '你是专业 B2B 外贸内容译员。只输出 JSON，不要输出任何其他文本或代码块标记。'],
            ['role' => 'user', 'content' => <<<PROMPT
把以下 B2B 外贸文章从 {$from} 翻译成 {$to}。

标题：{$source['title']}
摘要：{$source['excerpt']}
正文 HTML：
{$source['content']}{$seoText}

要求：
1. 保留正文全部 HTML 标签结构（h2/p/ul/li/table 等）不增不减；
2. 术语符合目标语言行业习惯，品牌/参数/数字不变；
3. SEO 标题控制在 60 字符内、SEO 描述 70~160 字符，自然融入目标语言关键词；
4. 输出 JSON：{"title":"...","excerpt":"...","content":"<h2>...</h2>...","seo_title":"...","seo_description":"...","seo_keywords":"keyword1, keyword2"}
PROMPT],
        ], purpose: 'post.translate');

        $data = json_decode($this->extractJson($raw), true);
        if (! is_array($data) || blank($data['title'] ?? null) || blank($data['content'] ?? null)) {
            throw new RuntimeException("AI 返回的 {$to} 译文无法解析");
        }

        return [
            'title' => (string) $data['title'],
            'excerpt' => (string) ($data['excerpt'] ?? ''),
            'content' => (string) $data['content'],
            'seo_title' => (string) ($data['seo_title'] ?? ''),
            'seo_description' => (string) ($data['seo_description'] ?? ''),
            'seo_keywords' => (string) ($data['seo_keywords'] ?? ''),
        ];
    }

    /** 源语言：默认 en，缺失时取第一个有标题的语言 */
    protected function sourceLang(Post $post): string
    {
        $translations = $post->getTranslations('title');

        return isset($translations[default_locale()]) ? default_locale() : (array_key_first($translations) ?? default_locale());
    }

    protected function extractJson(string $raw): string
    {
        // 按括号配对提取（贪婪正则会被 JSON 前后的闲聊文本带偏）
        return JsonExtractor::extract($raw) ?? $raw;
    }
}
