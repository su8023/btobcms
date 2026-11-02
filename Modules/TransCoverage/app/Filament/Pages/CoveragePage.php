<?php

namespace Modules\TransCoverage\Filament\Pages;

use App\Models\Category;
use App\Models\Post;
use App\Models\Product;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Modules\AiContentWriter\Support\TranslationProgress;
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

    protected static ?int $navigationSort = 13;

    protected static ?string $slug = 'trans-coverage';

    protected string $view = 'transcoverage::coverage';

    /** 「AI 补翻」选中的文章 ID（Livewire 双向绑定） */
    public ?int $fixPostId = null;

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return __('admin.groups.growth_data');
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
     * 视图数据：整表扫描结果（统计卡 + 矩阵行 + 语言列表）+ 补翻实时进度。
     */
    public function getViewData(): array
    {
        return [
            'coverage' => app(CoverageScanner::class)->scan(),
            'progress' => $this->progressRows(),
        ];
    }

    /**
     * 补翻进度区数据：近期有任务记录的实体（文章/产品/分类）× 各语言状态。
     * 页面 5 秒轮询 refresh 时随之更新（无在途任务时轮询由视图侧关闭）。
     *
     * @return list<array{title: string, type_label: string, entries: list<array{locale: string, state: string, at: string, note: string}>, in_flight: bool}>
     */
    protected function progressRows(): array
    {
        if (! class_exists(TranslationProgress::class)) {
            return []; // AiContentWriter 未启用：无任务可跟踪
        }

        $typeLabels = ['post' => '文章', 'product' => '产品', 'category' => '分类'];
        $rows = [];

        foreach (TranslationProgress::trackedEntities() as $entity) {
            ['type' => $type, 'id' => $id] = $entity;
            $entries = TranslationProgress::forEntity($type, $id);

            if ($entries === []) {
                continue;
            }

            $model = match ($type) {
                'post' => Post::find($id),
                'product' => Product::find($id),
                'category' => Category::find($id),
                default => null,
            };

            $title = $model !== null
                ? $model->getTranslation($type === 'post' ? 'title' : 'name', default_locale(), false)
                : '';
            $title = trim((string) $title) ?: ($typeLabels[$type] ?? '内容')." #{$id}";

            $rows[] = [
                'title' => $title,
                'type_label' => $typeLabels[$type] ?? '内容',
                'entries' => $entries,
                'in_flight' => TranslationProgress::hasInFlight($id, $type),
            ];
        }

        return $rows;
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

        // 队列可用时按语言拆任务（与文章编辑页「AI 翻译」同通道）：多语言同步补翻
        // 会在一个 HTTP 请求里跑几分钟，被服务器掐断后 Livewire 只能报「页面加载出错」。
        // 任务由调度器每分钟消化一个并自动落库，矩阵按缓存 TTL 自然刷新。
        if (config('queue.default') !== 'sync') {
            // 清理该文章旧的终态记录（done/failed），重新排队后徽章从零开始
            \Modules\AiContentWriter\Support\TranslationProgress::clear($post->id);

            foreach ($locales as $locale) {
                \Modules\AiContentWriter\Support\TranslationProgress::mark($post->id, $locale, 'queued');
                \Modules\AiContentWriter\Jobs\TranslatePostJob::dispatch($post->id, $locale);
            }

            $this->fixPostId = null;

            Notification::make()
                ->title('已提交 '.count($locales).' 个补翻任务')
                ->body('下方「补翻进度」实时显示各语言状态（排队中 → 翻译中 → 完成）。'
                    .'预计约 '.count($locales).' 分钟内逐个完成，完成后矩阵自动更新。'
                    .'个别语言失败不影响其余语言，失败的语言会留在矩阵里，可重新提交补齐。')
                ->success()
                ->send();

            return;
        }

        // sync 队列退化为内联（一次全量同步——覆盖率页场景语言多，仅低风险兜底）
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
