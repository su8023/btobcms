<?php

namespace App\Filament\Resources\AttrSets\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AttrSetForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('admin.attr_sets.section'))->columns(2)->schema([
                    TextInput::make('name')
                        ->label(__('admin.attr_sets.template_name'))
                        ->required()
                        ->maxLength(191),

                    TextInput::make('code')
                        ->label(__('admin.common.code'))
                        ->required()
                        ->maxLength(64)
                        ->unique(ignoreRecord: true)
                        ->helperText(__('admin.attr_sets.code_helper')),
                ]),
            ]);
    }
}
