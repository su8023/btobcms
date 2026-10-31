<?php

namespace App\Services;

use App\Support\ArtisanCli;
use Throwable;

/**
 * APP_URL 健康检查与一键同步（v1.4.19）。
 *
 * 背景：AppServiceProvider 以 APP_URL 的协议强制全站 URL 生成（有意设计，
 * 防 CDN/代理场景下协议被降级伪造、canonical 被搜索引擎收录成两个版本）。
 * 代价是「装完后才在宝塔开 HTTPS」的用户会整站样式丢失——所有链接仍按
 * http:// 生成，浏览器当混合内容拦截 CSS/JS。旧流程只能 SSH 手改 .env
 * + config:cache；本服务让站点自己发现不一致，并在后台一键修复。
 *
 * 检测口径：拿「当前请求的协议+域名+端口」与 APP_URL 对比。协议与域名
 * 都来自服务端事实（未配 TRUSTED_PROXIES 时是 nginx/fastcgi 直传，
 * 配了则取可信代理头），客户端无法伪造，不存在「被诱导改写 .env」的
 * 通道；.env 改写入口另有 admin 角色 + CSRF 收口。
 */
class AppUrlHealth
{
    /**
     * 测试注入：覆盖 .env 路径（默认 base_path('.env')）。
     * 生产代码不要改它。
     */
    public static ?string $envPath = null;

    /**
     * 检测当前访问与 APP_URL 是否一致。
     *
     * @return array{ok: bool, type: 'scheme'|'host'|null, app_url: string, current: string, suggested: string}
     *          type=scheme 协议不一致（致命：混合内容整站断网式坏掉）
     *          type=host   域名/端口不一致（www 与非 www、临时域名残留等）
     */
    public static function inspect(): array
    {
        $appUrl = (string) config('app.url');
        $appScheme = strtolower((string) parse_url($appUrl, PHP_URL_SCHEME));
        $appHost = strtolower((string) parse_url($appUrl, PHP_URL_HOST));
        $appPort = parse_url($appUrl, PHP_URL_PORT);

        $reqScheme = request()->isSecure() ? 'https' : 'http';
        $reqHost = strtolower((string) request()->getHost());
        $httpHost = (string) request()->httpHost();

        $current = $reqHost === '' ? '' : $reqScheme.'://'.$httpHost;
        $suggested = $current;

        $type = null;

        if ($reqHost !== '' && $appHost !== '' && in_array($appScheme, ['http', 'https'], true)) {
            if ($reqScheme !== $appScheme) {
                $type = 'scheme';
            } elseif ($reqHost !== $appHost || self::effectivePort(request()->getPort(), $reqScheme) !== self::effectivePort($appPort, $appScheme)) {
                $type = 'host';
            }
        }

        return [
            'ok' => $type === null,
            'type' => $type,
            'app_url' => $appUrl,
            'current' => $current,
            'suggested' => $suggested,
        ];
    }

