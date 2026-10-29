<?php

use App\Http\Controllers\InstallController;
use App\Http\Controllers\Site\CategoryController;
use App\Http\Controllers\Site\HomeController;
use App\Http\Controllers\Site\IndustryController;
use App\Http\Controllers\Site\PageController;
use App\Http\Controllers\Site\PostController;
use App\Http\Controllers\Site\ProductController;
use App\Http\Controllers\Site\ServiceController;
use App\Http\Controllers\Site\SitemapController;
use App\Http\Middleware\DetectVisitorLocale;
use App\Http\Middleware\SetLocale;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| 多语言前台路由
|--------------------------------------------------------------------------
| 默认语言（en）不带前缀；其余语言带 /es/ /ar/ /ru/ 前缀，
| 路由名同样带语言前缀（es.home），由 localized_route() 辅助函数解析。
*/

// ── 安装向导（storage/app/installed.lock 存在即 404，防重装/防接管）──
Route::get('/install', [InstallController::class, 'index'])->name('install.index');
Route::post('/install', [InstallController::class, 'store'])->name('install.store');
Route::get('/install/done', [InstallController::class, 'done'])->name('install.done');
$registerSiteRoutes = function (string $locale): void {
    $name = $locale === config('site.default_locale') ? '' : "{$locale}.";

    Route::get('/', [HomeController::class, 'index'])->name("{$name}home");
    Route::get('/industries', [IndustryController::class, 'index'])->name("{$name}industries.index");
    Route::get('/industries/{slug}', [IndustryController::class, 'show'])->name("{$name}industries.show");
    Route::get('/services', [ServiceController::class, 'index'])->name("{$name}services.index");
    Route::get('/products', [ProductController::class, 'index'])->name("{$name}products.index");
    Route::get('/product/{slug}', [ProductController::class, 'show'])->name("{$name}products.show");
    Route::get('/category/{slug}', [CategoryController::class, 'show'])->name("{$name}categories.show");
    Route::get('/blog', [PostController::class, 'index'])->name("{$name}blog.index");
    Route::get('/blog/{slug}', [PostController::class, 'show'])->name("{$name}blog.show");
    Route::get('/contact', [PageController::class, 'contact'])->name("{$name}contact");
    Route::get('/about', [PageController::class, 'about'])->name("{$name}about");
    Route::get('/privacy', [PageController::class, 'privacy'])->name("{$name}privacy");
    Route::get('/terms', [PageController::class, 'terms'])->name("{$name}terms");
    Route::get('/inquiry-basket', [PageController::class, 'basket'])->name("{$name}basket");
};

$default = config('site.default_locale');

Route::middleware([DetectVisitorLocale::class, SetLocale::class.":{$default}"])
    ->group(function () use ($registerSiteRoutes, $default) {
        $registerSiteRoutes($default);
    });

foreach (array_values(array_diff(config('site.locales'), [$default])) as $locale) {
    Route::prefix($locale)
        ->middleware([SetLocale::class.":{$locale}"])
        ->group(function () use ($registerSiteRoutes, $locale) {
            $registerSiteRoutes($locale);
        });
}

/*
|--------------------------------------------------------------------------
| 插件前台路由约定
|--------------------------------------------------------------------------
| 模块提供 routes/site.php 即在每个语言组内被 include：
| 文件内可用 $locale（当前语言），路由名需按约定带语言前缀
| （默认语言 'cases.index'，其他 "{$locale}.cases.index"），
| 以配合 localized_route()/locale_alternates() 的解析规则。
*/

foreach (\Nwidart\Modules\Facades\Module::allEnabled() as $siteRoutesModule) {
    $siteRoutesFile = module_path($siteRoutesModule->getName(), 'routes/site.php');
    if (! is_file($siteRoutesFile)) {
        continue;
    }

    foreach (array_merge([$default], array_values(array_diff(config('site.locales'), [$default]))) as $locale) {
        $prefix = $locale === $default ? '' : $locale;

        Route::prefix($prefix)
            ->middleware([SetLocale::class.":{$locale}"])
            ->group(function () use ($siteRoutesFile, $locale): void {
                include $siteRoutesFile;
            });
    }
}

/*
|--------------------------------------------------------------------------
| SEO 路由
|--------------------------------------------------------------------------
*/

Route::get('/sitemap.xml', [SitemapController::class, 'index'])->name('sitemap.index');

Route::get('/sitemap-{section}.xml', [SitemapController::class, 'section'])
    ->whereIn('section', ['pages', 'industries', 'categories', 'products', 'posts'])
    ->name('sitemap.section');

Route::get('/robots.txt', [SitemapController::class, 'robots'])->name('robots');

