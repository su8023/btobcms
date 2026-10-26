<?php

namespace Modules\AiContentWriter\Services;

use App\Services\Ai\LlmManager;
use App\Models\Post;
use Illuminate\Support\Str;
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
        // 自定义模型：数据库设置优先于 .env（仅本插件生成时生效）
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
        if ($title === '' || empty($plan['outline'])) {
            throw new RuntimeException('方案缺少标题或大纲，无法生成');
        }

        $words = $words ?: (int) (plugin_setting('ai-content-writer', 'default_words', 0) ?: 1200);
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
        // FAQ 由方案阶段的问题列表 + 设置数量决定
        $faqTopics = collect($plan['faq'] ?? [])->take($faqCount)->pluck('q')->implode('；');
        $faqText = $faqCount > 0 ? "\nFAQ 题目（按这些写答案）：{$faqTopics}" : '';

        $raw = $this->llm->chat([
            ['role' => 'system', 'content' => "你是资深 B2B 外贸内容编辑，为外贸获客站撰写面向海外买家的 SEO 文章。写作风格：{$toneText} 只输出 JSON，不要输出任何其他文本或代码块标记。"],
            ['role' => 'user', 'content' => <<<PROMPT
标题：{$title}
语言：{$lang}
目标字数：约 {$words} 词{$audienceText}

按以下大纲撰写全文：
{$outlineText}

SEO 关键词（自然融入，不堆砌）：{$keywords}{$faqText}

要求：
1. 严格按大纲的小节顺序组织内容，每个 h2 下 2~4 段；
2. 面向海外采购商，包含选购要点与行业知识；
3. 输出 JSON：{"slug":"url-slug","excerpt":"2 句摘要","html":"<h2>…</h2><p>…</p>…","faq":[{"q":"...","a":"..."}]}；
4. 严禁编造具体产品参数、认证编号或客户案例。
PROMPT],
        ], purpose: 'article.generate');

        $data = json_decode($this->extractJson($raw), true);
        if (! is_array($data) || blank($data['title'] ?? null) && blank($title)) {
            throw new RuntimeException('AI 返回的正文无法解析，请重试。');
        }

        $slug = $this->uniqueSlug((string) ($data['slug'] ?? '') ?: Str::slug($title));

        $post = Post::create([
            'type' => 'post',
            'slug' => $slug,
            'status' => Post::STATUS_DRAFT,
            'is_ai_generated' => true,
            'title' => [$lang => $title],
            'excerpt' => [$lang => (string) ($data['excerpt'] ?? '')],
            'content' => [$lang => (string) ($data['html'] ?? '')],
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
    }

    protected function extractJson(string $raw): string
    {
        return preg_match('/\{.*\}/s', $raw, $m) ? $m[0] : $raw;
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
