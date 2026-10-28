<?php

namespace Modules\GeoBoost\Support;

use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Modules\GeoBoost\Services\FaqGenerator;

/**
 * 文章编辑器动作（PluginPostActions 约定）：
 * AI 生成 FAQ → 前台 FAQ 区块 + FAQPage JSON-LD（编辑页只有已保存文章才可用）。
 */
class PostActions
{
    /**
     * @return list<Action>
     */
    public static function editorActions(string $page): array
    {
        return $page === 'edit' ? [self::generateFaq()] : [];
    }

    protected static function generateFaq(): Action
    {
        return Action::make('aiFaq')
            ->label('AI 生成 FAQ')
            ->icon('heroicon-o-question-mark-circle')
            ->color('gray')
            ->modalHeading('AI 生成买家 FAQ')
            ->modalDescription('根据当前文章正文生成买家常见问答：前台文章底部渲染 FAQ 区块，并输出 FAQPage JSON-LD（谷歌富摘要 + AI 引擎引用）。重新生成会替换同语言的 AI 来源 FAQ，人工编辑的保留。')
            ->form(fn ($livewire): array => [
                Select::make('locale')
                    ->label('语言')
                    ->options(collect(site_locales())
                        ->filter(fn ($l) => filled($livewire->getRecord()->getTranslation('content', $l, false)))
                        ->mapWithKeys(fn ($l) => [$l => config("site.native_names.{$l}", $l)]))
                    ->required()
                    ->helperText('只列出已有正文的语言'),
            ])
            ->action(function (array $data, $livewire): void {
                try {
                    $count = app(FaqGenerator::class)->generateFor(
                        $livewire->getRecord(),
                        (string) $data['locale'],
                    );
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title('FAQ 生成失败')
                        ->body(mb_substr($e->getMessage(), 0, 300))
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title("已生成 {$count} 组 FAQ")
                    ->body('前台文章底部立即生效，无需重新发布')
                    ->success()
                    ->send();
            });
    }
}
