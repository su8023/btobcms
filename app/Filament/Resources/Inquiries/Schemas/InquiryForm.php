<?php

namespace App\Filament\Resources\Inquiries\Schemas;

use App\Models\Inquiry;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class InquiryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('admin.inquiries.sections.customer'))->columns(2)->schema([
                    TextInput::make('name')->label(__('admin.inquiries.fields.name'))->required(),
                    TextInput::make('email')->label(__('admin.inquiries.fields.email'))->email()->required(),
                    TextInput::make('phone')->label(__('admin.inquiries.fields.phone')),
                    TextInput::make('company')->label(__('admin.inquiries.fields.company')),
                    TextInput::make('country')->label(__('admin.inquiries.fields.country'))->maxLength(8),
                ]),

                Section::make(__('admin.inquiries.sections.message'))->schema([
                    Textarea::make('message')->label(__('admin.inquiries.message'))->rows(6)->required(),
                ]),

                Section::make(__('admin.inquiries.sections.pipeline'))->columns(2)->schema([
                    Select::make('status')
                        ->label(__('admin.common.status'))
                        ->options(__('admin.inquiries.status_options'))
                        ->default(Inquiry::STATUS_NEW)
                        ->required(),

                    Select::make('assignee_id')
                        ->label(__('admin.inquiries.assigned_to'))
                        ->relationship('assignee', 'name')
                        ->searchable(),

                    Select::make('source')
                        ->label(__('admin.inquiries.source'))
                        ->options(__('admin.inquiries.source_options')),

                    TextInput::make('spam_score')->label(__('admin.inquiries.spam_score'))->numeric()->minValue(0)->maxValue(100),
                ]),

                Section::make(__('admin.inquiries.sections.attribution'))->schema([
                    TextInput::make('ip')
                        ->label(__('admin.inquiries.ip'))
                        ->disabled()
                        ->copyable(),

                    TextInput::make('user_agent')
                        ->label(__('admin.inquiries.user_agent'))
                        ->disabled()
                        ->columnSpanFull(),

                    KeyValue::make('utm')
                        ->keyLabel(__('admin.inquiries.utm_param'))
                        ->valueLabel(__('admin.common.value'))
                        ->disabled(),
                ])->collapsed(),
            ]);
    }
}
