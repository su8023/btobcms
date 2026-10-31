<?php

namespace App\Support;

use RuntimeException;
use Throwable;

/**
 * 以独立 PHP 子进程执行 artisan 命令。
 *
 * 为什么必须子进程：config:cache 等命令会把「启动时加载的配置」烘进
 * bootstrap/cache/config.php。HTTP 请求进程加载的可能是旧 .env（安装前/
 * 同步前），在进程内执行 Artisan::call('config:cache') 会把旧值永久固化，
 * 站点反而坏掉；子进程重新 boot 应用，读到的才是刚落盘的新 .env。
 *
 * 使用方：InstallController（安装收尾缓存）与 AppUrlHealth（APP_URL 一键同步）。
 * 与 Modules/UpdateCenter/SelfUpdater::artisan() 保持同构。
 */
class ArtisanCli
{
    /**
     * 运行 artisan 命令并返回输出；失败抛异常。
     *
     * PHP_BINDIR 优先（宝塔 FPM 下 PHP_BINARY 可能是 php-fpm，收到 artisan 参数
     * 会以 usage 码 64 退出），失败换下一个候选；Windows 加 bypass_shell。
     */
    public static function run(string $command): string
    {
        $cli = PHP_OS_FAMILY === 'Windows' ? PHP_BINDIR.'\\php.exe' : PHP_BINDIR.'/php';
        $candidates = array_values(array_unique([$cli, PHP_BINARY]));

        // 净化继承环境：剥掉所有 Laravel 相关键。
        // 若主机在安装前就有一份带空 APP_KEY 的 .env 残片，dotenv 部分适配器会把
        // 旧值 putenv 进本进程；子进程一旦继承这些键（immutable 模式优先读继承值），
        // config:cache 就会把安装前的旧配置烘进缓存。子进程必须只依赖新落盘的 .env。
        $env = [];
        foreach (getenv() as $key => $value) {
            if (! preg_match('/^(APP_|DB_|SESSION_|CACHE_|QUEUE_|MAIL_|TRUSTED_|REDIS_|FILESYSTEM_|AWS_|LARAVEL_)/', $key)) {
                $env[$key] = $value;
            }
        }

        $lastError = '';

        foreach ($candidates as $php) {
            $cmd = escapeshellarg($php).' '.escapeshellarg(base_path('artisan')).' '.$command;

            $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $process = @proc_open($cmd, $descriptors, $pipes, base_path(), $env, ['bypass_shell' => true]);

            if (! is_resource($process)) {
                $lastError = $php.' 无法启动（proc_open 失败）';

                continue;
            }

            $stdout = (string) stream_get_contents($pipes[1]);
            $stderr = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($process);

            // 64 = CLI usage 错误（php-fpm 收到未知参数的退出码）；artisan 不会用 64
            if ($exit === 64) {
                $lastError = $php.' 不是 CLI 解释器（疑似 php-fpm，exit 64）';

                continue;
            }

            if ($exit !== 0) {
                throw new RuntimeException(sprintf(
                    '%s 失败（exit %d）：%s',
                    $command,
                    $exit,
                    mb_substr(trim($stdout."\n".$stderr), 0, 400)
                ));
            }

            return trim($stdout."\n".$stderr);
        }

        throw new RuntimeException('无法启动 artisan '.$command.'：'.$lastError);
    }
}
