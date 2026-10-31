<?php

namespace Modules\AiContentWriter\Filament\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Modules\AiContentWriter\Services\ArticleStudio;
use Throwable;
use UnitEnum;

/**
 * AI 写作室：两段式创作。
 *   第 1 步：主题 + 提示词 → AI 出方案（标题/大纲/关键词/FAQ 题目）
 *   第 2 步：可编辑方案 → 确认后按方案成文 → 草稿入库 → 跳转文章编辑页
 */
class AiStudio extends Page
{
    /**
     * 仅管理员可访问：本页每次点击都会消耗运营方的 LLM 额度。
     */
    public static function canAccess(): bool
    {
        return user_is_admin();
    }

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPencilSquare;

    protected static ?int $navigationSort = 40;

    protected static ?string $slug = 'ai-studio';

    protected string $view = 'aicontentwriter::studio';

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('admin.groups.content');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.ai_studio.title');
    }

    public function getTitle(): string|Htmlable
    {
        return __('admin.ai_studio.title');
    }

    // ---- 表单状态 ----
    public string $topic = '';

    public string $prompt = '';

    public string $lang = 'en';

    public ?int $words = null;

    // ---- 方案状态（Livewire 公开属性，前端可编辑） ----
    public ?string $planTitle = null;

    public ?string $planAngle = null;

    /** @var list<array{h2: string, points: string}> */
    public array $planOutline = [];

    /**
     * 关键词：逗号/换行分隔的纯文本。
     *
     * 必须是 string —— 视图里是普通文本框（wire:model="planKeywords"），
     * 之前声明成 array 会让 Livewire 赋值字符串时抛 TypeError：
     * APP_DEBUG=true 直接 500，生产环境静默 419 并丢弃这次编辑。
     */
    public string $planKeywords = '';

    /** FAQ 题目：换行分隔的纯文本（同样必须是 string，见 planKeywords 注释） */
    public string $planFaq = '';

    public bool $planning = false;

    public bool $writing = false;

    public ?int $createdPostId = null;

    public ?string $createdSlug = null;

    public function langOptions(): array
    {
        return ['en' => 'English', 'es' => 'Español', 'ar' => 'العربية', 'ru' => 'Русский', 'pt' => 'Português', 'fr' => 'Français', 'de' => 'Deutsch', 'it' => 'Italiano'];
    }

    /**
     * 第 1 步：生成方案。
     */
    public function makePlan(): void
    {
        if (trim($this->topic) === '') {
            Notification::make()->title(__('admin.ai_studio.topic_required'))->danger()->send();

            return;
        }

        $this->planning = true;
        $this->createdPostId = null;

        try {
            $plan = app(ArticleStudio::class)->plan($this->topic, $this->prompt, $this->lang);

            $this->planTitle = $plan['title'];
            $this->planAngle = $plan['angle'];
            $this->planOutline = collect($plan['outline'])->map(fn ($s) => [
                'h2' => $s['h2'],
                'points' => implode('；', $s['points']),
            ])->all();
            $this->planKeywords = implode(', ', array_map('strval', (array) ($plan['keywords'] ?? [])));
            $this->planFaq = implode("\n", collect((array) ($plan['faq'] ?? []))
                ->pluck('q')
                ->filter()
                ->map(fn ($q) => (string) $q)
                ->all());

            Notification::make()->title(__('admin.ai_studio.plan_ready'))->success()->send();
        } catch (Throwable $e) {
            Notification::make()->title(__('admin.ai_studio.plan_failed'))->body($e->getMessage())->danger()->send();
        } finally {
            $this->planning = false;
        }
    }

    /**
     * 第 2 步：按（可能编辑过的）方案成文。
     */
    public function writeArticle(): void
    {
        if ($this->planTitle === null || $this->planOutline === []) {
            Notification::make()->title(__('admin.ai_studio.no_plan'))->warning()->send();

            return;
        }

        $this->writing = true;

        try {
            $post = app(ArticleStudio::class)->write([
                'title' => $this->planTitle,
                'outline' => collect($this->planOutline)->map(fn ($s) => [
                    'h2' => $s['h2'],
                    'points' => array_filter(array_map('trim', preg_split('/[；;]/u', (string) $s['points']) ?: [])),
                ])->all(),
                'keywords' => $this->splitLines($this->planKeywords, ','),
                'faq' => collect($this->splitLines($this->planFaq))->map(fn ($q) => ['q' => $q])->all(),
            ], $this->lang, $this->words);

            $this->createdPostId = $post->id;
            $this->createdSlug = $post->slug;
            Notification::make()->title(__('admin.ai_studio.draft_created'))->success()->send();
        } catch (Throwable $e) {
            Notification::make()->title(__('admin.ai_studio.write_failed'))->body($e->getMessage())->danger()->send();
        } finally {
            $this->writing = false;
        }
    }

    public function resetStudio(): void
    {
        $this->topic = '';
        $this->prompt = '';
        $this->planTitle = null;
        $this->planAngle = null;
        $this->planOutline = [];
        $this->planKeywords = '';
        $this->planFaq = '';
        $this->createdPostId = null;
        $this->createdSlug = null;
    }

    /**
     * 把多行/逗号分隔的文本切成干净的去重列表。
     *
     * @param  string|null  $extraSeparator 额外的分隔符（如关键词用逗号）
     * @return list<string>
     */
    protected function splitLines(?string $value, string $extraSeparator = ''): array
    {
        if ($value === null || trim($value) === '') {
            return [];
        }

        $pattern = $extraSeparator !== ''
            ? '/[\r\n'.preg_quote($extraSeparator, '/').']+/u'
            : '/[\r\n]+/u';

        $parts = preg_split($pattern, $value) ?: [];

        $parts = array_map(
            fn ($part) => trim((string) $part),
            $parts
        );

        $parts = array_filter($parts, fn ($part) => $part !== '');

        return array_values(array_unique($parts));
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('reset')
                ->label(__('admin.ai_studio.new_article'))
                ->color('gray')
                ->action('resetStudio')
                ->visible(fn () => $this->createdPostId !== null || $this->planTitle !== null),
        ];
    }
}
