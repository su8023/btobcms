<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Post;
use App\Models\Product;
use Illuminate\Http\Response;

class SitemapController extends Controller
{
    /**
     * sitemap 索引：拆分为 5 个分片，产品量大时可独立分页。
     */
    public function index(): Response
    {
        $sections = [];
        foreach (['pages', 'industries', 'categories', 'products', 'posts'] as $section) {
            $sections[] = route('sitemap.section', ['section' => $section]);
        }

        return response()
            ->view('sitemap.index', ['sections' => $sections])
            ->header('Content-Type', 'application/xml; charset=UTF-8');
    }

    public function section(string $section): Response
    {
        $urls = match ($section) {
            'pages' => $this->pageUrls(),
            'industries' => $this->industryUrls(),
            'categories' => $this->modelUrls(
                Category::query()->where('status', true)->get(),
                'categories.show',
            ),
            'products' => $this->modelUrls(
                Product::published()->orderBy('id')->get(),
                'products.show',
            ),
            'posts' => $this->modelUrls(
                Post::published()->orderBy('id')->get(),
                'blog.show',
            ),
            default => abort(404),
        };

        return response()
            ->view('sitemap.urlset', ['urls' => $urls])
            ->header('Content-Type', 'application/xml; charset=UTF-8');
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
            '',
        ];

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
