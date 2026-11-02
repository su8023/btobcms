<?php

namespace Modules\AiContentWriter\Support;

use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Support\Str;
use Modules\AiContentWriter\Jobs\TranslatePostJob;
use Modules\AiContentWriter\Services\ArticleStudio;
use Modules\AiContentWriter\Services\PostTranslator;
use Modules\AiContentWriter\Services\SeoChecklist;

/**
 * 文章编辑器 AI 动作（经 PluginPostActions 约定挂到核心 Post 页面）：
 *   - create/edit：AI 填充（主题 → 方案 → 全文 → 填入当前表单，不落库）
 *   - edit：AI 翻译（把已有文章一键翻译到所选语言，含 SEO 元数据）
 *   - create/edit：SEO 体检（纯规则即时检查表单当前内容，发布前自查）
 *
 * 全部闭包用 Filament 标准注入（$livewire/$data），不依赖 $this 绑定——
 * 本类方法均为静态，闭包在静态上下文定义，绑定不可用。
 */
class PostActions
{
    /**
     * @return list<Action>
     */
    public static function editorActions(string $page): array
    {
        return match ($page) {
            'create' => [self::compose(), self::seoCheck()],
            'edit' => [self::compose(), self::translate(), self::seoCheck()],
            default => [],
        };
    }

    protected static function compose(): Action
    {
        return Action::make('aiCompose')
            ->label('AI 填充')
            ->icon('heroicon-o-sparkles')
            ->color('gray')
            ->modalHeading('AI 填充文章内容')
            ->modalDescription('AI 生成标题、摘要、正文与 SEO 关键词并填入下方表单（不落库），生成后可自由编辑再保存/发布。注意：所选语言下当前表单的内容（含未保存的修改）会被 AI 新内容替换。推荐顺序：AI 填充 → AI 翻译 → SEO 体检 → 发布。')
            ->form([
                TextInput::make('topic')
                    ->label('主题 / 核心关键词')
                    ->helperText('例：How to choose a gate valve supplier from China')
                    ->required()
                    ->maxLength(500),
                Select::make('lang')
                    ->label('语言')
                    ->options(collect(site_locales())
                        ->mapWithKeys(fn ($l) => [$l => config("site.native_names.{$l}", $l)]))
                    ->default(default_locale())
                    ->required(),
                TextInput::make('words')
                    ->label('目标字数（词）')
                    ->numeric()
                    ->minValue(300)
                    ->maxValue(3000)
                    ->default(fn () => (int) (plugin_setting('ai-content-writer', 'default_words', 0) ?: 1200)),
                Textarea::make('extra')
                    ->label('额外要求（可选）')
                    ->rows(3)
                    ->maxLength(1000)
                    ->placeholder('例：重点写 OEM 定制能力，提到 MOQ 灵活'),
            ])
            ->action(function (array $data, $livewire): void {
                $lang = (string) $data['lang'];

                try {
                    $studio = app(ArticleStudio::class);
                    $plan = $studio->plan((string) $data['topic'], (string) ($data['extra'] ?? ''), $lang);
                    $result = $studio->compose($plan, $lang, (int) ($data['words'] ?? 0) ?: null);
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title('AI 生成失败')
                        ->body(mb_substr($e->getMessage(), 0, 300))
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                }

                // 填入当前表单（不落库，运营检查后自行保存）
                $state = [
                    "title.{$lang}" => $plan['title'],
                    "excerpt.{$lang}" => $result['excerpt'],
                    "content.{$lang}" => $result['html'],
                    "seo.keywords.{$lang}" => implode(', ', $result['keywords']),
                ];

                // 新建页 slug 必填：AI 没给就用标题兜底
                $currentData = is_array($livewire->data ?? null) ? $livewire->data : [];
                if (blank($currentData['slug'] ?? null)) {
                    $state['slug'] = $result['slug'] ?: (Str::slug($plan['title']) ?: 'post-'.Str::random(6));
                }

                $livewire->form->fillPartially($state, array_keys($state));

                Notification::make()
                    ->title('AI 内容已填入表单')
                    ->body('关键词：'.implode('、', array_slice($result['keywords'], 0, 4)).'…… 请检查内容后保存')
                    ->success()
                    ->persistent()
                    ->send();
            });
    }

