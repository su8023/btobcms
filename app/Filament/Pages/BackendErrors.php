<?php

namespace App\Filament\Pages;

use App\Services\BackendErrorLog;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * 后台错误诊断页（/admin/backend-errors）：
 * 列出最近的后台请求失败记录（异常类 + 消息 + 出错位置），
 * 让「页面加载出错」从无从下手的通用提示变成可定位的具体原因。
 */
class BackendErrors extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static ?int $navigationSort = 45;

    protected string $view = 'filament.pages.backend-errors';

    /** 仅管理员：里面有异常细节与内部路径 */
    public static function canAccess(): bool
    {
        return user_is_admin();
    }

    public static function getNavigationLabel(): string
    {
        return '错误诊断';
    }

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return __('admin.groups.system');
    }

    public function getTitle(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return '后台错误诊断';
    }

    /**
     * @return list<array{time: string, method: string, url: string, exception: string, message: string, status: int|null, at: string}>
     */
    public function getEntriesProperty(): array
    {
        return BackendErrorLog::recent(30);
    }

    /** 清空诊断记录 */
    public function clear(): void
    {
        $removed = BackendErrorLog::clear();

        Notification::make()
            ->title($removed > 0 ? "已清空 {$removed} 条诊断记录" : '没有可清空的诊断记录')
            ->success()
            ->send();
    }
}
