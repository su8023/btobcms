<?php

namespace Modules\TrustBuilder\Filament\Resources\Certifications\Schemas;

use App\Filament\Support\TranslatableFields;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CertificationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('trustbuilder.admin.section_basics'))->columns(2)->schema([
                    TextInput::make('issuer')
                        ->label(__('trustbuilder.admin.issuer'))
                        ->maxLength(255),

                    TextInput::make('cert_no')
                        ->label(__('trustbuilder.admin.cert_no'))
                        ->maxLength(255),

                    DatePicker::make('issued_at')
                        ->label(__('trustbuilder.admin.issued_at')),

                    DatePicker::make('expires_at')
                        ->label(__('trustbuilder.admin.expires_at'))
                        ->after('issued_at'),

                    TextInput::make('sort')
                        ->label(__('trustbuilder.admin.sort'))
                        ->numeric()
                        ->default(0)
                        ->helperText(__('trustbuilder.admin.sort_helper')),
                ]),

                Section::make(__('trustbuilder.admin.section_content'))->schema([
                    TranslatableFields::tabs('name', __('trustbuilder.admin.cert_name'), 'text', requireDefault: true),
                ]),

                Section::make(__('trustbuilder.admin.section_media'))->schema([
                    // 证书扫描件：public 盘（前台信任条/详情页可直接访问）
                    FileUpload::make('image')
                        ->label(__('trustbuilder.admin.image'))
                        ->disk('public')
                        ->directory('trust-builder')
                        ->image()
                        ->imageEditor()
                        ->maxSize(5120)
                        ->helperText(__('trustbuilder.admin.image_helper')),
                ]),
            ]);
    }
}
