<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Post;
use App\Models\Product;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

class SitemapController extends Controller
{
    /**
     * 单个 sitemap 文件的 URL 上限。
     *
     * 搜索引擎协议规定单个 sitemap 文件最多 5 万条 <url>；
     * 留出余量，超过就自动分片（products-1.xml / products-2.xml …）。
     */
    private const SHARD_SIZE = 45000;

    /**
     * sitemap 索引：拆分为分片，产品量大时独立分页。
     */
    public function index(): Response
    {
        $sections = [];

        foreach (['pages', 'industries', 'categories', 'products', 'posts', 'cases'] as $section) {
            if (! $this->sectionAvailable($section)) {
                continue;
            }

            foreach ($this->shardsOf($section) as $shard) {
                $sections[] = route('sitemap.section', ['section' => $shard]);
            }
        }

        return response()
            ->view('sitemap.index', ['sections' => $sections])
            ->header('Content-Type', 'application/xml; charset=UTF-8');
    }

    public function section(string $section): Response
    {
        // 形如 products / products-2：数字后缀是分片号
        $base = $section;
        $shard = 0;

        if (preg_match('/^(.+)-(\d+)$/', $section, $m) === 1) {
            $base = $m[1];
            $shard = max(0, ((int) $m[2]) - 1);
        }

        if (! $this->sectionAvailable($base)) {
            abort(404);
        }

        $urls = match ($base) {
            'pages' => $this->pageUrls(),
            'industries' => $this->industryUrls(),
            'categories' => $this->modelUrls(
                $this->shardQuery(Category::query()->where('status', true)->orderBy('id'), $shard),
                'categories.show',
            ),
            'products' => $this->modelUrls(
                $this->shardQuery(Product::published()->orderBy('id'), $shard),
                'products.show',
            ),
            'posts' => $this->modelUrls(
                $this->shardQuery(Post::published()->orderBy('id'), $shard),
                'blog.show',
            ),
            'cases' => $this->modelUrls(
                $this->shardQuery(\Modules\TrustBuilder\Models\TrustCase::query()->published()->orderBy('id'), $shard),
                'cases.show',
            ),
            default => abort(404),
        };

        return response()
            ->view('sitemap.urlset', ['urls' => $urls])
            ->header('Content-Type', 'application/xml; charset=UTF-8');
    }

    /**
     * 该分片是否存在（模块停用/被删除时不应再对外暴露）。
     */
    private function sectionAvailable(string $section): bool
    {
        if ($section === 'cases') {
            // TrustBuilder 是可选插件：模块目录被删除后类不存在，
            // 此时既不能查询也不能生成链接，否则 /sitemap-cases.xml 直接 500。
            return class_exists(\Modules\TrustBuilder\Models\TrustCase::class)
                && $this->trustBuilderEnabled();
        }

        return in_array($section, ['pages', 'industries', 'categories', 'products', 'posts'], true);
    }

