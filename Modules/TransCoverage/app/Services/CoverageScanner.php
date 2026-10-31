<?php

namespace Modules\TransCoverage\Services;

use App\Models\Post;
use App\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * 翻译覆盖率扫描：统计 Post / Product 在全站语言下的翻译完整度。
 *
 * 输出每条内容：
 *   - 逐语言状态（full = 标题+正文 / name+summary 都有；partial = 仅标题/name；missing = 全缺）
 *   - 缺失 SEO 语言数（经 metable 多态关联查 SeoMeta；模型无该关联时为 null，前端渲染 '—'）
 *   - 缺失语言数（补翻目标数量，用于排序与 AI 补翻选项标注）
 *
 * 整表结果缓存 10 分钟；缓存 key 携带 Post/Product 两模型的内容指纹
 * （count + max(id) + updated_at 摘要 + 最近改动行的正文摘要），
 * 任何内容变化（增删改）都会生成新 key，自动失效重算，无需手动清缓存。
 */
class CoverageScanner
{
    /** 缓存 key 前缀；完整 key = 前缀 + md5(两模型内容指纹) */
    protected const CACHE_PREFIX = 'trans-coverage:scan:';

    /** 参与正文摘要的"最近改动行"条数：够用又不至于把整站正文搬一遍 */
    protected const FINGERPRINT_SAMPLE = 50;

    /**
     * 覆盖率扫描结果（带缓存）。
     *
     * @return array{locales: list<string>, rows: list<array<string, mixed>>, stats: array<string, int>}
     */
    public function scan(): array
    {
        return Cache::remember($this->cacheKey(), now()->addMinutes(10), fn () => $this->build());
    }

    /**
     * 当前缓存 key：内容变化后指纹随之变化，key 即更换；
     * 携带语言清单——站点改 site.locales 后矩阵列集变化，旧缓存不能复用。
     */
    public function cacheKey(): string
    {
        return self::CACHE_PREFIX.md5(
            $this->fingerprint(Post::class, ['title', 'content']).'|'
            .$this->fingerprint(Product::class, ['name', 'summary'])
            .'|'.implode(',', site_locales())
        );
    }

    /**
     * 指定内容当前缺失翻译的语言（full 之外的所有语言），即 AI 补翻的目标语言。
     *
     * @return list<string>
     */
    public function missingLocalesFor(string $type, int $id): array
    {
        foreach ($this->scan()['rows'] as $row) {
            if ($row['type'] === $type && $row['id'] === $id) {
                return array_values(array_keys(array_filter(
                    $row['statuses'],
                    fn (string $status) => $status !== 'full'
                )));
            }
        }

        return [];
    }

    /* ----------------------------------------------------------
     | 内部构建
     * --------------------------------------------------------- */

    /**
     * 内容指纹：任意一条记录的增/删/改都会让指纹变化，缓存随之失效。
     *
     * 只用 max(updated_at) 是不够的：编辑一条非最新记录时全局最大值可能纹丝不动
     * （改动没有抬高该行的 updated_at，或与最新值落在同一秒），缓存会继续命中最长 10 分钟。
     * 这里组合四个维度：
     *   count                                  —— 增/删必然变
     *   max(id)                                —— 新增必然变
     *   全量 updated_at 摘要                    —— 任意一行的 updated_at 变了就变
     *   最近改动行的正文摘要（固定条数）          —— 正文变了就变（哪怕 updated_at 没动）
     *
     * @param  class-string<Model>  $model
     * @param  list<string>  $contentColumns  参与摘要的正文列（Post: title/content；Product: name/summary）
     */
    protected function fingerprint(string $model, array $contentColumns): string
    {
        $agg = $model::query()
            ->selectRaw('count(*) as row_count, max(id) as max_id, max(updated_at) as max_updated')
            ->first();

        // 增量摘要：流式扫描，内存恒定（内容表可能上千行）
        $ctx = hash_init('md5');
        foreach ($model::query()->orderBy('id')->select(['id', 'updated_at'])->cursor() as $row) {
            hash_update($ctx, $row->id.':'.$row->updated_at."\n");
        }

        // 最近改动行的正文摘要：任何一次保存都会把该行顶到最前面，正文一变摘要就变；
        // 只取固定条数，避免每次算 key 都把整站正文搬一遍
        $recentCtx = hash_init('md5');
        $recent = $model::query()
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(self::FINGERPRINT_SAMPLE)
            ->select(array_merge(['id', 'updated_at'], $contentColumns))
            ->cursor();
        foreach ($recent as $row) {
            hash_update($recentCtx, json_encode(
                $row->getAttributes(),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            )."\n");
        }

        return implode(':', [
            (int) ($agg->row_count ?? 0),
            (int) ($agg->max_id ?? 0),
            (string) ($agg->max_updated ?? ''),
            hash_final($ctx),
            hash_final($recentCtx),
        ]);
    }

