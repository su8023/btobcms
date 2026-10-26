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
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPencilSquare;

    protected static ?int $navigationSort = 31;

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

    /** @var list<string> */
    public array $planKeywords = [];

    /** @var list<string> */
    public array $planFaq = [];

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
            $this->planKeywords = $plan['keywords'];
            $this->planFaq = collect($plan['faq'])->pluck('q')->all();

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
                'keywords' => $this->planKeywords,
                'faq' => collect($this->planFaq)->map(fn ($q) => ['q' => $q])->all(),
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
        $this->planKeywords = [];
        $this->planFaq = [];
        $this->createdPostId = null;
        $this->createdSlug = null;
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
