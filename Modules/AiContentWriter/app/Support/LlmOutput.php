<?php

namespace Modules\AiContentWriter\Support;

use Illuminate\Support\Str;

/**
 * LLM 长文输出处理工具：围栏剥离 / 截断修复 / 原始响应留档。
 *
 * 写作室正文与文章翻译共用（长文改裸 HTML 直出后，这三步是共同收尾）。
 */
class LlmOutput
{
    /**
     * 剥离 markdown 代码围栏（```html ... ```）。
     */
    public static function stripFence(string $raw): string
    {
        if (preg_match('/```[a-zA-Z]*\s*(.*?)\s*```/s', $raw, $m) === 1) {
            return trim($m[1]);
        }

        return trim($raw);
    }

    /**
     * 截断修复：去掉尾部残缺标签并逆序补全未闭合的块级标签。
     */
    public static function closeTruncatedHtml(string $html): string
    {
        $html = trim($html);

        // 截断常停在标签中间（"<p>xxx" 甚至 "<p"）：去掉最后一个 '>' 之后的残段
        if (! str_ends_with($html, '>')) {
            $pos = strrpos($html, '>');

            if ($pos === false) {
                return $html;
            }

            $html = substr($html, 0, $pos + 1);
        }

        if (preg_match_all('/<(\/?)(p|h2|h3|h4|ul|ol|li|blockquote|strong|em|table|thead|tbody|tr|td|th|div)\b[^>]*>/i', $html, $tags, PREG_SET_ORDER)) {
            $stack = [];

            foreach ($tags as $t) {
                if ($t[1] === '/') {
                    array_pop($stack);
                } elseif (! str_ends_with($t[0], '/>')) {
                    $stack[] = strtolower($t[2]);
                }
            }

            foreach (array_reverse($stack) as $name) {
                $html .= "</{$name}>";
            }
        }

        return $html;
    }

    /**
     * 原始响应留档：网页端的解析失败此前不留痕，运营方无法自查也无法报修。
     *
     * @return string 相对 storage 的存档路径
     */
    public static function dumpRaw(string $raw, string $stage): string
    {
        $dir = 'ai-articles';
        $path = storage_path("app/{$dir}");

        if (! is_dir($path)) {
            @mkdir($path, 0775, true);
        }

        $file = "{$dir}/{$stage}-".now()->format('Ymd-His').'-'.Str::random(4).'.txt';
        @file_put_contents(storage_path("app/{$file}"), $raw);

        return "storage/app/{$file}";
    }
}
