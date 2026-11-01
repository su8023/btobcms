<?php

namespace Modules\AiContentWriter\Services;

use App\Services\Ai\LlmManager;
use App\Models\Post;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\AiContentWriter\Support\JsonExtractor;
use Modules\AiContentWriter\Support\LlmOutput;
use RuntimeException;

/**
 * AI 写作室两段式生成：
 *   1) plan()   —— 输入主题/提示词 → 输出写作方案（标题、大纲、关键词、FAQ 列表），不落库
 *   2) write()  —— 用户确认/修改方案后 → 按方案全文生成 → 建草稿 Post
 *
 * 支持插件设置里的自定义模型（base_url/key/model 覆盖 .env 默认供应商）。
 */
class ArticleStudio
{
    public function __construct(
        private readonly LlmManager $llm,
    ) {
        self::applyCustomProvider();
    }

    /**
     * 自定义模型覆盖：数据库设置优先于 .env（写作室与翻译共用）。
     * 静态方法供各服务构造器调用，保证无论先实例化谁覆盖都生效。
     */
    public static function applyCustomProvider(): void
    {
        $baseUrl = (string) plugin_setting('ai-content-writer', 'custom_base_url', '');
        $apiKey = (string) plugin_setting('ai-content-writer', 'custom_api_key', '');
        $model = (string) plugin_setting('ai-content-writer', 'custom_model', '');

        if ($baseUrl !== '' || $apiKey !== '' || $model !== '') {
            $providerName = 'custom-studio-'.substr(md5($baseUrl.$model), 0, 8);
            config(['ai.providers.'.$providerName => [
                'base_url' => $baseUrl !== '' ? $baseUrl : (string) config('ai.providers.deepseek.base_url'),
                'key' => $apiKey !== '' ? $apiKey : (string) config('ai.providers.deepseek.key'),
                'model' => $model !== '' ? $model : (string) config('ai.providers.deepseek.model'),
            ]]);
            config(['ai.tiers.chat' => $providerName]);
        }
    }

    /**
     * 第一步：生成写作方案。
     *
     * @return array{title: string, outline: list<array{h2: string, points: list<string>}>, keywords: list<string>, faq: list<array{q: string}>, angle: string}
     *
     * @throws RuntimeException
     */
    public function plan(string $topic, string $extraPrompt = '', string $lang = 'en'): array
    {
        $topic = trim($topic);
        if ($topic === '') {
            throw new RuntimeException('主题不能为空');
        }

        $tone = (string) plugin_setting('ai-content-writer', 'tone', 'professional');
        $audience = (string) plugin_setting('ai-content-writer', 'target_audience', '');
        $audienceText = $audience !== '' ? "\n目标读者：{$audience}" : '';
        $extra = trim($extraPrompt) !== '' ? "\n额外要求（用户提示词，优先遵循）：".trim($extraPrompt) : '';

        $raw = $this->llm->chat([
            ['role' => 'system', 'content' => '你是资深 B2B 外贸内容策划。只输出 JSON，不要输出任何其他文本或代码块标记。'],
            ['role' => 'user', 'content' => <<<PROMPT
主题：{$topic}
语言：{$lang}{$audienceText}{$extra}

请为外贸获客站策划一篇 SEO 文章方案，输出 JSON：
{
  "title": "文章标题（含核心关键词）",
  "angle": "一句话写作切入角度",
  "outline": [{"h2":"二级标题","points":["该节要点1","要点2"]}],
  "keywords": ["自然融入的 SEO 关键词"],
  "faq": [{"q":"买家常见问题"}]
}

要求：
1. outline 4~6 个 h2 小节，覆盖选购要点/行业知识/供应链视角；
2. keywords 5~8 个，含 supplier / manufacturer from China 类语境；
3. faq 3~5 条；
4. 方案本身不要写正文。
PROMPT],
        ], purpose: 'article.plan');

        $data = json_decode($this->extractJson($raw), true);
        if (! is_array($data) || blank($data['title'] ?? null) || ! isset($data['outline'])) {
            throw new RuntimeException('AI 返回的方案无法解析，请重试或调整主题描述。');
        }

        return [
            'title' => (string) $data['title'],
            'angle' => (string) ($data['angle'] ?? ''),
            'outline' => collect($data['outline'] ?? [])->map(fn ($s) => [
                'h2' => (string) ($s['h2'] ?? ''),
                'points' => array_values(array_map('strval', (array) ($s['points'] ?? []))),
            ])->filter(fn ($s) => $s['h2'] !== '')->values()->all(),
            'keywords' => array_values(array_map('strval', (array) ($data['keywords'] ?? []))),
            'faq' => collect($data['faq'] ?? [])->map(fn ($f) => ['q' => (string) ($f['q'] ?? '')])->filter(fn ($f) => $f['q'] !== '')->values()->all(),
        ];
    }

