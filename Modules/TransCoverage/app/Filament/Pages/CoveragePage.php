<?php

namespace Modules\TransCoverage\Filament\Pages;

use App\Models\Post;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Modules\TransCoverage\Services\CoverageScanner;
use Modules\TransCoverage\Services\TranslationFixer;

/**
 * 翻译覆盖率看板（/admin/trans-coverage）：
 * 内容×语言矩阵一目了然；顶部「AI 补翻」区块选择缺口最多的文章一键补齐。
 * 数据由 CoverageScanner 提供（带缓存），AI 动作经 TranslationFixer 调
 * AiContentWriter 的 PostTranslator。
 */
class CoveragePage extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-language';

    protected static ?int $navigationSort = 33;

    protected static ?string $slug = 'trans-coverage';

    protected string $view = 'transcoverage::coverage';

    /** 「AI 补翻」选中的文章 ID（Livewire 双向绑定） */
    public ?int $fixPostId = null;

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return __('admin.groups.growth');
    }

    public static function getNavigationLabel(): string
    {
        return '翻译覆盖率';
    }

    public function getTitle(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return '翻译覆盖率';
    }

    /**
     * 视图数据：整表扫描结果（统计卡 + 矩阵行 + 语言列表）。
     */
    public function getViewData(): array
    {
        return [
            'coverage' => app(CoverageScanner::class)->scan(),
        ];
    }

    /**
     * AI 补翻 Select 的候选文章：缺语言数最多的 Post 在前（最多 50 条），
     * 选项文案标注缺口数，如「Gate Valve Guide（缺 7 种语言）」。
     */
    public function getFixCandidatesProperty(): Collection
    {
        return collect(app(CoverageScanner::class)->scan()['rows'])
            ->filter(fn (array $row) => $row['type'] === 'post' && $row['missing_count'] > 0)
            ->take(50)
            ->map(fn (array $row) => [
                'id' => $row['id'],
                'label' => sprintf('%s（缺 %d 种语言）', $row['title'], $row['missing_count']),
            ])
            ->values();
    }

    /* ----------------------------------------------------------
     | 操作
     * --------------------------------------------------------- */

    /**
     * 执行 AI 补翻：为选中文章翻译全部缺失语言，成功后通知并刷新看板。
     */
    public function runFix(): void
    {
        if (blank($this->fixPostId)) {
            Notification::make()->title('请先选择要补翻的文章')->warning()->send();

            return;
        }

        if (! TranslationFixer::available()) {
            Notification::make()->title('未安装 AiContentWriter 插件，无法 AI 补翻')->danger()->send();

            return;
        }

        $post = Post::find($this->fixPostId);
        if ($post === null) {
            Notification::make()->title('文章不存在，请刷新后重试')->danger()->send();

            return;
        }

        // 缺失语言以扫描结果为准（full 之外的语言都补）
        $locales = app(CoverageScanner::class)->missingLocalesFor('post', $post->id);
        if ($locales === []) {
            $this->fixPostId = null;
            Notification::make()->title('该文章已覆盖全部语言，无需补翻')->success()->send();

            return;
        }

        try {
            $translated = app(TranslationFixer::class)->fix($post, $locales);
        } catch (\Throwable $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        $this->fixPostId = null;

        Notification::make()
            ->title('已补翻 '.count($translated).' 种语言：'.implode(', ', $translated))
            ->body('看板缓存已刷新，矩阵即时更新。')
            ->success()
            ->send();
    }
}
