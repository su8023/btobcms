<?php

namespace Modules\TrustBuilder\Filament\Resources;

use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Modules\TrustBuilder\Filament\Resources\Certifications\Pages\CreateCertification;
use Modules\TrustBuilder\Filament\Resources\Certifications\Pages\EditCertification;
use Modules\TrustBuilder\Filament\Resources\Certifications\Pages\ListCertifications;
use Modules\TrustBuilder\Filament\Resources\Certifications\Schemas\CertificationForm;
use Modules\TrustBuilder\Filament\Resources\Certifications\Tables\CertificationsTable;
use Modules\TrustBuilder\Models\TrustCertification;

/**
 * 认证证书后台管理。
 *
 * 注意：资源类文件须位于 Resources/ 顶层（插件资源约定式发现只扫描
 * Resources/*Resource.php），Pages/Schemas/Tables 分层放 Certifications/ 子目录。
 */
class CertificationResource extends Resource
{
    protected static ?string $model = TrustCertification::class;

    // URL 标识：/admin/certifications（显式声明，避免类名推导歧义）
    protected static ?string $slug = 'certifications';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?int $navigationSort = 33;

    protected static ?string $recordTitleAttribute = 'cert_no';

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return __('admin.groups.growth');
    }

    public static function getNavigationLabel(): string
    {
        return __('trustbuilder.admin.certifications');
    }

    public static function getModelLabel(): string
    {
        return __('trustbuilder.admin.certification');
    }

    public static function getPluralModelLabel(): string
    {
        return __('trustbuilder.admin.certifications');
    }

    public static function form(Schema $schema): Schema
    {
        return CertificationForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CertificationsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCertifications::route('/'),
            'create' => CreateCertification::route('/create'),
            'edit' => EditCertification::route('/{record}/edit'),
        ];
    }
}
