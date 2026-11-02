<?php

namespace Modules\AiContentWriter\Services;

use App\Services\Ai\LlmManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Modules\AiContentWriter\Support\JsonExtractor;
use Modules\AiContentWriter\Support\LlmOutput;
use RuntimeException;

/**
 * 通用「实体一键翻译」引擎：短字段（标题+摘要+SEO）小 JSON + 正文裸 HTML 两段式。
 *
 * WHY 抽象：文章/产品/分类的翻译流程一模一样，只差字段名与「有没有摘要」；
 * PostTranslator/ProductTranslator/CategoryTranslator 各自给出 $config 即可，
 * 避免复制三份代码导致修复只落一处（历史上「长文塞 JSON」就是三处同步踩过的坑）。
 *
 * $config 契约：
 *   title_field  : 标题列名（Post=title, Product/Category=name）
 *   excerpt_field: 摘要列名（Post=excerpt；Product=summary；Category=null 时不请求）
 *   body_field   : 正文列名（Post/Product/Category=content/description/description）
 *   label        : 报错文案里的实体名（「文章」「产品」「分类」）
 *   require_body : 正文是否必填（文章 true；产品/分类 false，只填名称也能翻短字段）
 *
 * 每语言两次 LLM 调用；只新增语言不覆盖已有翻译；写前重取最新行防竞态回滚。
 */
abstract class RecordTranslator
{
    /** @var array{title_field:string, excerpt_field:?string, body_field:string, label:string} */
    abstract protected function config(): array;

    public function __construct(
        protected readonly LlmManager $llm,
    ) {
        // 与写作室一致：插件自定义模型优先
        ArticleStudio::applyCustomProvider();
    }