    /**
     * TrustBuilder 模块是否处于启用状态（模块被删除时 isEnabled 会抛异常）。
     */
    private function trustBuilderEnabled(): bool
    {
        try {
            return (bool) \Nwidart\Modules\Facades\Module::isEnabled('TrustBuilder');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 某分区需要几个分片（至少 1 个）。
     *
     * @return list<string>
     */
    private function shardsOf(string $section): array
    {
        // 分片数只随内容量变化，缓存 10 分钟：
        // 否则每次请求 sitemap.xml 都要对 products/posts 做一次 COUNT（可观的扫描开销）
        $counts = Cache::remember('sitemap-shard-counts', 600, function (): array {
            $out = [];

            foreach (['categories', 'products', 'posts', 'cases'] as $key) {
                $out[$key] = match ($key) {
                    'categories' => Category::query()->where('status', true)->count(),
                    'products' => Product::published()->count(),
                    'posts' => Post::published()->count(),
                    'cases' => class_exists(\Modules\TrustBuilder\Models\TrustCase::class)
                        ? \Modules\TrustBuilder\Models\TrustCase::query()->published()->count()
                        : 0,
                };
            }

            return $out;
        });

        $total = (int) ($counts[$section] ?? 1);

        $count = max(1, (int) ceil($total / self::SHARD_SIZE));

        $shards = [];

        for ($i = 1; $i <= $count; $i++) {
            $shards[] = $count === 1 ? $section : "{$section}-{$i}";
        }

        return $shards;
    }

    /**
     * 取某个分片的数据。
     *
     * 用 cursor() 而不是 get()：一次性物化上万个 Eloquent 模型
     * （含 JSON cast 的正文/属性）会把内存吃到几十 MB，
     * 而 sitemap 只需要 slug 与 updated_at。
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Support\LazyCollection<int, \Illuminate\Database\Eloquent\Model>
     */
    private function shardQuery($query, int $shard): \Illuminate\Support\LazyCollection
    {
        return $query
            ->skip($shard * self::SHARD_SIZE)
            ->take(self::SHARD_SIZE)
            ->cursor();
    }

    public function robots(): Response
    {
        $content = "User-agent: *\n".
            "Allow: /\n".
            "Disallow: /admin\n\n".
            // GEO：明确欢迎 AI 搜索引擎抓取（生成式引擎优化）
            "User-agent: GPTBot\nAllow: /\n\n".
            "User-agent: OAI-SearchBot\nAllow: /\n\n".
            "User-agent: ChatGPT-User\nAllow: /\n\n".
            "User-agent: ClaudeBot\nAllow: /\n\n".
            "User-agent: PerplexityBot\nAllow: /\n\n".
            "User-agent: Google-Extended\nAllow: /\n\n".
            "User-agent: Applebot-Extended\nAllow: /\n\n".
            "User-agent: Bytespider\nAllow: /\n\n".
            'Sitemap: '.route('sitemap.index')."\n";

        return response($content, 200)->header('Content-Type', 'text/plain; charset=UTF-8');
    }

    /**
     * llms.txt：面向 AI 生成引擎的站点说明（GEO），
     * 帮助 ChatGPT/Perplexity 等正确引用本站实体与内容。
     */
    public function llms(): Response
    {
        $site = site_setting('site.name', config('site.name'));
        $url = localized_route('home');
        $locale = app()->getLocale();

        $categories = Category::query()
            ->whereNull('parent_id')
            ->where('status', true)
            ->orderBy('sort_order')
            ->get();

        $products = Product::published()->orderByDesc('id')->take(20)->get();

        $lines = [
            '# '.$site,
            '',
            '> '.(site_setting('seo.home.description.'.$locale) ?: __('site.home.hero_sub')),
            '',
            '## About',
            '',
            $site.' is a B2B manufacturer and wholesale supplier based in '.config('site.contact.address').'.',
            'Core business: factory-direct supply with OEM/ODM support for global buyers.',
            'Response time: within 24 hours. Contact: '.site_setting('site.contact.email', config('site.contact.email')),
            '',
            '## Key pages',
            '',
            '- [Home]('.$url.'): company overview and featured products',
            '- [All products]('.localized_route('products.index').'): full catalog',
            '- [Contact]('.localized_route('contact').'): request a quote',
            '- [Blog]('.localized_route('blog.index').'): sourcing guides',
            '- [Industries]('.localized_route('industries.index').'): industry-specific sourcing solutions',
            '- [Services]('.localized_route('services.index').'): OEM/ODM, quality control, logistics, sourcing',
        ];

        // 案例页来自可选插件 TrustBuilder：插件停用时该路由不存在，
        // 直接调 localized_route 会抛 RouteNotFoundException 让整个 llms.txt 500。
        if ($this->sectionAvailable('cases')) {
            $lines[] = '- [Case studies]('.localized_route('cases.index').'): customer success stories and delivery records';
        }


        // 行业清单：纯线索站的核心实体导航（AI 引擎按行业引用落地页）
        $lines[] = '## Industries we serve';
        $lines[] = '';

        foreach (config('industries') as $industry) {
            $name = $industry['name'][$locale] ?? $industry['name']['en'];
            $link = localized_route('industries.show', ['slug' => $industry['slug']]);
            $lines[] = '- ['.$name.']('.$link.')';
        }

        // 目录区块仅在有内容时输出，避免给 AI 引擎空清单
        if ($categories->isNotEmpty()) {
            $lines[] = '';
            $lines[] = '## Product categories';
            $lines[] = '';

            foreach ($categories as $category) {
                $link = localized_route('categories.show', ['slug' => $category->slug]);
                $lines[] = '- ['.$category->name.']('.$link.')';
            }
        }

        if ($products->isNotEmpty()) {
            $lines[] = '';
            $lines[] = '## Popular products';
            $lines[] = '';

            foreach ($products as $product) {
                $link = localized_route('products.show', ['slug' => $product->slug]);
                $lines[] = '- ['.$product->name.']('.$link.')';
            }
        }

        // 近期文章 + 一句话摘要：AI 引擎引用内容的直接素材（含 excerpt 才列出）
        $posts = Post::published()
            ->where('type', 'post')
            ->orderByDesc('published_at')
            ->take(15)
            ->get()
            ->filter(fn ($post) => filled($post->excerpt));

        if ($posts->isNotEmpty()) {
            $lines[] = '';
            $lines[] = '## Recent articles';
            $lines[] = '';

            foreach ($posts as $post) {
                $link = localized_route('blog.show', ['slug' => $post->slug]);
                $lines[] = '- ['.$post->title.']('.$link.'): '.mb_substr(strip_tags((string) $post->excerpt), 0, 140);
            }
        }

        return response(implode("\n", $lines)."\n", 200)
            ->header('Content-Type', 'text/markdown; charset=UTF-8');
    }

    /**
     * @return array<int, array{url: string, lastmod: ?string, alternates: array<string, string>}>
     */
    private function pageUrls(): array
    {
        $names = ['home', 'contact', 'about', 'services.index', 'industries.index', 'privacy', 'terms'];

        // 目录/博客空库时不提交空壳列表页（纯线索模式：有内容再进 sitemap）
        if (Product::published()->exists()) {
            $names[] = 'products.index';
        }
        if (Post::published()->exists()) {
            $names[] = 'blog.index';
        }

        return array_map(fn (string $name) => [
            'url' => localized_route($name),
            'lastmod' => null,
            'alternates' => locale_alternates($name),
        ], $names);
    }

    /**
     * 行业落地页（config 驱动，增删行业自动同步 sitemap）。
     *
     * @return array<int, array{url: string, lastmod: ?string, alternates: array<string, string>}>
     */
    private function industryUrls(): array
    {
        // lastmod 用行业配置文件真实修改时间（内容变更即更新，Google 抓取调度友好）
        $lastmod = date('c', (int) filemtime(config_path('industries.php')));

        return collect(config('industries'))->map(fn (array $industry) => [
            'url' => localized_route('industries.show', ['slug' => $industry['slug']]),
            'lastmod' => $lastmod,
            'alternates' => locale_alternates('industries.show', ['slug' => $industry['slug']]),
        ])->all();
    }

    /**
     * @param  iterable<\Illuminate\Database\Eloquent\Model>  $models
     * @return array<int, array{url: string, lastmod: ?string, alternates: array<string, string>}>
     */
    private function modelUrls(iterable $models, string $routeName): array
    {
        $urls = [];
        foreach ($models as $model) {
            $params = ['slug' => $model->slug];
            $urls[] = [
                'url' => localized_route($routeName, $params),
                'lastmod' => $model->updated_at?->toAtomString(),
                'alternates' => locale_alternates($routeName, $params),
            ];
        }

        return $urls;
    }
}
