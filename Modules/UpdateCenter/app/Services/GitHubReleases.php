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
     * @return array<int, array{tag: string, name: string, date: ?string, notes: string, url: string, prerelease: bool}>
     *
     * @throws ConnectionException|\RuntimeException
     */
    public function releases(int $perPage = 20): array
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
