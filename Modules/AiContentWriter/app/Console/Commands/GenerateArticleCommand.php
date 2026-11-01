<?php

namespace Modules\AiContentWriter\Console\Commands;

use App\Models\Post;
use App\Services\Ai\LlmManager;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Modules\AiContentWriter\Services\ArticleStudio;
use Modules\AiContentWriter\Support\JsonExtractor;
use Modules\AiContentWriter\Support\LlmOutput;
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
        // 自定义模型覆盖：写作室/翻译服务在各自构造器里会调用，这里必须显式调用一次，
        // 否则后台配置的 custom_base_url / custom_model 对命令行完全不生效
        //（命令注入的是 LlmManager，不经过 ArticleStudio）。
        ArticleStudio::applyCustomProvider();

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

        // 两段式（与写作室正文同源方案）：整篇 HTML 塞 JSON 的单次调用
        // 会因转义/截断整包报废——CLI 曾是「无法解析为 JSON」的惯犯（修复 B-P0）。
        try {
            // 调用 A：短字段（slug/title/excerpt）小 JSON
            $metaRaw = $llm->chat([
                ['role' => 'system', 'content' => '只输出 JSON，不要输出任何其他文本或代码块标记。'],
                ['role' => 'user', 'content' => <<<PROMPT
为 SEO 文章生成元数据，语言 {$lang}。主题：{$topic}

输出 JSON：{"slug":"url-slug（小写连字符，贴合主题关键词）","title":"文章标题（含核心关键词）","excerpt":"2 句面向海外买家的摘要"}
PROMPT],
            ], purpose: 'article.meta');

            $meta = json_decode(JsonExtractor::extract($metaRaw) ?? '', true);

            if (! is_array($meta) || blank($meta['title'] ?? null)) {
                $dump = LlmOutput::dumpRaw($metaRaw, 'cli-meta');

                $this->error("AI 返回的元数据无法解析为 JSON，原始内容已保存：{$dump}");

                return self::FAILURE;
            }

            // 调用 B：正文裸 HTML 直出（含 FAQ 小节与内链占位）
            $faqText = $faqCount > 0
                ? "\n4. 文末加 FAQ 小节（<h2>Frequently Asked Questions</h2>），给出 {$faqCount} 条买家常见问题及 40~80 词答案（用于 FAQPage 结构化数据）；"
                : '';

            $bodyPrompt = <<<PROMPT
主题：{$topic}
语言：{$lang}
目标字数：约 {$words} 词{$audienceText}

要求：
1. 面向海外采购商，包含选购要点与行业知识，自然融入 supplier / manufacturer from China 类 SEO 语境；
2. 只输出 HTML 片段（用 <h2>/<p> 标签，不要 <html>/<body> 包裹，不要 markdown 代码块，不要任何解释文字，第一个字符必须是 '<'）；{$linkText}{$faqText}
5. 严禁编造具体产品参数、认证编号或客户案例。
PROMPT;

            $html = '';

            for ($attempt = 1; $attempt <= 2; $attempt++) {
                $result = $llm->chatResult([
                    ['role' => 'system', 'content' => "你是资深 B2B 外贸内容编辑，为外贸获客站撰写面向海外买家的 SEO 文章。写作风格：{$toneText} 只输出 HTML 片段，不要 markdown 代码块，不要任何解释文字。"],
                    ['role' => 'user', 'content' => $bodyPrompt],
                ], purpose: 'article.generate.body');

                $html = LlmOutput::stripFence(trim($result['content']));

                // 截断容忍：补齐未闭合标签（与写作室正文同源）
                if (($result['finish_reason'] ?? '') === 'length') {
                    $html = LlmOutput::closeTruncatedHtml($html);
                }

                // 净化：AI 输出直接落库、前台原样渲染（见 LlmOutput::sanitize）
                $html = LlmOutput::sanitize($html);

                if (str_starts_with($html, '<') && preg_match('/<(h2|h3|p)\b/i', $html) === 1) {
                    break;
                }

                $html = '';

                if ($attempt === 1) {
                    $this->warn('第 1 次正文输出不是 HTML，自动重试…');
                }
            }

            if ($html === '') {
                $dump = LlmOutput::dumpRaw($result['raw'] ?? '', 'cli-body');

                $this->error("AI 返回的正文无法解析（两次尝试均失败），原始内容已保存：{$dump}");

                return self::FAILURE;
            }
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $slug = $this->uniqueSlug((string) ($meta['slug'] ?? '') ?: Str::slug($topic));

        $post = Post::create([
            'type' => 'post',
            'slug' => $slug,
            'status' => Post::STATUS_DRAFT,
            'is_ai_generated' => true,
            'title' => [$lang => (string) $meta['title']],
            'excerpt' => [$lang => (string) ($meta['excerpt'] ?? '')],
            'content' => [$lang => $html],
        ]);

        $this->info("草稿已创建：Post #{$post->id}（slug: {$slug}）");
        $this->line('下一步：在后台审核内容、替换内链占位后发布。');

        return self::SUCCESS;
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
