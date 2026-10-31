<?php

namespace Modules\LinkWeaver\Services;

use App\Models\Post;
use DOMDocument;
use DOMXPath;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
     * 单次「全量回织」的时间预算（秒）。
     * 回织是同步 HTTP 动作，跑满网关超时会被网关掐成 502；
     * 超预算即收尾并把"没处理完"如实报给后台，剩余文章下次再点一次即可（幂等，不会重复织）。
     */
    protected const REWEAVE_TIME_BUDGET = 120;

    /**
     * 对一篇文章的所有语言正文织链，返回本次新增链接数。
     * 幂等：applied_links 表保证同一词在同一文章同一语言只织一次。
     */
    public function weavePost(Post $post): int
    {
        // 缩小并发发布竞态窗口：以库中最新内容为准
        $post = $post->fresh() ?? $post;

        // 词典与已织锚点一次性取回：原来每个语言各查一次，逐篇逐语言会放大成 N+1
        return $this->weavePostWith($post, $this->enabledTerms(), $this->appliedMap([$post->id]));
    }

    /**
     * 全量回织：对词典变更后重跑所有已发布文章（幂等表控制不重复）。
     * 返回 [处理文章数, 新增链接数, 是否因时间预算提前结束]。
     *
     * @return array{0: int, 1: int, 2: bool}
     */
    public function reweaveAll(): array
    {
        $posts = 0;
        $links = 0;
        $partial = false;
        $startedAt = microtime(true);

        Post::query()
            ->where('status', 'published')
            ->chunkById(100, function ($chunk) use (&$posts, &$links, &$partial, $startedAt): bool {
                // 词典与已织锚点按批预取：原来每篇每语言各查一次（2~3 条/篇），大站直接打爆数据库
                $terms = $this->enabledTerms();
                $applied = $this->appliedMap($chunk->modelKeys());

                foreach ($chunk as $post) {
                    // 时间预算用尽就收尾：剩余文章下次再点一次，绝不让同步请求跑成 502
                    if (microtime(true) - $startedAt > self::REWEAVE_TIME_BUDGET) {
                        $partial = true;

                        return false;
                    }

                    try {
                        $added = $this->weavePostWith($post, $terms, $applied);
                    } catch (Throwable $e) {
                        // 单篇失败不中断整轮：记日志后继续下一篇
                        Log::error('LinkWeaver: 回织单篇失败', [
                            'post' => $post->id,
                            'e' => $e->getMessage(),
                        ]);

                        continue;
                    }

                    if ($added > 0) {
                        $posts++;
                        $links += $added;
                    }
                }

                return true;
            });

        return [$posts, $links, $partial];
    }

    /**
     * 对 HTML 织链：返回 [新 HTML, [term_id => 关键词] 列表]。
     * 出错时原样返回（织链失败不阻塞发布链路）。
     *
     * 偏移语义：stripos/PREG_OFFSET_CAPTURE 返回字节偏移，而 DOM 规范的
     * splitText 按（码点）字符偏移切分——多字节正文（阿语/西语）必须转换，
     * 否则锚文本被错位切断（tests/...::test_weaves_multibyte_text 锁定）。
     *
     * @param  iterable<LinkTerm>  $terms
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

    /* ----------------------------------------------------------
     | 内部实现
     * --------------------------------------------------------- */

    /**
     * 用已取好的词典/锚点快照织链（回织批量路径复用，避免逐篇再查库）。
     *
     * @param  Collection<int, LinkTerm>  $terms  已启用的词典
     * @param  array<int, array<string, list<int>>>  $applied  [post_id => [locale => [term_id...]]]
     */
    protected function weavePostWith(Post $post, Collection $terms, array $applied): int
    {
        $total = 0;

        foreach ($post->getTranslations('content') as $locale => $html) {
            if (! is_string($html) || trim($html) === '') {
                continue;
            }

            $total += $this->weaveLocale(
                $post,
                (string) $locale,
                $html,
                $terms,
                $applied[$post->id][(string) $locale] ?? [],
            );
        }

        return $total;
    }

    /**
     * 单语言织链：正文写入与幂等锚点插入必须在同一个事务里。
     *
     * @param  Collection<int, LinkTerm>  $terms
     * @param  list<int>  $applied  该文章该语言已织入的 term_id
     */
    protected function weaveLocale(Post $post, string $locale, string $html, Collection $terms, array $applied): int
    {
        // 没有可织的词就不开事务、不碰数据库
        if ($this->eligibleTerms($terms, $locale, $applied)->isEmpty()) {
            return 0;
        }

        // 正文与锚点同生共死：锚点写失败时正文一起回滚。
        // 否则正文已含新链接而 applied_links 缺锚点行，后台"已织入"计数与正文漂移
        return (int) DB::transaction(function () use ($post, $locale, $html, $terms, $applied): int {
            [$newHtml, $appliedLinks] = $this->weaveHtml(
                $html,
                $this->eligibleTerms($terms, $locale, $applied),
                (int) config('link-weaver.max_links_per_post'),
            );

            if ($appliedLinks === []) {
                return 0;
            }

            // insertOrIgnore：唯一约束 (post_id, term_id, locale) 冲突时忽略而不是抛异常。
            // 不能用 upsert($rows, $keys, [])：Laravel 的 Builder::upsert 在 $update 为空数组时
            // 直接退化成 insert()，唯一约束冲突照样抛 QueryException —— 那正是并发重复的来源
            $inserted = AppliedLink::insertOrIgnore(array_map(fn (array $link) => [
                'post_id' => $post->id,
                'term_id' => $link['term_id'],
                'locale' => $locale,
                'created_at' => now(),
                'updated_at' => now(),
            ], $appliedLinks));

            $content = $post->getTranslations('content');
            $content[$locale] = $newHtml;
            $post->setTranslations('content', $content);
            // PostPublished 只由后台保存动作发出，这里 save 不会重复触发
            $post->save();

            // 只统计真正落库的锚点：并发重复被 insertOrIgnore 忽略时不能重复计数
            return $inserted;
        });
    }

    /**
     * 已启用的词典（按 id 倒序，与原查询一致）：一次性取回，内存里按语言过滤。
     *
     * @return Collection<int, LinkTerm>
     */
    protected function enabledTerms(): Collection
    {
        return LinkTerm::query()
            ->where('enabled', true)
            ->orderByDesc('id')
            ->get();
    }

    /**
     * 已织锚点快照：[post_id => [locale => [term_id...]]]。
     *
     * @param  list<int>  $postIds
     * @return array<int, array<string, list<int>>>
     */
    protected function appliedMap(array $postIds): array
    {
        if ($postIds === []) {
            return [];
        }

        $map = [];
        foreach (AppliedLink::query()->whereIn('post_id', $postIds)->get(['post_id', 'term_id', 'locale']) as $row) {
            $map[$row->post_id][(string) $row->locale][] = $row->term_id;
        }

        return $map;
    }

    /**
     * 该语言可用、且尚未织入的词典项。
     * locale 为 null = 全部语言生效（与原查询 whereNull('locale')->orWhere('locale', $locale) 等价）。
     *
     * @param  Collection<int, LinkTerm>  $terms
     * @param  list<int>  $applied
     * @return Collection<int, LinkTerm>
     */
    protected function eligibleTerms(Collection $terms, string $locale, array $applied): Collection
    {
        return $terms->filter(function (LinkTerm $term) use ($locale, $applied): bool {
            if ($term->locale !== null && $term->locale !== $locale) {
                return false;
            }

            return ! in_array($term->id, $applied, true);
        })->values();
    }
}