// GEO：面向 AI 生成引擎的站点说明
Route::get('/llms.txt', [SitemapController::class, 'llms'])->name('llms');

/*
|--------------------------------------------------------------------------
| 后台语言切换
|--------------------------------------------------------------------------
| 独立于前台多语言路由组：仅登录用户可访问，路径参数限定 en/zh_CN，
| 由 AdminLocaleController 写入用户资料与会话后返回来源页。
*/

Route::middleware(['web', 'auth'])
    ->get('/admin/locale/{locale}', [\App\Http\Controllers\Admin\AdminLocaleController::class, 'switch'])
    ->whereIn('locale', ['en', 'zh_CN'])
    ->name('admin.locale');

/*
|--------------------------------------------------------------------------
| 询盘 CSV 导出
|--------------------------------------------------------------------------
| 后台登录用户导出询盘 CSV（UTF-8 带 BOM，Excel 可直接打开），
| 支持 ?status= 按状态筛选。
*/

Route::middleware(['web', 'auth'])
    ->get('/admin/inquiries/export', function (\Illuminate\Http\Request $request) {
        $status = (string) $request->query('status', '');

        $query = \App\Models\Inquiry::query()->orderBy('id');

        if ($status !== '' && in_array($status, \App\Models\Inquiry::STATUSES, true)) {
            $query->where('status', $status);
        }

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');

            // UTF-8 BOM：让 Excel 正确识别中文
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, [
                'id', 'name', 'email', 'phone', 'company', 'country', 'status',
                'source', 'utm_source', 'utm_medium', 'utm_campaign',
                'landing_url', 'message', 'created_at',
            ]);

            $query->chunk(500, function ($inquiries) use ($out) {
                foreach ($inquiries as $inquiry) {
                    $utm = is_array($inquiry->utm) ? $inquiry->utm : [];

                    fputcsv($out, [
                        $inquiry->id,
                        $inquiry->name,
                        $inquiry->email,
                        $inquiry->phone,
                        $inquiry->company,
                        $inquiry->country,
                        $inquiry->status,
                        $inquiry->source,
                        $utm['source'] ?? '',
                        $utm['medium'] ?? '',
                        $utm['campaign'] ?? '',
                        $inquiry->landing_url,
                        $inquiry->message,
                        $inquiry->created_at?->format('Y-m-d H:i:s'),
                    ]);
                }
            });

            fclose($out);
        }, 'inquiries-'.now()->format('Ymd-His').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    })
    ->name('admin.inquiries.export');

// 未登录访问受保护路由时，auth 中间件寻找名为 login 的路由——指向后台登录页
Route::get('/login', fn () => redirect('/admin/login'))->name('login');

/*
|--------------------------------------------------------------------------
| 产品 CSV 导出
|--------------------------------------------------------------------------
| 后台登录用户导出产品 CSV（UTF-8 带 BOM），供 ListProducts 工具栏
| “导出 CSV”按钮跳转（路由名 admin.products.export）。
*/

Route::middleware(['web', 'auth'])
    ->get('/admin/products/export', function () {
        return response()->streamDownload(function (): void {
            $out = fopen('php://output', 'w');

            // UTF-8 BOM：让 Excel 正确识别编码
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, [
                'id', 'slug', 'category_slug', 'sku', 'status', 'currency',
                'price_min', 'price_max', 'moq', 'unit', 'sort_order',
                'name_en', 'name_es', 'name_ar', 'name_ru',
                'summary_en', 'summary_es', 'summary_ar', 'summary_ru',
                'created_at', 'updated_at',
            ]);

            \App\Models\Product::query()
                ->with('category')
                ->orderBy('id')
                ->chunk(500, function ($products) use ($out): void {
                    foreach ($products as $product) {
                        $name = $product->getTranslations('name');
                        $summary = $product->getTranslations('summary');

                        fputcsv($out, [
                            $product->id,
                            $product->slug,
                            $product->category?->slug,
                            $product->sku,
                            $product->status,
                            $product->currency,
                            $product->price_min,
                            $product->price_max,
                            $product->moq,
                            $product->unit,
                            $product->sort_order,
                            $name['en'] ?? '', $name['es'] ?? '', $name['ar'] ?? '', $name['ru'] ?? '',
                            $summary['en'] ?? '', $summary['es'] ?? '', $summary['ar'] ?? '', $summary['ru'] ?? '',
                            $product->created_at?->toDateTimeString(),
                            $product->updated_at?->toDateTimeString(),
                        ]);
                    }
                });

            fclose($out);
        }, 'products-'.now()->format('Ymd-His').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    })
    ->name('admin.products.export');
