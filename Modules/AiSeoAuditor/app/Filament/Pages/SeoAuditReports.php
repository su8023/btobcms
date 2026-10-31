<?php

namespace Modules\AiSeoAuditor\Filament\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Modules\AiSeoAuditor\Jobs\RunSeoAudit;
use UnitEnum;

/**
 * SEO 巡检报告：读取 seo:audit 命令落盘的 JSON 报告并可视化。
 * 插件停用时不注册（由 AdminPanelProvider 条件加载）。
 */
class SeoAuditReports extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?int $navigationSort = 41;

    protected string $view = 'aiseoauditor::filament.reports';

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('admin.groups.system');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.seo_audit.title');
    }

    public function getTitle(): string|Htmlable
    {
        return __('admin.seo_audit.title');
    }

    /**
     * @return list<array{file: string, finished_at: ?string, checked: int, issue_count: int, summary: array<string, int>, issues: list<array{type: string, url: string, detail?: string}>, is_latest: bool}>
     */
    public function getReports(): array
    {
        $files = glob(storage_path('app/seo-audits/audit-*.json')) ?: [];
        rsort($files);

        return array_map(function (string $file, int $index): array {
            $data = json_decode((string) file_get_contents($file), true) ?: [];

            return [
                'file' => basename($file),
                'finished_at' => $data['finished_at'] ?? null,
                'checked' => (int) ($data['checked'] ?? 0),
                'issue_count' => is_array($data['issues'] ?? null) ? count($data['issues']) : 0,
                'summary' => is_array($data['summary'] ?? null) ? $data['summary'] : [],
                'issues' => is_array($data['issues'] ?? null) ? $data['issues'] : [],
                'is_latest' => $index === 0,
            ];
        }, $files, array_keys($files));
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('run_audit')
                ->label(__('admin.seo_audit.run'))
                ->icon('heroicon-o-magnifying-glass')
                ->requiresConfirmation()
                ->modalDescription(__('admin.seo_audit.run_confirm'))
                ->action(function (): void {
                    // 巡检是长耗时任务（最坏 ~17 分钟），必须排队执行：
                    // 在 HTTP 请求里同步跑会被网关掐断，管理员只会看到 504。
                    $limit = max(1, (int) plugin_setting('ai-seo-auditor', 'limit', 100));
                    $timeout = max(1, (int) plugin_setting('ai-seo-auditor', 'audit_timeout', 10));

                    RunSeoAudit::dispatch(
                        rtrim((string) config('app.url'), '/'),
                        $limit,
                        $timeout
                    );

                    Notification::make()
                        ->title(__('admin.seo_audit.run_queued') ?: '巡检已排队')
                        ->body(__('admin.seo_audit.run_queued_hint') ?: '巡检在后台运行，完成后会在下方生成报告并推送通知。')
                        ->success()
                        ->send();
                }),
        ];
    }
}
