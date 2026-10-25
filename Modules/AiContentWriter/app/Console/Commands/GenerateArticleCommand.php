<?php

namespace Modules\AiContentWriter\Console\Commands;

use App\Models\Post;
use App\Services\Ai\LlmManager;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use RuntimeException;

class GenerateArticleCommand extends Command
{
    protected $signature = 'ai:article
        {topic : 文章主题或目标关键词}
        {--lang= : 内容语言（默认：插件中心设置 → en）}
        {--words= : 目标字数（默认：插件中心设置 → 1200）}';

    protected $description = 'AI 生成一篇 SEO 文章并保存为草稿（后台审核后再发布）';

    public function handle(LlmManager $llm): int
    {
        $topic = (string) $this->argument('topic');
        // 设置优先级：命令行参数 → 插件中心数据库设置 → 默认值
        $lang = (string) ($this->option('lang') ?: plugin_setting('ai-content-writer', 'default_lang') ?: 'en');
        $words = (int) ($this->option('words') ?: plugin_setting('ai-content-writer', 'default_words') ?: 1200);
        $tone = (string) plugin_setting('ai-content-writer', 'tone', 'professional');
        $audience = trim((string) plugin_setting('ai-content-writer', 'target_audience', ''));
        $faqCount = max(0, (int) plugin_setting('ai-content-writer', 'faq_count', 4));
        $internalLinks = plugin_setting('ai-content-writer', 'internal_links', true);

        $toneText = match ($tone) {
            'consultative' => 'Consultative and advisory — guide the buyer like an experienced sourcing consultant.',
            'concise' => 'Concise and direct — short sentences, no filler, facts first.',
            default => 'Professional and trustworthy — measured claims, industry-standard terminology.',
        };

        $audienceText = $audience !== '' ? "\n目标受众：{$audience}。" : '';
        $linkText = $internalLinks
            ? "\n3. html 中放置 2 个形如 <!--PRODUCT_LINK:产品关键词--> 的内链占位注释，供后续替换为真实产品链接；"
            : '';

        $this->info("正在用 AI 生成文章草稿：{$topic}（{$lang}，约 {$words} 词）…");

        try {
            $raw = $llm->chat([
                ['role' => 'system', 'content' => "你是资深 B2B 外贸内容编辑，为外贸获客站撰写面向海外买家的 SEO 文章。写作风格：{$toneText} 只输出 JSON，不要输出任何其他文本或代码块标记。"],
                ['role' => 'user', 'content' => <<<PROMPT
主题：{$topic}
语言：{$lang}
目标字数：约 {$words} 词{$audienceText}

要求：
1. 面向海外采购商，包含选购要点与行业知识，自然融入 supplier / manufacturer from China 类 SEO 语境；
2. 输出 JSON：{"slug":"url-slug","title":"...","excerpt":"...","html":"<h2>…</h2><p>…</p>…","faq":[{"q":"...","a":"..."}]}；{$linkText}
3. faq 给出 {$faqCount} 条买家常见问题（用于 FAQPage 结构化数据，{$faqCount} 为 0 时输出空数组）；
4. 严禁编造具体产品参数、认证编号或客户案例。
PROMPT],
                ], purpose: 'article.generate');
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $data = json_decode($this->extractJson($raw), true);

        if (! is_array($data) || blank($data['title'] ?? null)) {
            $this->error('AI 返回内容无法解析为 JSON，原始内容已保存：storage/app/ai-articles/'.now()->format('Ymd-His').'.txt');
            @mkdir(storage_path('app/ai-articles'), 0755, true);
            file_put_contents(storage_path('app/ai-articles/'.now()->format('Ymd-His').'.txt'), $raw);

            return self::FAILURE;
        }

        $slug = $this->uniqueSlug((string) ($data['slug'] ?? '') ?: Str::slug($topic));

        $post = Post::create([
            'type' => 'post',
            'slug' => $slug,
            'status' => Post::STATUS_DRAFT,
            'is_ai_generated' => true,
            'title' => [$lang => (string) $data['title']],
            'excerpt' => [$lang => (string) ($data['excerpt'] ?? '')],
            'content' => [$lang => (string) ($data['html'] ?? '')],
        ]);

        $this->info("草稿已创建：Post #{$post->id}（slug: {$slug}）");
        $this->line('下一步：在后台审核内容、替换内链占位后发布。');

        return self::SUCCESS;
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
