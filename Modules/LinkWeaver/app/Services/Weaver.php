<?php

namespace Modules\LinkWeaver\Services;

use App\Models\Post;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\DB;
use Modules\LinkWeaver\Models\AppliedLink;
use Modules\LinkWeaver\Models\LinkTerm;
use Throwable;

/**
 * 内链织入器：把词典关键词包上 <a> 织进文章正文。
 *
 * 用 DOM 而非正则：保证只替换纯文本节点、永不改写已有的 <a> 标签内部。
 * 匹配用字节级 stripos（ASCII 关键词大小写不敏感；非 ASCII 关键词如西语
 * 重音词退化为精确匹配，找不到即跳过，不会错链）。
 */
class Weaver
{
    /**
     * 对一篇文章的所有语言正文织链，返回本次新增链接数。
     * 幂等：applied_links 表保证同一词在同一文章同一语言只织一次。
     */
    public function weavePost(Post $post): int
    {
        $total = 0;

        // 缩小并发发布竞态窗口：以库中最新内容为准
        $post = $post->fresh() ?? $post;

        foreach ($post->getTranslations('content') as $locale => $html) {
            if (! is_string($html) || trim($html) === '') {
                continue;
            }

            $applied = AppliedLink::where('post_id', $post->id)
                ->where('locale', $locale)
                ->pluck('term_id')
                ->all();

            $terms = LinkTerm::query()
                ->where('enabled', true)
                ->where(fn ($q) => $q->whereNull('locale')->orWhere('locale', $locale))
                ->when($applied !== [], fn ($q) => $q->whereNotIn('id', $applied))
                ->orderByDesc('id')
                ->get();

            if ($terms->isEmpty()) {
                continue;
            }

            [$newHtml, $appliedLinks] = $this->weaveHtml(
                $html,
                $terms,
                (int) config('link-weaver.max_links_per_post'),
            );

            if ($appliedLinks !== []) {
                $content = $post->getTranslations('content');
                $content[$locale] = $newHtml;
                $post->setTranslations('content', $content);
                // PostPublished 只由后台保存动作发出，这里 save 不会重复触发
                $post->save();

                // 幂等表批量写入：唯一约束兜底并发重复，事务保证与正文一致
                \DB::transaction(function () use ($post, $locale, $appliedLinks): void {
                    AppliedLink::upsert(
                        array_map(fn ($link) => [
                            'post_id' => $post->id,
                            'term_id' => $link['term_id'],
                            'locale' => $locale,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ], $appliedLinks),
                        ['post_id', 'term_id', 'locale'],
                        [],
                    );
                });
                $total += count($appliedLinks);
            }
        }

        return $total;
    }

    /**
     * 对 HTML 织链：返回 [新 HTML, [term_id => 关键词] 列表]。
     * 出错时原样返回（织链失败不阻塞发布链路）。
     *
     * 偏移语义：stripos/PREG_OFFSET_CAPTURE 返回字节偏移，而 DOM 规范的
     * splitText 按（码点）字符偏移切分——多字节正文（阿语/西语）必须转换，
     * 否则锚文本被错位切断（tests/...::test_weaves_multibyte_text 锁定）。
     *
     * @param  list<LinkTerm>  $terms
     * @return array{0: string, 1: list<array{term_id: int, keyword: string}>}
     */
    public function weaveHtml(string $html, iterable $terms, int $maxLinks): array
    {
        try {
            $doc = new DOMDocument();
            libxml_use_internal_errors(true);
            // XML 声明前缀让 libxml 按 UTF-8 解析（DOMDocument 默认 ISO-8859-1）
            $doc->loadHTML(
                '<?xml encoding="UTF-8"?><div id="__weaver">'.$html.'</div>',
                LIBXML_NOERROR | LIBXML_NOWARNING,
            );

            $xpath = new DOMXPath($doc);
            $applied = 0;
            $appliedLinks = [];

            foreach ($terms as $term) {
                if ($applied >= $maxLinks) {
                    break;
                }

                $keyword = $term->keyword;
                $kwLen = strlen($keyword);
                if ($kwLen < 3) {
                    continue; // 太短的词误链率高
                }

                // 纯 ASCII 关键词用词边界匹配：避免 valve 命中 valves 的词内部；
                // 非 ASCII（CJK 等）无 \b 概念，退化为子串匹配
                $useWordBoundary = preg_match('/^[\x20-\x7e]+$/', $keyword) === 1;

                // 每次重新查询：上一个织入后节点树已变化。
                // 排除 a/script/style/code/pre/textarea：已有链接与代码块语义不可破坏
                $nodes = $xpath->query('//text()[not(ancestor::a or ancestor::script or ancestor::style or ancestor::code or ancestor::pre or ancestor::textarea)]');
                foreach ($nodes as $node) {
                    $pos = false;
                    if ($useWordBoundary) {
                        if (preg_match('/\b'.preg_quote($keyword, '/').'\b/i', $node->nodeValue, $m, PREG_OFFSET_CAPTURE) === 1) {
                            $pos = $m[0][1];
                        }
                    } else {
                        $pos = stripos($node->nodeValue, $keyword);
                    }
                    if ($pos === false) {
                        continue;
                    }

                    // 字节偏移 → 字符偏移（匹配点必在字符边界，转换无损）
                    $charPos = mb_strlen(substr($node->nodeValue, 0, $pos), 'UTF-8');
                    $kwLenChars = mb_strlen($keyword);

                    // 拆三段：前文 | 关键词 | 后文
                    $node->splitText($charPos + $kwLenChars);
                    $kwNode = $node->splitText($charPos);

                    $a = $doc->createElement('a');
                    $a->setAttribute('href', $term->target);
                    if ($term->title) {
                        $a->setAttribute('title', $term->title);
                    }
                    $kwNode->parentNode->replaceChild($a, $kwNode);
                    $a->appendChild($kwNode);

                    $applied++;
                    $appliedLinks[] = ['term_id' => $term->id, 'keyword' => $keyword];
                    break; // 每个词每篇文章只织一次
                }
            }

            if ($appliedLinks === []) {
                return [$html, []];
            }

            // 取回包裹层的 innerHTML
            $inner = '';
            $wrapper = $xpath->query('//div[@id="__weaver"]')->item(0);
            foreach ($wrapper->childNodes as $child) {
                $inner .= $doc->saveHTML($child);
            }

            return [$inner, $appliedLinks];
        } catch (Throwable) {
            return [$html, []];
        }
    }

    /**
     * 全量回织：对词典变更后重跑所有已发布文章（幂等表控制不重复）。
     * 返回 [处理文章数, 新增链接数]。
     *
     * @return array{0: int, 1: int}
     */
    public function reweaveAll(): array
    {
        $posts = 0;
        $links = 0;

        Post::query()
            ->where('status', 'published')
            ->chunkById(100, function ($chunk) use (&$posts, &$links): void {
                foreach ($chunk as $post) {
                    $added = $this->weavePost($post);
                    if ($added > 0) {
                        $posts++;
                        $links += $added;
                    }
                }
            });

        return [$posts, $links];
    }
}