    /**
     * 把 .env 的 APP_URL 原子替换为 $newUrl，并刷新配置缓存。
     *
     * 刷新策略（为子进程不可用的受限主机兜底）：
     *   1. 配置已缓存 → 子进程 config:cache（重新 boot 读新 .env，与安装收尾同机制）；
     *   2. 子进程失败 → 删除缓存文件降级为未缓存运行（下一请求自然读新 .env），
     *      返回 degraded 状态，提示稍后可 artisan optimize 恢复缓存。
     * 本进程内同步更新 config 与 URL 强制协议，点击后无需重启即生效。
     *
     * @return array{status: 'ok'|'degraded'|'error', message: string}
     */
    public static function sync(string $newUrl): array
    {
        $newUrl = self::normalize($newUrl);

        if ($newUrl === null) {
            return ['status' => 'error', 'message' => '站点地址不合法：只接受 http(s)://域名[:端口] 形式（不含路径）'];
        }

        $envPath = self::envPath();

        if (! is_file($envPath)) {
            return ['status' => 'error', 'message' => '未找到 .env（'.$envPath.'），无法同步'];
        }

        $contents = file_get_contents($envPath);

        if ($contents === false) {
            return ['status' => 'error', 'message' => '无法读取 .env，请检查文件权限'];
        }

        // dotenv 不可变模式取首个同名值：正则只替换第一行即生效；
        // 不存在则追加（正常安装必然存在，此处防御手工删过的 .env）
        $line = 'APP_URL='.$newUrl;

        if (preg_match('/^[ \t]*APP_URL[ \t]*=.*$/m', $contents) === 1) {
            $updated = (string) preg_replace('/^[ \t]*APP_URL[ \t]*=.*$/m', $line, $contents, 1);
        } else {
            $updated = rtrim($contents, "\r\n")."\n".$line."\n";
        }

        // 原子落盘（与安装向导写 .env 同套路）：中断不会截断原文件
        $tmp = $envPath.'.syncing';

        if (file_put_contents($tmp, $updated) === false) {
            return ['status' => 'error', 'message' => '无法写入 .env 临时文件，请检查目录权限（chmod 775 站点根目录）'];
        }

        if (is_file($envPath)) {
            @unlink($envPath);
        }

        if (! rename($tmp, $envPath)) {
            @unlink($tmp);

            return ['status' => 'error', 'message' => '.env 写入失败（rename 失败），请检查文件权限'];
        }

        // 配置缓存刷新：测试环境不碰 bootstrap/cache（与安装收尾同口径）；
        // 未缓存的站点无需刷新，.env 下一请求自然生效
        $degraded = null;

        if (! app()->runningUnitTests() && app()->configurationIsCached()) {
            try {
                ArtisanCli::run('config:cache');
            } catch (Throwable $e) {
                // 子进程不可用（proc_open 被禁等）：删缓存降级为未缓存运行
                @unlink(base_path('bootstrap/cache/config.php'));
                $degraded = '配置缓存自动刷新失败（'.$e->getMessage().'），站点已按无缓存模式运行新配置；稍后可 SSH 执行 artisan optimize 恢复缓存';
            }
        }

        // 本进程立即生效：不等下一请求
        config(['app.url' => $newUrl]);
        $scheme = (string) parse_url($newUrl, PHP_URL_SCHEME);

        if (in_array($scheme, ['http', 'https'], true)) {
            \Illuminate\Support\Facades\URL::forceScheme($scheme);
        }

        return $degraded === null
            ? ['status' => 'ok', 'message' => '站点地址已同步为 '.$newUrl.'，配置缓存已刷新']
            : ['status' => 'degraded', 'message' => '站点地址已同步为 '.$newUrl.'；'.$degraded];
    }

    /**
     * 校验并归一化目标地址：只接受 scheme://authority（域名/IPv4/[IPv6][:端口]），
     * 拒绝路径、查询、片段、凭据与任何白名单外字符——该值会被写进 .env 并烘进
     * 配置缓存，宁可拒绝也不带入意外内容。
     */
    public static function normalize(string $url): ?string
    {
        $url = trim($url);

        if ($url === '' || strlen($url) > 255) {
            return null;
        }

        if (preg_match('~^(https?)://([^/?#@\s]+)$~i', $url, $m) !== 1) {
            return null;
        }

        $scheme = strtolower($m[1]);
        $authority = $m[2];
        $host = $authority;
        $port = null;

        if (str_starts_with($host, '[')) {
            // IPv6 字面量：[::1] 或 [::1]:8080
            if (preg_match('/^\[([0-9A-Fa-f:]+)\](?::(\d{1,5}))?$/', $host, $m6) !== 1) {
                return null;
            }

            if (filter_var($m6[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                return null;
            }

            $port = $m6[2] ?? null;
        } else {
            if (str_contains($host, ':')) {
                $parts = explode(':', $host);

                if (count($parts) !== 2) {
                    return null;
                }

                [$host, $port] = $parts;
            }

            if ($host === '' || str_contains($host, '[') || str_contains($host, ']')) {
                return null;
            }

            $isIp = filter_var($host, FILTER_VALIDATE_IP) !== false;
            $isDomain = filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;

            if (! $isIp && ! $isDomain) {
                return null;
            }
        }

        if ($port !== null && ((int) $port < 1 || (int) $port > 65535)) {
            return null;
        }

        return $scheme.'://'.$authority;
    }

    public static function envPath(): string
    {
        return static::$envPath ?? base_path('.env');
    }

    /**
     * 端口比较归一化：显式默认端口（http:80 / https:443）视为「无端口」，
     * 否则 APP_URL=http://x 与 http://x:80 会被误报域名不一致。
     */
    private static function effectivePort(int|string|null $port, string $scheme): ?int
    {
        $default = $scheme === 'https' ? 443 : 80;

        if ($port === null || (int) $port === $default) {
            return null;
        }

        return (int) $port;
    }
}
