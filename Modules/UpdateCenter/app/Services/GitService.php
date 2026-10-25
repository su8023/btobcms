<?php

namespace Modules\UpdateCenter\Services;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Git 薄封装：所有命令在项目根执行，统一超时与错误包装。
 */
class GitService
{
    public function __construct(
        private readonly string $git = 'git',
        private readonly string $cwd = '',
    ) {
        $this->cwd = $cwd !== '' ? $cwd : base_path();
    }

    public static function fromSettings(): self
    {
        return new self((string) (plugin_setting('update-center', 'git_path', '') ?: config('update-center.git_path')));
    }

    /**
     * @return array{exit: int, out: string, err: string}
     */
    public function run(array $command, int $timeout = 300): array
    {
        $result = Process::path($this->cwd)
            ->timeout($timeout)
            ->run(array_merge([$this->git], $command));

        return [
            'exit' => $result->exitCode(),
            'out' => trim($result->output()),
            'err' => trim($result->errorOutput()),
        ];
    }

    public function isAvailable(): bool
    {
        return $this->run(['--version'], 15)['exit'] === 0;
    }

    public function isRepository(): bool
    {
        return is_dir($this->cwd.'/.git');
    }

    public function hasRemote(): bool
    {
        return $this->run(['remote'], 15)['exit'] === 0 && $this->run(['remote'], 15)['out'] !== '';
    }

    /**
     * 当前 HEAD 指向的最近 tag（含本地未 push 提交时带 -dirty 后缀的精确描述）。
     */
    public function currentTag(): ?string
    {
        $r = $this->run(['describe', '--tags', '--exact-match', 'HEAD'], 15);
        if ($r['exit'] === 0) {
            return $r['out'];
        }

        return null;
    }

    public function currentCommit(): ?string
    {
        $r = $this->run(['rev-parse', '--short', 'HEAD'], 15);

        return $r['exit'] === 0 ? $r['out'] : null;
    }

    public function isDirty(): bool
    {
        return $this->run(['status', '--porcelain'], 30)['out'] !== '';
    }

    public function fetch(): void
    {
        $r = $this->run(['fetch', '--tags', '--force', 'origin'], 600);
        if ($r['exit'] !== 0) {
            throw new RuntimeException('git fetch 失败：'.$r['err'] ?: $r['out']);
        }
    }

    public function tagExists(string $tag): bool
    {
        return $this->run(['rev-parse', '-q', '--verify', "refs/tags/{$tag}"], 15)['exit'] === 0;
    }

    public function checkout(string $ref): void
    {
        $r = $this->run(['checkout', '-f', $ref], 120);
        if ($r['exit'] !== 0) {
            throw new RuntimeException("git checkout {$ref} 失败：".($r['err'] ?: $r['out']));
        }
    }
}
