<?php

namespace Modules\TransCoverage\Services;

use App\Models\Post;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * AI 补翻封装：为 Post 一次性补齐缺失语言的翻译（标题/摘要/正文/SEO 元数据）。
 *
 * 依赖 AiContentWriter 插件的 PostTranslator；模块未安装时明确抛错/提示，
 * 而不是让看板崩溃。Product 不做 AI 动作（看板仅展示缺口）。
 */
class TranslationFixer
{
    /** AiContentWriter 插件是否可用（class_exists 防御，模块未安装时为 false） */
    public static function available(): bool
    {
        return class_exists(\Modules\AiContentWriter\Services\PostTranslator::class);
    }

    /**
     * 把 $post 的指定语言补翻出来；成功后清掉补翻前的覆盖率缓存，看板立即刷新。
     *
     * @param  list<string>  $locales  目标语言（一般来自 CoverageScanner::missingLocalesFor）
     * @return list<string>  实际翻译成功并落库的语言
     *
     * @throws RuntimeException 插件未安装、无目标语言或翻译失败时
     */
    public function fix(Post $post, array $locales): array
    {
        if (! self::available()) {
            throw new RuntimeException('未安装 AiContentWriter 插件，无法 AI 补翻。');
        }

        if ($locales === []) {
            throw new RuntimeException('该文章没有缺失语言，无需补翻。');
        }

        $scanner = app(CoverageScanner::class);

        // 记录补翻前的缓存 key：翻译落库后 updated_at 变化会自然换新 key，
        // 但旧 key 的 10 分钟缓存仍残留，这里显式清掉，保证立即重算。
        $keyBefore = $scanner->cacheKey();

        // 与写作室一致：先应用插件自定义模型覆盖（PostTranslator 构造器也会调，双保险）
        \Modules\AiContentWriter\Services\ArticleStudio::applyCustomProvider();

        try {
            $translated = app(\Modules\AiContentWriter\Services\PostTranslator::class)
                ->translate($post, $locales);
        } catch (\Throwable $e) {
            throw new RuntimeException('AI 补翻失败：'.$e->getMessage(), 0, $e);
        }

        Cache::forget($keyBefore);

        return $translated;
    }
}
