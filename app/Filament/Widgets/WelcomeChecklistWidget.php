<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\PluginSettingsPage;
use App\Filament\Resources\Categories\CategoryResource;
use App\Filament\Resources\Products\ProductResource;
use App\Models\Category;
use App\Models\Post;
use App\Models\Product;
use App\Support\PluginGuard;
use Filament\Widgets\Widget;
use Modules\AiContentWriter\Services\ArticleStudio;
use Modules\GscPulse\Models\GscCredential;

/**
 * 登录后第一眼的「新手开始清单」：按运营动线检查 5 件事的完成状态，
 * 全部完成则显示日常动线提示。数据驱动（空项待办、有数据打勾）。
 */
class WelcomeChecklistWidget extends Widget
{
    protected string $view = 'filament.widgets.welcome-checklist';

    protected array|string|int $columnSpan = 'full';

    protected static ?int $sort = -4;

    /**
     * @return array{done: bool, items: list<array{done: bool, title: string, hint: string, url: ?string, url_label: string}>}
     */
    public function getViewData(): array
    {
        $hasAiKey = $this->hasAiKey();

        // GscPulse 是可选插件：被停用或目录被删时，这一项既完成不了、
        // 链接也指向不存在的页面。整项移除，而不是永远挂着「未完成」。
        // 守卫必须在调用之前——类不存在时静态调用会抛 Error。
        $gscAvailable = PluginGuard::available(GscCredential::class, 'GscPulse');

        $items = [
            [
                'done' => $hasAiKey,
                'title' => '配置 AI 助手（写作、翻译、SEO 全靠它）',
                'hint' => $hasAiKey
                    ? 'AI 助手已就绪，文章生产可以全自动起步。'
                    : '打开「插件中心 → AI 内容写作 → 设置」，填入 AI 接口密钥并保存。这是所有 AI 功能的总开关。',
                'url' => $hasAiKey ? null : PluginSettingsPage::getUrl(['plugin' => 'AiContentWriter']),
                'url_label' => '去配置',
            ],
            [
                'done' => Category::query()->exists(),
                'title' => '创建产品分类',
                'hint' => '左侧「目录 → 分类」新建 2~3 个分类（如球阀、闸阀），产品要挂在分类下。',
                'url' => CategoryResource::getUrl('index'),
                'url_label' => '去创建',
            ],
            [
                'done' => Product::query()->exists(),
                'title' => '上传第一批产品',
                'hint' => '左侧「目录 → 产品」新建，填名称和参数即可，图片可后补。',
                'url' => ProductResource::getUrl('index'),
                'url_label' => '去上传',
            ],
            [
                'done' => Post::query()->exists(),
                'title' => '发布第一篇文章（AI 帮你写）',
                'hint' => '去「AI 写作室」输入一个主题，AI 出方案后一键成稿；发布后会自动做 SEO 并推送给搜索引擎。',
                'url' => '/admin/ai-studio',
                'url_label' => '去 AI 写作室',
            ],
            [
                'done' => $gscAvailable && GscCredential::isConnected(),
                'title' => '连接谷歌搜索看板（看客户搜什么词找到你）',
                'hint' => '后台「增长 → GSC 连接」按 4 步图文指引操作，连接后每天自动回流搜索数据。',
                'url' => '/admin/gsc-pulse-connect',
                'url_label' => '去连接',
                // 插件不可用时整项移除，避免永远挂着「未完成」且链接指向 404
                'optional' => ! $gscAvailable,
            ],
        ];

        $items = array_values(array_filter(
            $items,
            fn (array $item): bool => ! ($item['optional'] ?? false)
        ));

        $doneCount = count(array_filter($items, fn ($i) => $i['done']));

        return [
            'done' => $doneCount === count($items),
            'doneCount' => $doneCount,
            'total' => count($items),
            'items' => $items,
        ];
    }

    /** AI Key 是否已配置（.env 供应商或插件设置自定义，与 LlmManager 同口径） */
    private function hasAiKey(): bool
    {
        try {
            ArticleStudio::applyCustomProvider();
        } catch (\Throwable) {
        }

        $providerName = config('ai.tiers.chat');
        $provider = config("ai.providers.{$providerName}");

        return is_array($provider) && filled($provider['key'] ?? null);
    }
}
