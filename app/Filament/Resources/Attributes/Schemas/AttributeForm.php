<?php

namespace App\Filament\Resources\Attributes\Schemas;

use App\Filament\Support\TranslatableFields;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AttributeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('admin.attributes.sections.definition'))->columns(2)->schema([
                    Select::make('attr_set_id')
                        ->label(__('admin.attributes.template'))
                        ->relationship('attrSet', 'name')
                        ->required()
                        ->searchable()
                        ->preload(),

                    TextInput::make('code')
                        ->label(__('admin.common.code'))
                        ->required()
                        ->maxLength(64)
                        ->helperText(__('admin.attributes.code_helper')),

                    Select::make('type')
                        ->label(__('admin.common.type'))
                        ->options(__('admin.attributes.type_options'))
                        ->default('text')
                        ->live()
                        ->required(),

                    TextInput::make('unit')->label(__('admin.common.unit'))->maxLength(32)->placeholder(__('admin.attributes.unit_placeholder')),

                    TagsInput::make('options')
                        ->label(__('admin.attributes.options'))
                        ->placeholder(__('admin.attributes.options_placeholder'))
                        ->visible(fn ($state, callable $get) => in_array($get('type'), ['enum', 'multi']))
                        ->columnSpan(2),

                    TextInput::make('sort_order')->label(__('admin.common.sort_order'))->numeric()->default(0),

                    Toggle::make('is_filterable')
                        ->label(__('admin.attributes.filterable_toggle'))
                        ->default(true),

                    Toggle::make('is_required')->label(__('admin.attributes.is_required'))->default(false),
                ]),

                Section::make(__('admin.attributes.sections.label_multi'))->schema([
                    TranslatableFields::tabs('name', __('admin.attributes.attribute_label'), 'text', requireDefault: true),
                ]),
            ]);
    }
}
