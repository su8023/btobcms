<?php

namespace App\Console\Commands;

use App\Services\PluginUploader;
use Illuminate\Console\Command;

/**
 * 插件上传安装命令：解压插件 zip 到 Modules/、注册 PSR-4 映射并启用。
 * 用法：php artisan plugins:upload /path/to/Plugin.zip [--skip-autoload]
 */
class PluginUploadCommand extends Command
{
    protected $signature = 'plugins:upload
        {zip : 插件 zip 包路径（绝对路径，或相对于当前工作目录）}
        {--skip-autoload : 跳过 composer dump-autoload（由调用方稍后统一刷新）}';

    protected $description = 'Upload and install a plugin zip package into Modules/ (PSR-4 mapping + enable)';

    public function handle(PluginUploader $uploader): int
    {
        $zipPath = $this->resolveZipPath((string) $this->argument('zip'));

        $this->info('开始安装插件：'.$zipPath);

        $result = $uploader->upload($zipPath, (bool) $this->option('skip-autoload'));

        foreach ($result['steps'] ?? [] as $step) {
            $this->line('  - '.$step);
        }

        if (! ($result['ok'] ?? false)) {
            $this->newLine();
            $this->error('安装失败：'.($result['error'] ?? '未知错误'));

            return self::FAILURE;
        }

        $this->newLine();
        $this->info(sprintf(
            '插件 %s 安装成功（类别：%s，版本：%s）',
            $result['name'] ?? '—',
            $result['category'] ?? 'general',
            $result['version'] ?? '—',
        ));
        $this->warn('提示：插件将在下一次页面加载时生效。');

        return self::SUCCESS;
    }

    /**
     * 相对路径按当前工作目录解析；Windows 盘符 / UNC / Unix 绝对路径原样返回。
     */
    private function resolveZipPath(string $path): string
    {
        if (preg_match('#^(?:[A-Za-z]:[\\\\/]|\\\\\\\\|/)#', $path) === 1) {
            return $path;
        }

        return getcwd().DIRECTORY_SEPARATOR.ltrim($path, '\\/');
    }
}
