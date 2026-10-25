<?php

namespace App\Filament\Resources\Redirects\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class RedirectForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('admin.redirects.section'))->columns(3)->schema([
                    TextInput::make('from_path')
                        ->label(__('admin.redirects.from_path'))
                        ->required()
                        ->maxLength(500)
                        ->placeholder(__('admin.redirects.from_placeholder'))
                        ->helperText(__('admin.redirects.from_helper')),

                    TextInput::make('to_path')
                        ->label(__('admin.redirects.to_path'))
                        ->required()
                        ->maxLength(500)
                        ->placeholder(__('admin.redirects.to_placeholder')),

                    Select::make('code')
                        ->label(__('admin.common.code'))
                        ->options(__('admin.redirects.code_options'))
                        ->default(301)
                        ->required(),
                ]),
            ]);
    }
}
