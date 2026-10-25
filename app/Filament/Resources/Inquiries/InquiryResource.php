<?php

namespace App\Filament\Resources\Inquiries;

use App\Filament\Resources\Inquiries\Pages\CreateInquiry;
use App\Filament\Resources\Inquiries\Pages\EditInquiry;
use App\Filament\Resources\Inquiries\Pages\ListInquiries;
use App\Filament\Resources\Inquiries\RelationManagers\FollowupsRelationManager;
use App\Filament\Resources\Inquiries\Schemas\InquiryForm;
use App\Filament\Resources\Inquiries\Tables\InquiriesTable;
use App\Models\Inquiry;
use BackedEnum;
use UnitEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;

class InquiryResource extends Resource
{
    protected static ?string $model = Inquiry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInbox;

    protected static ?int $navigationSort = 5;

    protected static ?string $recordTitleAttribute = 'email';

    public static function getModelLabel(): string
    {
        return __('admin.inquiries.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.inquiries.plural');
    }

    /** 全局搜索（顶栏）：客户名/邮箱/公司 */
    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'email', 'company'];
    }

    public static function getGlobalSearchResultTitle($record): string|Htmlable
    {
        return trim(($record->name ?: $record->email).' — '.($record->company ?: ''));
    }

    public static function getNavigationBadge(): ?string
    {
        $count = Inquiry::query()->where('status', Inquiry::STATUS_NEW)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function form(Schema $schema): Schema
    {
        return InquiryForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return InquiriesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            FollowupsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInquiries::route('/'),
            'create' => CreateInquiry::route('/create'),
            'edit' => EditInquiry::route('/{record}/edit'),
        ];
    }
}
