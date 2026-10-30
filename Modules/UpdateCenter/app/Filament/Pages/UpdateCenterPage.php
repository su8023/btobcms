<?php

namespace Modules\UpdateCenter\Filament\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Modules\UpdateCenter\Services\GitHubReleases;
use Modules\UpdateCenter\Services\SelfUpdater;
use Throwable;
use UnitEnum;

/**
 * 更新中心：WordPress 式自更新。
 * 点更新 → 同步执行（下载自包含包 → 维护模式 → 解压覆盖 → 迁移 → 缓存重建 → 恢复访问），
 * 不依赖队列 worker / git / composer；中断后可从断点继续。
 */
class UpdateCenterPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPath;

    protected static ?int $navigationSort = 34;

    protected static ?string $slug = 'update-center';

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
        // 断点中断优先提示「!」；否则有新版本提示
        $updater = SelfUpdater::fromSettings();
        if ($updater->isResumable()) {
            return '!';
        }

        $client = GitHubReleases::fromSettings();
        if ($client === null) {
            return null;
        }
        $latest = $client->latest();
        $current = (string) config('version.version');

        return ($latest !== null && $latest['tag'] !== $current && $latest['tag'] !== '') ? '!' : null;
    }

    // ---- 页面数据 ----

    /** @var list<array{tag: string, name: string, date: ?string, notes: string, prerelease: bool}> */
    public array $releases = [];

    public ?string $latestTag = null;

    public ?string $latestNotes = null;

    public string $currentVersion = '';

    public string $githubError = '';

    /** @var list<string> */
    public array $preflightProblems = [];

    public bool $configured = false;

    public string $repo = '';

    public bool $updating = false;

    public function mount(): void
    {
        $this->configured = GitHubReleases::fromSettings() !== null;
        $this->repo = (string) plugin_setting('update-center', 'github_repo', '');

        // 当前版本以 config/version.php 为准（每次发版随更新包自带）
        $this->currentVersion = (string) config('version.version');

        $this->preflightProblems = SelfUpdater::preflight();

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
        } catch (Throwable $e) {
            $this->githubError = $e->getMessage();
        }
    }

    public function hasUpdate(): bool
    {
        return $this->latestTag !== null && $this->latestTag !== $this->currentVersion;
    }

    public function resumable(): bool
    {
        return SelfUpdater::fromSettings()->isResumable();
    }

    public function interruptedTag(): ?string
    {
        return SelfUpdater::fromSettings()->state()['tag'] ?? null;
    }

    public function interruptedStep(): ?string
    {
        return SelfUpdater::fromSettings()->state()['step'] ?? null;
    }

    /**
     * 一键更新：点击后派发「脱离的 CLI 子进程」执行全流程
     * （下载 → 维护 → 覆盖 → 迁移 → 缓存 → 恢复），HTTP 请求立即返回。
     *
     * 为什么派发子进程而非同步执行：同步跑在 HTTP 请求里会被 nginx
     * fastcgi 超时 / FPM request_terminate_timeout 杀掉（线上实测）；
     * 子进程是独立 OS 进程，父请求死亡不影响它，且天然规避超时。
     * 进度通过状态文件 + 日志轮询（页面 5s wire:poll）实时可见。
     */
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

        // 必须用 fromSettings()：容器解析标量参数（repo/token）会退回默认值，
        // 导致「未配置仓库」——这些值只能从插件设置注入。
        $updater = SelfUpdater::fromSettings();
        if (! $updater->acquireLock()) {
            Notification::make()->title('已有更新任务在执行（可能是另一个管理员刚点过）')->danger()->send();

            return;
        }

        $this->updating = true;

        try {
            // 以独立进程派发：proc_open 子进程在 FPM 杀掉本请求后继续运行
            $php = PHP_OS_FAMILY === 'Windows' ? PHP_BINDIR.'\\php.exe' : PHP_BINDIR.'/php';
            $descriptors = [1 => ['file', storage_path('app/update-apply.log'), 'a'], 2 => ['file', storage_path('app/update-apply.log'), 'a']];
            $process = @proc_open(
                escapeshellarg($php).' '.escapeshellarg(base_path('artisan')).' update:apply '.escapeshellarg($target),
                $descriptors,
                $pipes,
                base_path(),
                null,
                ['bypass_shell' => true],
            );

            if (! is_resource($process)) {
                throw new RuntimeException('无法派发更新进程（proc_open 不可用？检查禁用函数）');
            }
            proc_close($process); // 只等派发动作本身（瞬间），不等更新跑完

            Notification::make()
                ->title('更新已启动：目标 '.$target)
                ->body('后台进程正在执行下载 → 覆盖 → 迁移 → 缓存重建，通常 2~4 分钟。本页每 5 秒刷新进度，完成后刷新页面即可看到新版本。')
                ->info()
                ->persistent()
                ->send();
        } catch (Throwable $e) {
            Notification::make()
                ->title('无法启动更新进程')
                ->body(mb_substr($e->getMessage(), 0, 300))
                ->danger()
                ->persistent()
                ->send();
        } finally {
            $this->updating = false;
        }
    }

    /** 断点恢复 */
    public function resumeUpdate(): void
    {
        $updater = SelfUpdater::fromSettings();

        if (! $updater->acquireLock()) {
            Notification::make()->title('已有更新任务在执行，请稍候')->danger()->send();

            return;
        }

        @set_time_limit(0);

        try {
            $updater->resume();
            Notification::make()
                ->title('更新已完成')
                ->success()
                ->send();
        } catch (Throwable $e) {
            Notification::make()
                ->title('恢复仍失败：'.$e->getMessage())
                ->body('站点仍在维护模式。请联系技术人员查看 storage/logs/laravel.log。')
                ->danger()
                ->persistent()
                ->send();
            report($e);
        } finally {
            $updater->releaseLock();
        }

        $this->currentVersion = (string) config('version.version');
    }

    /** 放弃中断的更新：先回滚已覆盖的文件，再恢复访客访问 */
    public function abortInterrupted(): void
    {
        $updater = SelfUpdater::fromSettings();

        try {
            $updater->rollback();
        } catch (Throwable $e) {
            Notification::make()
                ->title('回滚失败，站点可能仍处于半更新状态')
                ->body($e->getMessage())
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        $updater->abandon();

        try {
            \Illuminate\Support\Facades\Artisan::call('up');
        } catch (Throwable) {
        }
        Notification::make()
            ->title('已回滚并恢复访问（本次更新已放弃，可稍后重新发起）')
            ->warning()
            ->send();
    }

    /**
     * 从本地上传的安装包更新（不依赖 GitHub API）。
     */
    public function updateFromLocalPackage(array $data): void
    {
        $tag = (string) ($data['tag'] ?? '');
        // FileUpload 的值为路径数组（可多选），取第一个
        $uploaded = $data['package'] ?? [];
        $stored = is_array($uploaded) ? (string) ($uploaded[0] ?? '') : (string) $uploaded;

        if ($stored === '' || ! is_file(storage_path($stored))) {
            Notification::make()->title('请选择安装包文件')->danger()->send();

            return;
        }

        $updater = SelfUpdater::fromSettings();
        if (! $updater->acquireLock()) {
            Notification::make()->title('已有更新任务在执行，请稍候')->danger()->send();

            return;
        }

        $this->updating = true;
        @set_time_limit(0);

        try {
            $updater->installFromLocalPackage(storage_path($stored), $tag);
            Notification::make()
                ->title('更新成功：已升级到 '.$tag)
                ->body('已用本地上传的安装包完成覆盖、迁移与缓存重建。建议刷新页面检查前台。')
                ->success()
                ->persistent()
                ->send();
        } catch (Throwable $e) {
            Notification::make()
                ->title('更新中断：'.$e->getMessage())
                ->body('站点可能处于维护模式。点顶部「继续更新」从断点恢复。')
                ->danger()
                ->persistent()
                ->send();
            report($e);
        } finally {
            $this->updating = false;
            $updater->releaseLock();
        }

        $this->currentVersion = (string) config('version.version');
    }

    public function logTail(): string
    {
        return SelfUpdater::readLogTail(50);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('localPackage')
                ->label('本地上传安装包')
                ->color('gray')
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->modalHeading('从本地上传安装包更新')
                ->modalDescription('下载站点的 btobcms-{版本}.zip 安装包后在此上传即可更新——不需要 GitHub 可达，适合网络受限或私有仓库场景。')
                ->form([
                    \Filament\Forms\Components\TextInput::make('tag')
                        ->label('目标版本号')
                        ->default($this->latestTag ?? '')
                        ->required()
                        ->maxLength(40),
                    \Filament\Forms\Components\FileUpload::make('package')
                        ->label('安装包（zip）')
                        ->disk('local')
                        ->directory('update-uploads')
                        ->acceptedFileTypes(['application/zip', 'application/x-zip-compressed'])
                        ->maxSize(204800)
                        ->required(),
                ])
                ->action(fn (array $data) => $this->updateFromLocalPackage($data)),

            Action::make('resumeUpdate')
                ->label('继续更新（'.$this->interruptedTag().' 断在第 '.$this->interruptedStep().' 步）')
                ->color('warning')
                ->icon(Heroicon::OutlinedPlayCircle)
                ->requiresConfirmation()
                ->modalHeading('继续上次中断的更新')
                ->modalDescription('从断点继续执行剩余步骤（幂等：已完成的文件覆盖与迁移不会重复造成问题）。完成后自动恢复访问。')
                ->action(fn () => $this->resumeUpdate())
                ->visible(fn () => $this->resumable()),

            Action::make('abortInterrupted')
                ->label('恢复访问并放弃')
                ->color('gray')
                ->icon(Heroicon::OutlinedXCircle)
                ->action(fn () => $this->abortInterrupted())
                ->visible(fn () => $this->resumable()),

            Action::make('updateNow')
                ->label(__('admin.update_center.update_now', ['tag' => $this->latestTag ?? '']))
                ->color('success')
                ->icon(Heroicon::OutlinedArrowUpCircle)
                ->requiresConfirmation()
                ->modalHeading(__('admin.update_center.confirm_heading'))
                ->modalDescription(__('admin.update_center.confirm_desc').' 全程无需服务器命令行；若中途出问题会停在维护模式并可一键从断点继续。')
                ->action(fn () => $this->updateTo())
                ->visible(fn () => $this->hasUpdate() && $this->preflightProblems === []),
        ];
    }
}
