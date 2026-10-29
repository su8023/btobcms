<?php

namespace App\Filament\Resources\Posts\Schemas;

use App\Filament\Support\TranslatableFields;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PostForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('admin.common.sections.basics'))->columns(2)->schema([
                    TextInput::make('slug')
                        ->label(__('admin.common.slug'))
                        ->helperText(__('admin.common.slug_helper'))
                        ->required()
                        ->maxLength(255)
                        ->unique(ignoreRecord: true),

                    Select::make('type')
                        ->label(__('admin.common.type'))
                        ->options(__('admin.posts.type_options'))
                        ->default('post'),

                    Select::make('status')
                        ->label(__('admin.common.status'))
                        ->options(__('admin.posts.status_options'))
                        ->default('draft')
                        ->required(),

                    DateTimePicker::make('published_at')
                        ->label(__('admin.common.published_at'))
                        ->helperText(__('admin.posts.published_at_helper')),

                    FileUpload::make('cover')
                        ->label(__('admin.posts.cover'))
                        ->disk('public')
                        ->directory('posts')
                        ->image()
                        ->imageEditor()
                        ->columnSpanFull(),

                    Toggle::make('is_ai_generated')
                        ->label(__('admin.posts.ai_generated'))
                        ->default(false)
                        ->helperText(__('admin.posts.ai_generated_helper')),
                ]),

                Section::make(__('admin.common.sections.content'))->schema([
                    TranslatableFields::tabs('title', __('admin.posts.title'), 'text', requireDefault: true),
                    TranslatableFields::tabs('excerpt', __('admin.posts.excerpt'), 'textarea'),
                    TranslatableFields::tabs('content', __('admin.posts.content'), 'rich'),
                ]),

                TranslatableFields::seoSection(),
            ]);
    }
}
