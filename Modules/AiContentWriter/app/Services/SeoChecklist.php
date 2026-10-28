<?php

namespace Modules\AiContentWriter\Services;

/**
 * 发布前 SEO 体检（纯规则、零 AI 调用、毫秒级）：
 * 输入文章编辑表单的 state（含未保存修改），输出人话问题清单。
 */
class SeoChecklist
{
    /**
     * @param  array<string, mixed>  $state  Filament 表单 state：title.en / excerpt.en / content.en / seo.title.en ...
     * @return list<string> 问题列表（空 = 全部通过）
     */
    public function checkState(array $state): array
    {
        $problems = [];
        $seo = is_array($state['seo'] ?? null) ? $state['seo'] : [];
        $checkedAny = false;

        foreach (site_locales() as $locale) {
            $title = trim((string) data_get($state, "title.{$locale}", ''));
            $excerpt = trim((string) data_get($state, "excerpt.{$locale}", ''));
            $content = (string) data_get($state, "content.{$locale}", '');

            // 只检查实际填了内容的语言
            if ($title === '' && $content === '') {
                continue;
            }
            $checkedAny = true;
            $label = config("site.native_names.{$locale}", $locale);

            $titleLen = mb_strlen($title);
            if ($titleLen > 0 && ($titleLen < 20 || $titleLen > 80)) {
                $problems[] = "[{$label}] 标题长度 {$titleLen} 字符，建议 20~80";
            }

            if ($content !== '') {
                $wordCount = $this->wordCount($content);
                if ($wordCount < 300) {
                    $problems[] = "[{$label}] 正文仅约 {$wordCount} 词，B2B 长文建议 ≥600 词";
                }

                $h2Count = preg_match_all('/<h2[ >]/i', $content);
                if ($h2Count < 2) {
                    $problems[] = "[{$label}] 小节标题（H2）只有 {$h2Count} 个，建议 ≥2 个";
                }

                // 图片缺 alt
                $imgs = preg_match_all('/<img\b[^>]*>/i', $content);
                $imgsWithAlt = preg_match_all('/<img\b[^>]*\balt=["\'][^"\']+["\']/i', $content);
                if ($imgs > $imgsWithAlt) {
                    $problems[] = "[{$label}] 有 ".($imgs - $imgsWithAlt)." 张图片缺 alt 属性";
                }

                // 站内链接：相对路径或含本站域名的绝对链接都算
                $appHost = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
                $hasInternalLink = false;
                if (preg_match_all('/<a\b[^>]*href=["\']([^"\']*)["\']/i', $content, $m)) {
                    foreach ($m[1] as $href) {
                        if (preg_match('#^/[^/]#', $href) || ($appHost !== '' && str_contains(strtolower($href), $appHost))) {
                            $hasInternalLink = true;
                            break;
                        }
                    }
                }
                if (! $hasInternalLink) {
                    $problems[] = "[{$label}] 正文没有站内链接，建议加入产品/行业页内链（可在发布后用内链词典回织）";
                }
            }

            // SEO 元数据
            $seoTitle = trim((string) data_get($seo, "title.{$locale}", ''));
            $seoDesc = trim((string) data_get($seo, "description.{$locale}", ''));
            $seoKeywords = trim((string) data_get($seo, "keywords.{$locale}", ''));

            if ($seoTitle === '') {
                $problems[] = "[{$label}] SEO 标题未填（留空则发布时由 AutoSeo 自动生成）";
            } else {
                $len = mb_strlen($seoTitle);
                if ($len > 60) {
                    $problems[] = "[{$label}] SEO 标题 {$len} 字符，谷歌通常截断 60 字符之后的内容";
                }
            }

            if ($seoDesc === '') {
                $problems[] = "[{$label}] SEO 描述未填（留空则发布时由 AutoSeo 自动生成）";
            } else {
                $len = mb_strlen($seoDesc);
                if ($len < 70 || $len > 160) {
                    $problems[] = "[{$label}] SEO 描述 {$len} 字符，建议 70~160";
                }
            }

            if ($seoKeywords === '' && $excerpt !== '') {
                $problems[] = "[{$label}] SEO 关键词未填";
            }
        }

        if (! $checkedAny) {
            $problems[] = '还没有任何语言的内容可检查';
        }

        return $problems;
    }

    /** HTML 词数：Unicode 感知——拉丁/西里尔/阿语按词切，中日韩按字计（str_word_count 会把重音词切碎） */
    protected function wordCount(string $html): int
    {
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags($html)) ?? '');

        if ($text === '') {
            return 0;
        }

        preg_match_all('/[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}]/u', $text, $cjk);
        $words = preg_match_all("/[\p{L}\p{N}][\p{L}\p{N}'\-]*/u", preg_replace('/[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}]+/u', ' ', $text) ?? '');

        return count($cjk[0] ?? []) + (int) $words;
    }
}
