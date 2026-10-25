<?php

namespace Modules\UpdateCenter\Filament\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Modules\UpdateCenter\Jobs\RunUpdate;
use Modules\UpdateCenter\Services\GitHubReleases;
use Modules\UpdateCenter\Services\Updater;
use UnitEnum;

/**
 * 更新中心：当前版本 vs GitHub 最新 Release、变更说明、历史版本、一键升级、执行日志。
 * 更新在队列中执行（维护模式+备份+回滚见 Updater），页面轮询日志。
 */
class UpdateCenterPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPath;

    protected static ?int $navigationSort = 34;

    protected string $view = 'updatecenter::update-center';

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('admin.groups.system');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.update_center.title');
    }

    public function getTitle(): string|Htmlable
    {
        return __('admin.update_center.title');
    }

    public static function getNavigationBadge(): ?string
    {
        // 有新版本时导航出红点提示
        $client = GitHubReleases::fromSettings();
        if ($client === null) {
            return null;
        }
        $latest = $client->latest();

        return ($latest !== null && $latest['tag'] !== config('version.version') && $latest['tag'] !== '') ? '!' : null;
    }

    // ---- 页面数据 ----

    /** @var list<array{tag: string, name: string, date: ?string, notes: string, prerelease: bool}> */
    public array $releases = [];

    public ?string $latestTag = null;

    public ?string $latestNotes = null;

    public string $currentVersion = '';

    public ?string $gitTag = null;

    public ?string $gitCommit = null;

    public string $githubError = '';

    /** @var list<string> */
    public array $preflightProblems = [];

    public bool $configured = false;

    public string $repo = '';

    public function mount(): void
    {
        $this->currentVersion = (string) config('version.version');
        $this->configured = GitHubReleases::fromSettings() !== null;
        $this->repo = (string) plugin_setting('update-center', 'github_repo', '');

        $git = \Modules\UpdateCenter\Services\GitService::fromSettings();
        $this->gitTag = $git->currentTag();
        $this->gitCommit = $git->currentCommit();
        $this->preflightProblems = $this->configured ? Updater::fromSettings()->preflight() : [];

        if (! $this->configured) {
            $this->githubError = __('admin.update_center.not_configured');

            return;
        }

        try {
            $this->releases = (new GitHubReleases(
                $this->repo,
                (string) plugin_setting('update-center', 'github_token', ''),
            ))->releases();
            $this->latestTag = $this->releases[0]['tag'] ?? null;
            $this->latestNotes = $this->releases[0]['notes'] ?? null;
        } catch (\Throwable $e) {
            $this->githubError = $e->getMessage();
        }
    }

    public function hasUpdate(): bool
    {
        return $this->latestTag !== null && $this->latestTag !== $this->currentVersion;
    }

    public function updateTo(?string $tag = null): void
    {
        $target = $tag ?? $this->latestTag;
        if ($target === null) {
            return;
        }
        if ($this->preflightProblems !== []) {
            Notification::make()->title(__('admin.update_center.preflight_blocked'))->danger()->send();

            return;
        }

        RunUpdate::dispatch($target);
        Notification::make()
            ->title(__('admin.update_center.started', ['tag' => $target]))
            ->body(__('admin.update_center.started_hint'))
            ->success()
            ->send();
    }

    public function logTail(): string
    {
        return Updater::readLogTail(50);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('updateNow')
                ->label(__('admin.update_center.update_now', ['tag' => $this->latestTag ?? '']))
                ->color('success')
                ->icon(Heroicon::OutlinedArrowUpCircle)
                ->requiresConfirmation()
                ->modalHeading(__('admin.update_center.confirm_heading'))
                ->modalDescription(__('admin.update_center.confirm_desc'))
                ->action(fn () => $this->updateTo())
                ->visible(fn () => $this->hasUpdate() && $this->preflightProblems === []),
        ];
    }
}
