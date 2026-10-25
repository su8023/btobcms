<?php

namespace App\Support;

use App\Models\Category;
use App\Models\Post;
use App\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Lang;

/**
 * SEO 元信息解析器：优先读 SeoMeta 覆盖值，其次内容摘要，最后回退到配置模板。
 */
class Seo
{
    /**
     * 模型页（产品/分类/文章）的 SEO 信息包。
     *
     * @return array{title: string, description: string, canonical: string, alternates: array<string, string>, og: array<string, string>}
     */
    public static function for(Model $model, string $routeName, array $routeParams = []): array
    {
        $locale = app()->getLocale();
        $site = (string) config('site.name');

        $type = match (true) {
            $model instanceof Product => 'product',
            $model instanceof Category => 'category',
            $model instanceof Post => 'post',
            default => 'page',
        };

        $nameField = $type === 'post' ? 'title' : 'name';
        $descField = match ($type) {
            'post' => 'excerpt',
            'category' => 'description',
            default => 'summary',
        };

        $name = trim((string) $model->getTranslation($nameField, $locale));
        $summary = trim((string) $model->getTranslation($descField, $locale));

        $categoryName = '';
        if ($model instanceof Product && $model->category) {
            $categoryName = trim((string) $model->category->getTranslation('name', $locale));
        }

        $replace = [
            '{name}' => $name,
            '{site}' => $site,
            '{category}' => $categoryName ?: $name,
            '{moq}' => (string) ($model->moq ?? ''),
        ];

        $meta = method_exists($model, 'seoMeta') ? $model->seoMeta : null;

        $title = trim((string) ($meta?->getTranslation('title', $locale) ?? ''));
        if ($title === '') {
            $title = strtr((string) config("site.seo.{$type}.title", '{name} | {site}'), $replace);
        }

        $description = trim((string) ($meta?->getTranslation('description', $locale) ?? ''));
        if ($description === '') {
            $description = $summary !== ''
                ? $summary
                : strtr((string) config("site.seo.{$type}.description", ''), $replace);
        }

        return self::bag(
            $title,
            $description,
            $routeName,
            $routeParams,
            $type === 'post' ? 'article' : 'product',
        );
    }

    /**
     * 静态页面（首页/列表/联系页）的 SEO 信息包。
     *
     * @param  string  $key  配置键，同时也是路由名，如 home / products.index / contact
     */
    public static function page(string $key): array
    {
        $locale = app()->getLocale();
        $cfg = config("site.seo.{$key}") ?? [];
        $site = (string) site_setting('site.name', config('site.name'));

        // 优先级：后台设置的每语言文案 → 当前语言的语言包模板 → 配置模板（英文兜底）
        $settingTitle = site_setting("seo.{$key}.title.{$locale}");
        $settingDescription = site_setting("seo.{$key}.description.{$locale}");

        $langTitle = Lang::has("seo.{$key}.title", $locale)
            ? strtr((string) __("seo.{$key}.title"), ['{site}' => $site])
            : null;
        $langDescription = Lang::has("seo.{$key}.description", $locale)
            ? strtr((string) __("seo.{$key}.description"), ['{site}' => $site])
            : null;

        $title = $settingTitle ?? $langTitle ?? strtr((string) ($cfg['title'] ?? $site), ['{site}' => $site]);
        $description = $settingDescription ?? $langDescription ?? strtr((string) ($cfg['description'] ?? ''), ['{site}' => $site]);

        return self::bag($title, $description, $key, [], 'website');
    }

    /**
     * @param  array<string, string>  $routeParams
     * @return array{title: string, description: string, canonical: string, alternates: array<string, string>, og: array<string, string>}
     */
    private static function bag(string $title, string $description, string $routeName, array $routeParams, string $ogType): array
    {
        $canonical = localized_route($routeName, $routeParams);

        return [
            'title' => $title,
            'description' => mb_substr($description, 0, 300),
            'canonical' => $canonical,
            'alternates' => locale_alternates($routeName, $routeParams),
            'og' => [
                'title' => $title,
                'description' => $description,
                'type' => $ogType,
                'url' => $canonical,
            ],
        ];
    }
}