    /**
     * 把模型翻译到目标语言集合，返回实际成功的语言列表。
     *
     * @param  list<string>  $locales
     * @return list<string>
     *
     * @throws RuntimeException 缺源语言内容 / 超字数上限
     */
    public function translate(Model $model, array $locales): array
    {
        $config = $this->config();
        $sourceLang = $this->sourceLang($model, $config['title_field']);
        $title = (string) $model->getTranslation($config['title_field'], $sourceLang, false);
        $excerpt = $config['excerpt_field'] !== null
            ? (string) $model->getTranslation($config['excerpt_field'], $sourceLang, false)
            : '';
        $body = (string) $model->getTranslation($config['body_field'], $sourceLang, false);

        if ($title === '') {
            throw new RuntimeException("{$config['label']}缺少源语言标题，无法翻译");
        }

        // 正文是否为必填：文章必须（旧契约：只有标题没正文要拒绝）；
        // 产品/分类常只填名称，允许正文为空——短字段照样值得翻译
        if ($body === '' && ($config['require_body'] ?? true)) {
            throw new RuntimeException("{$config['label']}缺少源语言标题或正文，无法翻译");
        }

        // 成本护栏：整篇 HTML 进 prompt，超长文一次翻译的 token 成本失控且输出易被截断
        $wordCount = str_word_count(strip_tags($body));
        if ($wordCount > 8000) {
            throw new RuntimeException("正文约 {$wordCount} 词，超过单次翻译上限（8000 词）。请精简后翻译，或分段处理。");
        }

        $meta = $model->seoMeta ?? null;
        $translated = [];

        foreach ($locales as $locale) {
            try {
                $data = $this->translateLocale(
                    ['title' => $title, 'excerpt' => $excerpt, 'body' => $body],
                    $sourceLang,
                    $locale,
                    $meta,
                    $config,
                );
            } catch (\Throwable $e) {
                // 单语言失败不阻塞其余语言，最后统一汇报
                report($e);

                continue;
            }

            // 写入前重取最新行：队列任务的 LLM 调用耗时 1~2 分钟，期间编辑者可能
            // 保存过修改——save() 写的是多语言列整块 JSON，
            // 用开始时的旧快照回写会静默回滚窗口期的人工修改（复核 D-P1-2）。
            $model = $model->fresh();

            if ($model === null) {
                break; // 实体已被删除，剩余语言作废
            }

            foreach ([$config['title_field'] => 'title', $config['body_field'] => 'body'] as $column => $key) {
                if (filled($data[$key] ?? null)) {
                    $model->setTranslation($column, $locale, (string) $data[$key]);
                }
            }

            if ($config['excerpt_field'] !== null && filled($data['excerpt'] ?? null)) {
                $model->setTranslation($config['excerpt_field'], $locale, (string) $data['excerpt']);
            }

            // 元数据与正文一起提交（同一事务）：
            // 之前 meta->save() 与 model->save() 是两次独立写入，
            // 中间失败就会留下"元数据已翻译、正文还是旧语言"的半翻译状态。
            $meta = DB::transaction(function () use ($data, $locale, $model, $meta) {
                if ($meta === null) {
                    $meta = $model->seoMeta()->create();
                } else {
                    $meta = $meta->fresh() ?? $model->seoMeta()->create();
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

                $model->save();

                return $meta;
            });

            $translated[] = $locale;
        }

        return $translated;
    }

    /**
     * 单语言：两次调用（短字段小 JSON + 正文裸 HTML），带重试与留档。
     *
     * @param  array{title:string, excerpt:string, body:string}  $source
     * @param  array{title_field:string, excerpt_field:?string, body_field:string, label:string}  $config
     * @return array{title:string, excerpt:string, body:string, seo_title:string, seo_description:string, seo_keywords:string}
     *
     * @throws RuntimeException
     */
    protected function translateLocale(array $source, string $from, string $to, $meta, array $config): array
    {
        $seoText = $meta !== null ? sprintf(
            "\nSEO 标题：%s\nSEO 描述：%s",
            (string) $meta->getTranslation('title', $from, false),
            (string) $meta->getTranslation('description', $from, false),
        ) : '';

        $excerptLine = $config['excerpt_field'] !== null
            ? "\n摘要：{$source['excerpt']}"
            : '';

        // 输出 JSON 骨架按摘要是否可用动态生成
        $excerptKey = $config['excerpt_field'] !== null ? '"excerpt":"...",' : '';

        // -- 调用 A：短字段（标题/摘要/SEO）小 JSON
        $metaRaw = $this->llm->chat([
            ['role' => 'system', 'content' => '你是专业 B2B 外贸内容译员。只输出 JSON，不要输出任何其他文本或代码块标记。'],
            ['role' => 'user', 'content' => <<<PROMPT
把以下 B2B 外贸{$config['label']}的短字段从 {$from} 翻译成 {$to}。

标题：{$source['title']}{$excerptLine}{$seoText}

要求：SEO 标题控制在 60 字符内、SEO 描述 70~160 字符，自然融入目标语言关键词。
输出 JSON：{"title":"...",{$excerptKey}"seo_title":"...","seo_description":"...","seo_keywords":"keyword1, keyword2"}
PROMPT],
        ], purpose: $this->purposePrefix().'.meta');

        $metaData = json_decode($this->extractJson($metaRaw), true);

        if (! is_array($metaData) || blank($metaData['title'] ?? null)) {
            throw new RuntimeException("AI 返回的 {$to} 译文无法解析（标题字段缺失）");
        }

        // -- 调用 B：正文裸 HTML 直出（两次机会）；正文为空（产品/分类只填名称）时跳过
        if (trim($source['body']) === '') {
            return [
                'title' => (string) $metaData['title'],
                'excerpt' => (string) ($metaData['excerpt'] ?? ''),
                'body' => '',
                'seo_title' => (string) ($metaData['seo_title'] ?? ''),
                'seo_description' => (string) ($metaData['seo_description'] ?? ''),
                'seo_keywords' => (string) ($metaData['seo_keywords'] ?? ''),
            ];
        }

        $bodyPrompt = <<<PROMPT
把以下 B2B 外贸{$config['label']}正文从 {$from} 翻译成 {$to}。

正文 HTML：
{$source['body']}

要求：
1. 保留全部 HTML 标签结构（h2/p/ul/li/table 等）不增不减；
2. 术语符合 {$to} 行业习惯，品牌/参数/数字不变；
3. 只输出译文 HTML，不要 markdown 代码块，不要任何解释文字，第一个字符必须是 '<'。
PROMPT;

        $lastRaw = '';

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $result = $this->llm->chatResult([
                ['role' => 'system', 'content' => '你是专业 B2B 外贸内容译员。只输出译文 HTML，不要 markdown 代码块，不要任何解释文字。'],
                ['role' => 'user', 'content' => $bodyPrompt],
            ], purpose: $this->purposePrefix().'.body');

            $html = LlmOutput::stripFence(trim($result['content']));
            $lastRaw = $result['raw'] !== '' ? $result['raw'] : $result['content'];

            if (($result['finish_reason'] ?? '') === 'length') {
                $html = LlmOutput::closeTruncatedHtml($html);
            }

            // 净化：AI 输出绕过表单直接落库，前台原样渲染
            $html = LlmOutput::sanitize($html);

            if (str_starts_with($html, '<') && str_contains($html, '</')) {
                return [
                    'title' => (string) $metaData['title'],
                    'excerpt' => (string) ($metaData['excerpt'] ?? ''),
                    'body' => $html,
                    'seo_title' => (string) ($metaData['seo_title'] ?? ''),
                    'seo_description' => (string) ($metaData['seo_description'] ?? ''),
                    'seo_keywords' => (string) ($metaData['seo_keywords'] ?? ''),
                ];
            }
        }

        $dump = LlmOutput::dumpRaw($lastRaw, 'translate-'.$to);

        throw new RuntimeException(
            "AI 返回的 {$to} 译文无法解析。原因：输出不是 HTML 片段。"
            ."原始响应已存档：{$dump}（可发给技术人员排查）"
        );
    }

    /** 源语言：站点默认语言，缺失时取第一个有标题的语言 */
    protected function sourceLang(Model $model, string $titleField): string
    {
        $translations = $model->getTranslations($titleField);

        return isset($translations[default_locale()]) ? default_locale() : (array_key_first($translations) ?? default_locale());
    }

    protected function extractJson(string $raw): string
    {
        return JsonExtractor::extract($raw) ?? $raw;
    }

    /** ai_calls 里区分实体：post / product / category */
    abstract protected function purposePrefix(): string;
}
