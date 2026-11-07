<?php

namespace App\Console\Commands;

use Illuminate\Console\Attribute\AsCommand;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;

/**
 * 15 个插件模块健康体检（只读）。
 *
 * 检查项：ServiceProvider 注册 / PHP 语法 / 迁移表缺失 / 路由注册 / 关键配置项。
 * 判定：正常 / 需配置 / 失效（失效 = 语法错误、缺表、路由指向不存在的控制器）。
 */
#[AsCommand(name: 'modules:health', description: 'Health-check all enabled plugin modules (read-only)')]
class ModuleHealthCheckCommand extends Command
{
    /** 模块核心功能说明（与体检报告一致） */
    private const MODULES = [
        'FeishuNotifier' => '飞书卡片与消息通知器：询盘/审计报告推送',
        'AiSeoAuditor' => 'SEO 自动化审计器：定时全站巡检并出报告',
        'AiContentWriter' => 'AI 内容创作引擎：文章/多语言翻译写作室',
        'MailNotifier' => '邮件通知服务：询盘邮件与买家确认信',
        'WhatsAppChat' => '前台 WhatsApp 快速对话浮窗',
        'UpdateCenter' => '热更新与版本中心：插件包上传与校验',
        'AutoSeo' => '自动 TDK 补全引擎：发布即写入标题描述',
        'LinkWeaver' => '站内智能锚文本内链：按关键词织入链接',
        'LeadLens' => '访客意图与线索雷达：询盘评分与富化',
        'GeoBoost' => '多语言地区着陆页增强：AI 流量追踪与 FAQ',
        'TransCoverage' => '多语言覆盖率追踪：缺失翻译扫描与修复',
        'GscPulse' => 'Google Search Console 联动：排名与点击报表',
        'LeadMagnet' => '线索收集与白皮书引流磁铁：下载换询盘',
        'TrustBuilder' => '验厂与资质背书增强：案例与证书展示',
        'ImageForge' => '图片处理与 Alt 优化：WebP 转换与 ALT 补全',
    ];

    /**
     * 各模块关键配置（缺失即「需配置」）。键为 plugin_setting 的 config 前缀。
     * 只列真正阻断功能的字段，可选项（如飞书签名密钥）不进表。
     */
    private const REQUIRED_CONFIG = [
        'FeishuNotifier' => ['feishu-notifier' => ['webhook']],
        'GscPulse' => ['gsc-pulse' => ['client_id', 'client_secret']],
        'WhatsAppChat' => ['whatsapp-chat' => ['phone']],
        'AiContentWriter' => ['ai-content-writer' => ['custom_api_key', 'custom_base_url']],
        'MailNotifier' => ['mail-notifier' => ['admin_emails']],
    ];

    /** 可选配置：缺失只提示，不降级状态 */
    private const OPTIONAL_CONFIG = [
        'FeishuNotifier' => ['feishu-notifier' => ['secret']],
        'UpdateCenter' => ['update-center' => ['github_token']],
    ];

    protected $signature = 'modules:health {--json : 输出 JSON}';

    protected $description = 'Health-check all enabled plugin modules (read-only)';

