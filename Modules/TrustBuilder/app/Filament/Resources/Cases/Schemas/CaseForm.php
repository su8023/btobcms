<?php

namespace Modules\TrustBuilder\Filament\Resources\Cases\Schemas;

use App\Filament\Support\TranslatableFields;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CaseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('trustbuilder.admin.section_basics'))->columns(2)->schema([
                    TextInput::make('slug')
                        ->label(__('trustbuilder.admin.slug'))
                        ->required()
                        ->maxLength(255)
                        ->unique(ignoreRecord: true)
                        ->helperText(__('trustbuilder.admin.slug_helper')),

                    TextInput::make('customer_name')
                        ->label(__('trustbuilder.admin.customer_name'))
                        ->required()
                        ->maxLength(255),

                    TextInput::make('industry')
                        ->label(__('trustbuilder.admin.industry'))
                        ->maxLength(255)
                        ->helperText(__('trustbuilder.admin.industry_helper')),

                    TextInput::make('country')
                        ->label(__('trustbuilder.admin.country'))
                        ->maxLength(8)
                        ->helperText(__('trustbuilder.admin.country_helper')),

                    Toggle::make('published')
                        ->label(__('trustbuilder.admin.published'))
                        ->default(false)
                        ->helperText(__('trustbuilder.admin.published_helper')),

                    TextInput::make('sort')
                        ->label(__('trustbuilder.admin.sort'))
                        ->numeric()
                        ->default(0),
                ]),

                Section::make(__('trustbuilder.admin.section_content'))->schema([
                    TranslatableFields::tabs('title', __('trustbuilder.admin.title'), 'text', requireDefault: true),
                    TranslatableFields::tabs('body', __('trustbuilder.admin.body'), 'rich'),
                ]),

                Section::make(__('trustbuilder.admin.section_media'))->columns(2)->schema([
                    // 封面图：public 盘（前台可直接访问）
                    FileUpload::make('cover')
                        ->label(__('trustbuilder.admin.cover'))
                        ->disk('public')
                        ->directory('trust-builder')
                        ->image()
                        ->imageEditor()
                        ->maxSize(5120)
                        ->helperText(__('trustbuilder.admin.cover_helper')),

                    // 图集：多图、可拖拽排序，json 列存储路径列表
                    FileUpload::make('images')
                        ->label(__('trustbuilder.admin.images'))
                        ->disk('public')
                        ->directory('trust-builder')
                        ->image()
                        ->multiple()
                        ->reorderable()
                        ->maxSize(5120)
                        ->helperText(__('trustbuilder.admin.images_helper')),
                ]),
            ]);
    }
}