    /**
     * 编辑页「AI 填充」用：按方案生成内容但不落库，返回原始产物。
     *
     * @param  array{title: string, outline: list<array{h2: string, points: list<string>}>, keywords: list<string>, faq: list<array{q: string}>, angle?: string}  $plan
     * @return array{slug: string, excerpt: string, html: string, keywords: list<string>}
     *
     * @throws RuntimeException
     */
    public function compose(array $plan, string $lang = 'en', ?int $words = null): array
    {
        $generated = $this->generateContent($plan, $lang, $words);

        return [
            'slug' => $generated['slug'],
            'excerpt' => $generated['excerpt'],
            'html' => $generated['html'],
            'keywords' => array_values(array_map('strval', $plan['keywords'] ?? [])),
        ];
    }

    /**
     * @return array{slug: string, excerpt: string, html: string}
     *
     * @throws RuntimeException
     */
    protected function generateContent(array $plan, string $lang, ?int $words): array
    {
        $title = trim((string) ($plan['title'] ?? ''));
        if ($title === '' || empty($plan['outline'])) {
            throw new RuntimeException('方案缺少标题或大纲，无法生成');
        }

        $words = $words ?: (int) (plugin_setting('ai-content-writer', 'default_words', 0) ?: 1200);

        // -- 调用 A：slug + 摘要（几十 token 的小 JSON，几乎不会解析失败）。
        // WHY 拆出来：旧实现把整篇 1500 词的 HTML 塞进一个 JSON 字符串返回，
        // 模型只要漏转义一个正文引号，整个 JSON 就废了——这正是
        // 「AI 返回的正文无法解析」的根因。长文不再进 JSON。
        $metaRaw = $this->llm->chat([
            ['role' => 'system', 'content' => '只输出 JSON，不要输出任何其他文本或代码块标记。'],
            ['role' => 'user', 'content' => <<<PROMPT
为 SEO 文章生成元数据，语言 {$lang}。标题：{$title}

输出 JSON：{"slug":"url-slug（小写连字符，贴合标题关键词）","excerpt":"2 句面向海外买家的摘要"}
PROMPT],
        ], purpose: 'article.meta');

        $meta = json_decode($this->extractJson($metaRaw), true) ?? [];

        $html = $this->generateBody($plan, $lang, $words, $title);

        return [
            'slug' => trim((string) ($meta['slug'] ?? '')),
            'excerpt' => trim((string) ($meta['excerpt'] ?? '')),
            'html' => $html,
        ];
    }

