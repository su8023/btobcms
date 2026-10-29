<?php

namespace Modules\LeadMagnet\Filament\Resources\Magnets\Schemas;

use App\Filament\Support\TranslatableFields;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class MagnetForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('基本信息')->columns(2)->schema([
                    TextInput::make('slug')
                        ->label('URL 标识')
                        ->required()
                        ->maxLength(191)
                        ->unique(ignoreRecord: true)
                        ->helperText('落地页地址 /download/{slug}，建议小写英文加连字符'),

                    Toggle::make('active')
                        ->label('启用')
                        ->default(true)
                        ->helperText('关闭后落地页与提交流程均 404'),
                ]),

                Section::make('多语言内容')->schema([
                    TranslatableFields::tabs('title', '标题', 'text', requireDefault: true),
                    TranslatableFields::tabs('description', '描述', 'textarea'),
                ]),

                Section::make('资料文件')->schema([
                    // 存 local private 盘（storage/app/private），只能经签名路由下载
                    FileUpload::make('file_path')
                        ->label('文件')
                        ->disk('local')
                        ->directory('lead-magnets')
                        ->maxSize(20480) // 20 MB
                        ->acceptedFileTypes([
                            'application/pdf',
                            'application/zip',
                            'application/x-zip-compressed',
                            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                        ])
                        ->required()
                        ->helperText('支持 PDF / ZIP / DOCX，最大 20 MB。文件不公开，买家只能通过邮件里的限时签名链接下载。'),
                ]),
            ]);
    }
}
