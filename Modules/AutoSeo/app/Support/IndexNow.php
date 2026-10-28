<?php

namespace Modules\AutoSeo\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use RuntimeException;

/**
 * IndexNow 客户端（微软 Bing / Yandex / Seznam 共享协议）。
 * 提交一个 URL 即通知整个 IndexNow 网络即时抓取，免 GSC 手动提交。
 *
 * 密钥首次使用时自动生成：32 位 hex，存放 storage/app/indexnow.key，
 * 并暴露于 https://站点/{key}.txt 供搜索引擎验证（路由见 ServiceProvider）。
 */
class IndexNow
{
    /**
     * 获取（必要时生成）站点密钥。
     * 并发首生成安全：LOCK_EX 串行写入；写后回读为准——并发下本进程生成的
     * key 可能被另一 worker 覆盖，推送必须用与 /{key}.txt 落盘一致的值，
     * 否则所有权验证静默失败。
     */
    public static function key(): string
    {
        $file = (string) config('autoseo.key_file');

        if (is_file($file)) {
            $existing = trim((string) file_get_contents($file));
            if ($existing !== '') {
                return $existing;
            }
        }

        @file_put_contents($file, bin2hex(random_bytes(16)), LOCK_EX);

        $key = trim((string) @file_get_contents($file));
        if ($key === '') {
            throw new RuntimeException("IndexNow 密钥文件不可写：{$file}（检查 storage/app 权限）");
        }

        return $key;
    }

    /**
     * 密钥验证文件内容（搜索引擎会抓 /{key}.txt 确认站点所有权）。
     */
    public static function keyFileContent(): string
    {
        return self::key();
    }

    /**
     * 推送 URL 列表（空列表直接跳过）。
     *
     * @param  list<string>  $urls
     *
     * @throws RuntimeException 网络失败时抛出（调用方决定是否忽略）
     */
    public static function submit(array $urls): void
    {
        $urls = array_values(array_filter(array_map('trim', $urls)));
        if ($urls === []) {
            return;
        }

        $key = self::key();
        $host = parse_url($urls[0], PHP_URL_HOST) ?: (string) request()->getHost();

        $response = Http::timeout(15)->post((string) config('autoseo.indexnow_endpoint'), [
            'host' => $host,
            'key' => $key,
            'urlList' => array_slice($urls, 0, 100), // 协议单次上限
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('IndexNow 推送失败：HTTP '.$response->status().' '.mb_substr($response->body(), 0, 120));
        }
    }

    /**
     * 便捷方法：推送单篇文章的所有语言变体。
     */
    public static function submitPostUrls(string $path): void
    {
        $urls = [];
        foreach (site_locales() as $locale) {
            $prefix = $locale === config('site.default_locale') ? '' : '/'.$locale;
            $urls[] = rtrim(URL::to('/'), '/').$prefix.$path;
        }

        self::submit($urls);
    }
}