    /**
     * 生成正文（裸 HTML 直出，不走 JSON）。
     *
     * WHY 不用 JSON 包正文：长文的引号/换行转义是大模型最易出错的输出形态，
     * 一次漏转义就整包报废。裸 HTML 没有转义负担，即便被 max_tokens 截断，
     * 补齐未闭合标签后仍是可用的正文（JSON 截断则整包作废）。
     * 解析失败自动重试一次，两次的原始响应都留档到 storage/app/ai-articles/。
     *
     * @throws RuntimeException
     */
    protected function generateBody(array $plan, string $lang, int $words, string $title): string
    {
        $faqCount = (int) plugin_setting('ai-content-writer', 'faq_count', 3);
        $tone = (string) plugin_setting('ai-content-writer', 'tone', 'professional');
        $toneText = $tone !== 'professional' ? "语气：{$tone}。" : '';
        $audience = (string) plugin_setting('ai-content-writer', 'target_audience', '');
        $audienceText = $audience !== '' ? "\n目标读者：{$audience}" : '';

        $outlineText = collect($plan['outline'])->map(function ($s, $i) {
            $points = implode('；', $s['points'] ?? []);

            return ($i + 1).'. '.$s['h2'].($points !== '' ? "（要点：{$points}）" : '');
        })->implode("\n");

        $keywords = implode(', ', $plan['keywords'] ?? []);
        // FAQ 直接写进正文小节（AI 生成的 FAQ 答案本就不落库，进正文才能被读者看到）
        $faqTopics = collect($plan['faq'] ?? [])->take($faqCount)->pluck('q')->map(fn ($q) => '- '.$q)->implode("\n");
        $faqText = $faqTopics !== '' ? "\n\n文末加 FAQ 小节（<h2>Frequently Asked Questions</h2>），按这些问题写答案：\n{$faqTopics}" : '';

        $prompt = <<<PROMPT
标题：{$title}
语言：{$lang}
目标字数：约 {$words} 词{$audienceText}

按以下大纲撰写全文：
{$outlineText}

SEO 关键词（自然融入，不堆砌）：{$keywords}{$faqText}

要求：
1. 严格按大纲的小节顺序组织内容，每个 h2 下 2~4 段；
2. 面向海外采购商，包含选购要点与行业知识；
3. 只输出 HTML 片段（用 <h2>/<p> 标签，不要 <html>/<body> 包裹，不要 markdown 代码块，不要任何解释文字，第一个字符必须是 '<'）；
4. 严禁编造具体产品参数、认证编号或客户案例。
PROMPT;

        $lastRaw = '';
        $lastReason = '';

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $result = $this->llm->chatResult([
                ['role' => 'system', 'content' => "你是资深 B2B 外贸内容编辑，为外贸获客站撰写面向海外买家的 SEO 文章。写作风格：{$toneText} 只输出 HTML 片段，不要 markdown 代码块，不要任何解释文字。"],
                ['role' => 'user', 'content' => $prompt],
            ], purpose: 'article.generate');

            $html = LlmOutput::stripFence(trim($result['content']));
            $lastRaw = $result['raw'] !== '' ? $result['raw'] : $result['content'];

            // 截断容忍：finish_reason=length 时补齐未闭合标签，正文少一节好过整包报废
            if (($result['finish_reason'] ?? '') === 'length') {
                $html = LlmOutput::closeTruncatedHtml($html);
            }

            // 净化：AI 输出绕过 RichEditor 表单直接落库，前台原样渲染（见 LlmOutput::sanitize）
            $html = LlmOutput::sanitize($html);

            // 有效性：必须是 HTML（以 < 开头且含块级标签），而不是道歉文本或半截 JSON
            if (str_starts_with($html, '<') && preg_match('/<(h2|h3|p)\b/i', $html) === 1) {
                return $html;
            }

            $lastReason = '输出不是 HTML 片段（开头：'.mb_substr($html, 0, 60).'）';
        }

        $dump = LlmOutput::dumpRaw($lastRaw, 'body');

        throw new RuntimeException(
            'AI 返回的正文无法解析，请重试。'
            ."原因：{$lastReason}。"
            ."原始响应已存档：{$dump}（可发给技术人员排查）"
        );
    }

    /**
     * 第二步：按（可能被用户编辑过的）方案生成全文并建草稿。
     *
     * @param  array{title: string, outline: list<array{h2: string, points: list<string>}>, keywords: list<string>, faq: list<string>, angle?: string}  $plan
     * @return Post
     *
     * @throws RuntimeException
     */
    public function write(array $plan, string $lang = 'en', ?int $words = null): Post
    {
        $title = trim((string) ($plan['title'] ?? ''));
        $generated = $this->generateContent($plan, $lang, $words);

        $slug = $this->uniqueSlug($generated['slug'] ?: Str::slug($title));

        // 文章与 SEO 元数据必须一起提交：
        // 之前分两次写，第二次失败就会留下"草稿存在但没有 SEO 关键词"的半成品。
        $post = DB::transaction(function () use ($plan, $lang, $title, $generated, $slug): Post {
            $post = Post::create([
                'type' => 'post',
                'slug' => $slug,
                'status' => Post::STATUS_DRAFT,
                'is_ai_generated' => true,
                'title' => [$lang => $title],
                'excerpt' => [$lang => $generated['excerpt']],
                'content' => [$lang => $generated['html']],
            ]);

            // 方案阶段的 SEO 关键词直接落库（写完即有，发布时 AutoSeo 只需补标题/描述）
            $keywords = implode(', ', $plan['keywords'] ?? []);

            if ($keywords !== '') {
                \App\Models\SeoMeta::create([
                    'metable_type' => Post::class,
                    'metable_id' => $post->id,
                    'keywords' => [$lang => $keywords],
                ]);
            }

            return $post;
        });

        return $post;
    }

    protected function extractJson(string $raw): string
    {
        // 按括号配对提取（贪婪正则会被 JSON 前后的闲聊文本带偏）
        return JsonExtractor::extract($raw) ?? $raw;
    }

    protected function uniqueSlug(string $slug): string
    {
        $slug = Str::slug($slug) ?: 'post-'.Str::random(6);
        $base = $slug;
        $i = 2;

        while (Post::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
