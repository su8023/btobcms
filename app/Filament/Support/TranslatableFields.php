<?php

namespace App\Filament\Support;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\RichEditor;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;

/**
 * 按站点语言生成多语言表单字段（name.en / name.es …）。
 */
class TranslatableFields
{
    /**
     * @param  string  $type  text|textarea|rich
     */
    public static function tabs(string $field, string $label, string $type = 'text', bool $requireDefault = false): Tabs
    {
        $tabs = [];

        foreach (site_locales() as $locale) {
            $name = "{$field}.{$locale}";
            $localeLabel = strtoupper($locale);

            $component = match ($type) {
                'textarea' => Textarea::make($name)->rows(3),
                'rich' => RichEditor::make($name)->columnSpanFull(),
                default => TextInput::make($name)->maxLength(255),
            };

            if ($requireDefault && $locale === default_locale()) {
                $component = $component->required();
            }

            $tabs[] = Tab::make($localeLabel)->schema([
                $component->label("{$label} ({$localeLabel})"),
            ]);
        }

        return Tabs::make("{$field}-tabs")->tabs($tabs);
    }

    /**
     * SEO 覆盖字段区块（标题/描述/关键词，多语言）。
     */
    public static function seoSection(): Section
    {
        return Section::make('SEO')
            ->description(__('admin.seo.section_description'))
            ->collapsible()
            ->collapsed()
            ->schema([
                self::tabs('seo.title', __('admin.seo.title'), 'text'),
                self::tabs('seo.description', __('admin.seo.description'), 'textarea'),
                self::tabs('seo.keywords', __('admin.seo.keywords'), 'text'),
            ]);
    }
}