    /**
     * 全量构建（无缓存路径）：扫描全部状态与类型的 Post / Product。
     *
     * @return array{locales: list<string>, rows: list<array<string, mixed>>, stats: array<string, int>}
     */
    protected function build(): array
    {
        $locales = site_locales();
        $rows = [];

        // 预加载 seoMeta（morphOne），避免逐行 N+1 查询
        foreach (Post::query()->with('seoMeta')->get() as $post) {
            $rows[] = $this->row($post, 'post', ['title', 'content'], $locales);
        }

        foreach (Product::query()->with('seoMeta')->get() as $product) {
            $rows[] = $this->row($product, 'product', ['name', 'summary'], $locales);
        }

        // 按缺失语言数降序；并列时按 type、id 保证顺序稳定
        usort($rows, function (array $a, array $b): int {
            if ($a['missing_count'] !== $b['missing_count']) {
                return $b['missing_count'] <=> $a['missing_count'];
            }

            return [$a['type'], $a['id']] <=> [$b['type'], $b['id']];
        });

        return [
            'locales' => $locales,
            'rows' => $rows,
            'stats' => $this->stats($rows),
        ];
    }

    /**
     * 构建单行结果。$fields = [标题字段, 正文字段]（Post: title/content；Product: name/summary）。
     *
     * @param  list<string>  $fields
     * @param  list<string>  $locales
     * @return array<string, mixed>
     */
    protected function row(Model $model, string $type, array $fields, array $locales): array
    {
        [$titleField, $bodyField] = $fields;

        $statuses = [];
        foreach ($locales as $locale) {
            // 第三参 false：不做回退翻译，只看该语言自己的译文
            $title = trim((string) $model->getTranslation($titleField, $locale, false));
            $body = trim((string) $model->getTranslation($bodyField, $locale, false));

            $statuses[$locale] = match (true) {
                $title === '' => 'missing',                       // 标题都没有 → 全缺
                $body === '' => 'partial',                        // 有标题无正文 → 半缺
                default => 'full',                                // 标题 + 正文都有 → 完整
            };
        }

        return [
            'type' => $type,
            'id' => $model->id,
            'title' => $this->displayTitle($model->getTranslations($titleField)),
            'statuses' => $statuses,
            'missing_count' => count(array_filter($statuses, fn (string $s) => $s !== 'full')),
            'missing_seo' => $this->missingSeoCount($model, $locales),
        ];
    }

    /**
     * 展示标题：默认语言有值优先，否则取第一个有翻译的语言，全缺时给占位文案。
     *
     * @param  array<string, string>  $translations
     */
    protected function displayTitle(array $translations): string
    {
        $default = default_locale();
        if (filled($translations[$default] ?? null)) {
            return trim((string) $translations[$default]);
        }

        foreach ($translations as $value) {
            if (filled($value)) {
                return trim((string) $value);
            }
        }

        return '(无标题)';
    }

    /**
     * 缺失 SEO 的语言数：SeoMeta 行不存在视为全部缺失；
     * 存在时统计 title 与 description 译文都为空的语言。
     * 模型没有 seoMeta 关系时返回 null（前端渲染 '—'）。
     *
     * @param  list<string>  $locales
     */
    protected function missingSeoCount(Model $model, array $locales): ?int
    {
        if (! method_exists($model, 'seoMeta')) {
            return null;
        }

        /** @var \App\Models\SeoMeta|null $meta */
        $meta = $model->seoMeta;

        if ($meta === null) {
            return count($locales);
        }

        $titles = $meta->getTranslations('title');
        $descriptions = $meta->getTranslations('description');

        $missing = 0;
        foreach ($locales as $locale) {
            if (blank($titles[$locale] ?? null) && blank($descriptions[$locale] ?? null)) {
                $missing++;
            }
        }

        return $missing;
    }

    /**
     * 顶部统计卡数据。
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, int>
     */
    protected function stats(array $rows): array
    {
        $total = count($rows);
        $fullCount = count(array_filter($rows, fn (array $row) => $row['missing_count'] === 0));

        return [
            'total' => $total,                                        // 内容总数（文章 + 产品）
            'full_count' => $fullCount,                               // 完全翻译的内容条数
            'full_rate' => $total > 0 ? (int) round($fullCount / $total * 100) : 0, // 完全翻译率 %
            'total_gaps' => (int) array_sum(array_column($rows, 'missing_count')),  // 总缺口数（内容×语言）
            'seo_gaps' => (int) array_sum(array_filter(                              // SEO 缺口总数
                array_column($rows, 'missing_seo'),
                fn ($v) => $v !== null
            )),
        ];
    }
}