    protected static function translate(): Action
    {
        return Action::make('aiTranslate')
            ->label('AI 翻译')
            ->icon('heroicon-o-language')
            ->color('gray')
            ->modalHeading('AI 翻译到更多语言')
            ->modalDescription('把当前文章（标题/摘要/正文/SEO 元数据）翻译到所选语言。所选语言进入后台队列逐个处理（每分钟一个）并自动保存，完成后刷新本页即可看到新语言；也可在「翻译覆盖率」页查看进度。已存在的语言不会被覆盖。')
            ->form(function ($livewire): array {
                $record = $livewire->getRecord();

                // 只列尚未有标题翻译的语言
                $remaining = collect(site_locales())
                    ->reject(fn ($l) => filled($record->getTranslation('title', $l, false)))
                    ->mapWithKeys(fn ($l) => [$l => config("site.native_names.{$l}", $l)]);

                return [
                    CheckboxList::make('locales')
                        ->label('目标语言')
                        ->options($remaining)
                        ->columns(4)
                        ->required()
                        ->helperText($remaining->isEmpty() ? '所有语言均已翻译' : null)
                        ->disabled($remaining->isEmpty()),
                ];
            })
            ->visible(function ($livewire): bool {
                $record = $livewire->getRecord();

                return $record !== null && filled($record->getTranslation('title', default_locale(), false));
            })
            ->action(function (array $data, $livewire): void {
                $locales = array_values((array) ($data['locales'] ?? []));
                if ($locales === []) {
                    return;
                }

                $record = $livewire->getRecord();

                // 队列未配置（QUEUE_CONNECTION=sync）时退化为单语言内联：
                // 一次请求只跑一种语言，避免多语言同步超时被服务器掐断。
                if (config('queue.default') === 'sync') {
                    try {
                        $translated = app(PostTranslator::class)->translate($record, array_slice($locales, 0, 1));
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title('AI 翻译失败')
                            ->body(mb_substr($e->getMessage(), 0, 300))
                            ->danger()
                            ->persistent()
                            ->send();

                        return;
                    }

                    // 0 个语言成功（如 AI 返回无法解析被内部跳过）时明确报失败，
                    // 不能让「已翻译 1 种语言」的空括号假成功糊弄过去（复核 D-P1-3）
                    if ($translated === []) {
                        Notification::make()
                            ->title('AI 翻译失败')
                            ->body('该语言的 AI 输出无法解析（详细原因见 storage/logs/laravel.log），请重试。')
                            ->danger()
                            ->persistent()
                            ->send();

                        return;
                    }

                    self::fillTranslatedLocales($livewire, $record, $translated);

                    Notification::make()
                        ->title('已翻译 1 种语言（'.implode('、', array_map(
                            fn ($l) => config("site.native_names.{$l}", $l),
                            $translated,
                        )).'）')
                        ->body('当前队列驱动是 sync（不支持后台任务），每次只能翻译一种语言——请再次点击「AI 翻译」继续其余语言。')
                        ->warning()
                        ->persistent()
                        ->send();

                    return;
                }

                // 每语言一个队列任务：调度器每分钟消化一个，互不阻塞、单语言失败不拖累其余
                foreach ($locales as $locale) {
                    \Modules\AiContentWriter\Support\TranslationProgress::mark($record->id, $locale, 'queued');
                    TranslatePostJob::dispatch($record->id, $locale);
                }

                Notification::make()
                    ->title('已提交 '.count($locales).' 个翻译任务')
                    ->body('后台每分钟处理一个语言并自动保存，预计约 '.count($locales).' 分钟完成。'
                        .'实时进度在「翻译覆盖率」页的「补翻进度」区查看；完成后刷新本页即可看到新语言。'
                        .'个别语言失败不影响其余语言，失败的语言会留在覆盖率矩阵里，可重新提交补齐。')
                    ->success()
                    ->persistent()
                    ->send();
            });
    }

    /**
     * 把已翻译语言填回表单（fillPartially）：保留表单里未保存的其他修改，
     * 全量 fill 会把已编辑内容的未保存修改静默覆盖回库值。
     *
     * @param  list<string>  $translated
     */
    protected static function fillTranslatedLocales($livewire, $record, array $translated): void
    {
        if ($translated === []) {
            return;
        }

        $meta = $record->seoMeta;
        $state = [];

        foreach ($translated as $locale) {
            foreach (['title', 'excerpt', 'content'] as $field) {
                $state[$field.'.'.$locale] = $record->getTranslation($field, $locale);
            }
            foreach (['title', 'description', 'keywords'] as $field) {
                $state['seo.'.$field.'.'.$locale] = (string) $meta?->getTranslation($field, $locale, false);
            }
        }

        $livewire->form->fillPartially($state, array_keys($state));
    }

    protected static function seoCheck(): Action
    {
        return Action::make('seoCheck')
            ->label('SEO 体检')
            ->icon('heroicon-o-clipboard-document-check')
            ->color('gray')
            ->modalHeading('发布前 SEO 体检')
            ->modalDescription(function ($livewire): string {
                $report = app(SeoChecklist::class)->report(self::formState($livewire));

                if (! $report['checked']) {
                    return '还没有任何语言的内容可检查。';
                }

                return $report['summary']['problems'] === 0
                    ? '✅ 当前内容全部通过，可以发布。'
                    : '发现 '.$report['summary']['problems'].' 个待修复项，见下方明细：';
            })
            ->modalContent(function ($livewire) {
                return view('aicontentwriter::seo-check', [
                    'report' => app(SeoChecklist::class)->report(self::formState($livewire)),
                ]);
            })
            ->modalWidth(\Filament\Support\Enums\Width::FourExtraLarge)
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('关闭')
            ->action(fn () => null);
    }

    /**
     * @return array<string, mixed>
     */
    protected static function formState($livewire): array
    {
        return is_array($livewire->data ?? null) ? $livewire->data : [];
    }
}
