<?php

namespace Modules\LinkWeaver\Services;

use App\Models\Post;
use DOMDocument;
use DOMXPath;
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

                foreach ($appliedLinks as $link) {
                    AppliedLink::create([
                        'post_id' => $post->id,
                        'term_id' => $link['term_id'],
                        'locale' => $locale,
                    ]);
                    $total++;
                }
            }
        }

        return $total;
    }

    /**
     * 对 HTML 织链：返回 [新 HTML, [term_id => 关键词] 列表]。
     * 出错时原样返回（织链失败不阻塞发布链路）。
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

                // 每次重新查询：上一个织入后节点树已变化
                $nodes = $xpath->query('//text()[not(ancestor::a)]');
                foreach ($nodes as $node) {
                    $pos = stripos($node->nodeValue, $keyword);
                    if ($pos === false) {
                        continue;
                    }

                    // 拆三段：前文 | 关键词 | 后文
                    $node->splitText($pos + $kwLen);
                    $kwNode = $node->splitText($pos);

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
