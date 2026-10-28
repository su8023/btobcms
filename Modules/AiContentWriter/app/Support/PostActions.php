<?php

namespace Modules\AiContentWriter\Support;

use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Support\Str;
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
            ->modalDescription('AI 生成标题、摘要、正文与 SEO 关键词并填入下方表单（不落库），生成后可自由编辑再保存/发布。')
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
            ->modalDescription('把当前文章（标题/摘要/正文/SEO 元数据）翻译到所选语言并合并保存，已存在的语言不会被覆盖。')
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

                try {
                    $translated = app(PostTranslator::class)->translate($livewire->getRecord(), $locales);
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title('AI 翻译失败')
                        ->body(mb_substr($e->getMessage(), 0, 300))
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                }

                if ($translated !== []) {
                    // 翻译已改记录：用公开 API 重填表单反映最新内容
                    $record = $livewire->getRecord();
                    $data = [];
                    foreach ($record->translatable ?? [] as $field) {
                        $data[$field] = $record->getTranslations($field);
                    }
                    $meta = $record->seoMeta;
                    $data['seo'] = [
                        'title' => $meta?->getTranslations('title') ?? [],
                        'description' => $meta?->getTranslations('description') ?? [],
                        'keywords' => $meta?->getTranslations('keywords') ?? [],
                    ];
                    $livewire->form->fill($data);
                }

                Notification::make()
                    ->title('翻译完成')
                    ->body('已翻译：'.implode('、', array_map(
                        fn ($l) => config("site.native_names.{$l}", $l),
                        $translated,
                    )))
                    ->success()
                    ->persistent()
                    ->send();
            });
    }

    protected static function seoCheck(): Action
    {
        return Action::make('seoCheck')
            ->label('SEO 体检')
            ->icon('heroicon-o-clipboard-document-check')
            ->color('gray')
            ->modalHeading('发布前 SEO 体检')
            ->modalDescription(function ($livewire): string {
                $problems = app(SeoChecklist::class)->checkState(self::formState($livewire));

                return $problems === []
                    ? '✅ 当前内容全部通过，可以发布。'
                    : '发现 '.count($problems).' 个问题，见下方明细：';
            })
            ->modalContent(function ($livewire) {
                $problems = app(SeoChecklist::class)->checkState(self::formState($livewire));

                if ($problems === []) {
                    return null;
                }

                $html = '<ul class="text-sm space-y-1">';
                foreach ($problems as $p) {
                    $html .= '<li>⚠️ '.e($p).'</li>';
                }

                return new \Illuminate\Support\HtmlString($html.'</ul>');
            })
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
