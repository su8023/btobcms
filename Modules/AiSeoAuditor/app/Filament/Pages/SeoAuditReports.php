<?php

namespace Modules\AiSeoAuditor\Filament\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Artisan;
use UnitEnum;

/**
 * SEO 巡检报告：读取 seo:audit 命令落盘的 JSON 报告并可视化。
 * 插件停用时不注册（由 AdminPanelProvider 条件加载）。
 */
class SeoAuditReports extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?int $navigationSort = 33;

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
                    Artisan::call('seo:audit', [
                        '--base-url' => rtrim((string) config('app.url'), '/'),
                    ]);

                    $output = trim(Artisan::output());
                    $tail = implode("\n", array_slice(explode("\n", $output), -4));

                    Notification::make()
                        ->title(__('admin.seo_audit.run_done'))
                        ->body($tail !== '' ? $tail : null)
                        ->success()
                        ->persistent()
                        ->send();
                }),
        ];
    }
}
