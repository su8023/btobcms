<?php

namespace Modules\UpdateCenter\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * GitHub Releases 客户端：拉取版本列表与变更说明。
 * 公开仓库匿名可用（60 次/小时限流）；私有仓库配 token。
 */
class GitHubReleases
{
    public function __construct(
        private readonly string $repo,
        private readonly string $token = '',
    ) {
    }

    public static function fromSettings(): ?self
    {
        $repo = (string) plugin_setting('update-center', 'github_repo', '');
        if (! preg_match('#^[\w.-]+/[\w.-]+$#', $repo)) {
            return null;
        }

        return new self($repo, (string) plugin_setting('update-center', 'github_token', ''));
    }

    /**
     * @param  bool  $refreshIfUpToDate  缓存显示「已是最新」时按 5 分钟节流强制回源，
     *                                     消除「刚发版后台 30 分钟看不到新版本」的盲区
     * @return array<int, array{tag: string, name: string, date: ?string, notes: string, url: string, prerelease: bool}>
     *
     * @throws ConnectionException|\RuntimeException
     */
    public function releases(int $perPage = 20, bool $refreshIfUpToDate = false): array
    {
        $key = $this->cacheKey();

        // cache()->add 原子占位：同一节流窗口内只回源一次，避免并发打爆匿名限流
        $cached = $refreshIfUpToDate ? cache()->get($key) : null;

        if (is_array($cached)
            && ($cached[0]['tag'] ?? '') === (string) config('version.version')
            && cache()->add($key.'.checked', true, now()->addMinutes(5))
        ) {
            cache()->forget($key);
        }

        // 结果缓存 30 分钟：导航徽章每次后台加载都会读取，
        // 匿名限流 60 次/小时会很快耗尽并导致更新中心报错
        return cache()->remember(
            $key,
            now()->addMinutes(30),
            fn () => $this->fetchReleases($perPage),
        );
    }

    /** 立即清掉 releases 缓存（「检查更新」按钮用） */
    public function refresh(): void
    {
        cache()->forget($this->cacheKey());
    }

    private function cacheKey(): string
    {
        return 'update-center.releases.'.md5($this->repo.'|'.$this->token);
    }

    /**
     * @return array<int, array{tag: string, name: string, date: ?string, notes: string, url: string, prerelease: bool}>
     *
     * @throws ConnectionException|\RuntimeException
     */
    protected function fetchReleases(int $perPage): array
    {
        $response = Http::timeout(10)
            ->withHeaders(array_filter([
                'Authorization' => $this->token !== '' ? 'Bearer '.$this->token : null,
                'Accept' => 'application/vnd.github+json',
            ]))
            ->get(rtrim(config('update-center.github_api'), '/')."/repos/{$this->repo}/releases", [
                'per_page' => $perPage,
            ]);

        if ($response->status() === 404) {
            throw new \RuntimeException("仓库不存在或无权访问：{$this->repo}");
        }
        if ($response->status() === 401) {
            throw new \RuntimeException('GitHub Token 无效或已过期');
        }
        if ($response->status() === 403) {
            throw new \RuntimeException('GitHub API 限流（匿名 60 次/小时），稍后再试或配置 Token');
        }
        if (! $response->successful()) {
            throw new \RuntimeException('GitHub API 异常：HTTP '.$response->status());
        }

        return collect($response->json())
            ->map(fn (array $r) => [
                'tag' => (string) ($r['tag_name'] ?? ''),
                'name' => (string) ($r['name'] ?? $r['tag_name'] ?? ''),
                'date' => $r['published_at'] ?? null,
                'notes' => (string) ($r['body'] ?? ''),
                'url' => (string) ($r['html_url'] ?? ''),
                'prerelease' => (bool) ($r['prerelease'] ?? false),
            ])
            ->filter(fn (array $r) => $r['tag'] !== '')
            ->values()
            ->all();
    }

    /**
     * 最新正式版（跳过 prerelease）。
     */
    public function latest(): ?array
    {
        try {
            foreach ($this->releases() as $release) {
                if (! $release['prerelease']) {
                    return $release;
                }
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }
}
