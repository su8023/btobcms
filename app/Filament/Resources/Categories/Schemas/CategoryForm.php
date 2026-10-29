<?php

namespace App\Filament\Resources\Categories\Schemas;

use App\Filament\Support\TranslatableFields;
use App\Models\AttrSet;
use App\Models\Category;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('admin.common.sections.basics'))->columns(2)->schema([
                    Select::make('parent_id')
                        ->label(__('admin.categories.parent'))
                        ->options(function (Select $component) {
                            $query = Category::query()->orderBy('path');

                            // 编辑时排除自身与后代，避免成环
                            if ($record = $component->getRecord()) {
                                $exclude = Category::query()
                                    ->where('path', 'like', $record->path.'/%')
                                    ->pluck('id')
                                    ->push($record->id);
                                $query->whereNotIn('id', $exclude);
                            }

                            return $query->get()
                                ->mapWithKeys(fn (Category $c) => [
                                    $c->id => str_repeat('— ', substr_count((string) $c->path, '/'))
                                        .($c->getTranslation('name', default_locale()) ?: $c->slug),
                                ])
                                ->all();
                        })
                        ->searchable()
                        ->preload(),

                    Select::make('attr_set_id')
                        ->label(__('admin.categories.attr_template'))
                        ->relationship('attrSet', 'name')
                        ->helperText(__('admin.categories.attr_template_helper')),

                    TextInput::make('slug')
                        ->label(__('admin.common.slug'))
                        ->helperText(__('admin.common.slug_helper'))
                        ->required()
                        ->maxLength(255)
                        ->unique(ignoreRecord: true),

                    TextInput::make('sort_order')->label(__('admin.common.sort_order'))->numeric()->default(0),

                    Toggle::make('status')
                        ->label(__('admin.categories.visible'))
                        ->default(true),
                ]),

                Section::make(__('admin.common.sections.content'))->schema([
                    TranslatableFields::tabs('name', __('admin.categories.name'), 'text', requireDefault: true),
                    TranslatableFields::tabs('description', __('admin.categories.description'), 'rich'),
                ]),

                TranslatableFields::seoSection(),
            ]);
    }
}