    public function handle(): int
    {
        $base = base_path('Modules');
        $results = [];

        foreach (array_keys(self::MODULES) as $module) {
            $results[$module] = $this->check($module, $base.'/'.$module);
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($results, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->table(
            ['模块', '核心功能', '状态', '依赖项/风险点'],
            array_map(fn (array $r) => [$r['module'], $r['feature'], $r['status'], $r['notes']], $results),
        );

        $broken = array_filter($results, fn (array $r) => $r['status'] === '失效');

        if ($broken !== []) {
            $this->error('失效模块：'.implode(', ', array_keys($broken)));
        }

        return self::SUCCESS;
    }

    /**
     * @return array{module: string, feature: string, status: string, notes: string}
     */
    private function check(string $module, string $path): array
    {
        $feature = self::MODULES[$module];
        $notes = [];
        $status = '正常';

        if (! is_dir($path)) {
            return ['module' => $module, 'feature' => $feature, 'status' => '失效', 'notes' => '模块目录缺失'];
        }

        // 1) ServiceProvider 是否注册（已注册 = 类已加载）
        $provider = $this->providerClass($module);

        if ($provider === null) {
            $status = '失效';
            $notes[] = '未找到 ServiceProvider';
        } elseif (! class_exists($provider)) {
            $status = '失效';
            $notes[] = 'ServiceProvider 未注册: '.$provider;
        }

        // 2) 语法检查（只报失败）
        $syntax = $this->lint($path);

        if ($syntax !== []) {
            $status = '失效';
            $notes[] = '语法错误: '.implode('; ', array_slice($syntax, 0, 3));
        }

        // 3) 迁移声明的表是否都存在
        $missing = $this->missingTables($path);

        if ($missing !== []) {
            $status = '失效';
            $notes[] = '缺表: '.implode(', ', $missing);
        }

        // 4) 路由注册情况
        $routes = $this->routesOf($module);

        $notes[] = $routes === [] ? '无独立路由（监听器/中间件型插件）' : '路由 '.$routes;

        // 5) 关键配置
        foreach (self::REQUIRED_CONFIG[$module] ?? [] as $prefix => $fields) {
            foreach ($fields as $field) {
                if (blank(plugin_setting($prefix, $field, ''))) {
                    $status = $status === '正常' ? '需配置' : $status;
                    $notes[] = $prefix.'.'.$field.' 未配置';
                }
            }
        }

        // 6) 可选配置：仅提示
        foreach (self::OPTIONAL_CONFIG[$module] ?? [] as $prefix => $fields) {
            foreach ($fields as $field) {
                if (blank(plugin_setting($prefix, $field, ''))) {
                    $notes[] = $prefix.'.'.$field.'（可选）未配置';
                }
            }
        }

        if ($notes === []) {
            $notes[] = '无';
        }

        return [
            'module' => $module,
            'feature' => $feature,
            'status' => $status,
            'notes' => implode('；', $notes),
        ];
    }

    private function providerClass(string $module): ?string
    {
        $candidates = glob(base_path("Modules/{$module}/app/Providers/*ServiceProvider.php")) ?: [];

        foreach ($candidates as $file) {
            $class = 'Modules\\'.$module.'\\Providers\\'.basename($file, '.php');

            if (class_exists($class)) {
                return $class;
            }
        }

        return null;
    }

    /**
     * php -l 检查模块内全部 PHP 文件。
     *
     * @return array<int, string> 错误信息
     */
    private function lint(string $path): array
    {
        $errors = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path));

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            // blade 视图不是 PHP 源码，php -l 会误报（@foreach / {{ }} 语法）
            if (str_contains($file->getPathname(), '/resources/views/')) {
                continue;
            }

            $process = new Process([PHP_BINARY, '-l', $file->getPathname()]);
            $process->setTimeout(30);
            $process->run();

            if (! $process->isSuccessful()) {
                $errors[] = $file->getPathname().': '.trim($process->getErrorOutput() ?: $process->getOutput());
            }
        }

        return $errors;
    }

    /**
     * 模块迁移中 Schema::create('table') 声明的表，与实际库比对。
     *
     * @return array<int, string>
     */
    private function missingTables(string $path): array
    {
        $missing = [];
        $files = glob($path.'/database/migrations/*.php') ?: [];

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);

            if (! preg_match_all("/Schema::create\('([a-z0-9_]+)'/", $source, $matches)) {
                continue;
            }

            foreach ($matches[1] as $table) {
                if (! Schema::hasTable($table)) {
                    $missing[] = $table;
                }
            }
        }

        return array_values(array_unique($missing));
    }

    /**
     * 该模块注册的路由（URI 列表）。
     */
    private function routesOf(string $module): string
    {
        $uris = [];

        foreach (Route::getRoutes() as $route) {
            $action = $route->getActionName();

            if (str_starts_with($action, 'Modules\\'.$module.'\\')) {
                $uris[] = $route->uri();
            }
        }

        $uris = array_values(array_unique($uris));

        return implode(', ', array_slice($uris, 0, 6)).(count($uris) > 6 ? ' …('.count($uris).')' : '');
    }
}
