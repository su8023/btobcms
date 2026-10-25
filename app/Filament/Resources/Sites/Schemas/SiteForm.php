<?php

namespace App\Filament\Resources\Sites\Schemas;

use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SiteForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('admin.sites.section'))->columns(2)->schema([
                    TextInput::make('name')->label(__('admin.common.name'))->required()->maxLength(191),

                    TextInput::make('domain')
                        ->label(__('admin.sites.domain'))
                        ->maxLength(191)
                        ->unique(ignoreRecord: true)
                        ->placeholder(__('admin.sites.domain_placeholder')),

                    Select::make('default_locale')
                        ->label(__('admin.sites.default_locale'))
                        ->options(fn () => array_combine(
                            config('site.locales'),
                            array_map(fn ($l) => config("site.native_names.{$l}", $l), config('site.locales')),
                        ))
                        ->default(config('site.default_locale'))
                        ->required(),

                    CheckboxList::make('locales')
                        ->label(__('admin.sites.enabled_locales'))
                        ->options(fn () => array_combine(
                            config('site.locales'),
                            array_map(fn ($l) => config("site.native_names.{$l}", $l), config('site.locales')),
                        ))
                        ->columns(2)
                        ->default(config('site.locales')),

                    TextInput::make('theme')->label(__('admin.sites.theme'))->maxLength(64)->placeholder(__('admin.sites.theme_placeholder')),

                    Toggle::make('is_active')->label(__('admin.sites.is_active'))->default(true),
                ]),
            ]);
    }
}
