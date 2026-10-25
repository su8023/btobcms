<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * 插件可用性自检：逐插件检查启用状态、配置完整性与（可选）连通性。
 * 输出 ✓ 可用 / ⚠ 需配置 / ✗ 异常 三级结果，可在插件中心一键运行。
 */
class PluginVerifyCommand extends Command
{
    protected $signature = 'plugins:verify
        {--deep : 连通性检查（真实请求 sitemap 等，默认只查配置）}';

    protected $description = 'Verify all installed plugins: enabled state, configuration completeness and connectivity';

    public function handle(): int
    {
        $rows = [];
        $counts = ['ok' => 0, 'warn' => 0, 'fail' => 0];

        $check = function (string $plugin, string $item, string $status, string $detail = '') use (&$rows, &$counts) {
            $icon = match ($status) {
                'ok' => '✓',
                'warn' => '⚠',
                default => '✗',
            };
            $rows[] = [$icon, $plugin, $item, $status === 'ok' ? '<fg=green>OK</>' : ($status === 'warn' ? '<fg=yellow>WARN</>' : '<fg=red>FAIL</>'), $detail];
            $counts[$status]++;
        };

        // ---------- feishu-notifier ----------
        $p = 'feishu-notifier';
        $webhook = (string) plugin_setting($p, 'webhook', '');
        if (plugin_setting($p, 'enabled', true)) {
            if ($webhook !== '') {
                $host = parse_url($webhook, PHP_URL_HOST);
                $check('FeishuNotifier', 'Webhook 已配置', 'ok', (string) $host);
                if (! str_starts_with($webhook, 'https://')) {
                    $check('FeishuNotifier', 'Webhook 使用 HTTPS', 'warn', '飞书要求 HTTPS');
                }
            } else {
                $check('FeishuNotifier', 'Webhook 已配置', 'warn', '未配置 — 插件中心设置面板或 FEISHU_WEBHOOK_URL');
            }
        } else {
            $check('FeishuNotifier', '插件状态', 'warn', '已停用（插件中心开关）');
        }

        // ---------- mail-notifier ----------
        $p = 'mail-notifier';
        $rawEmails = plugin_setting($p, 'admin_emails', '');
        $emails = is_array($rawEmails) ? implode(', ', $rawEmails) : (string) $rawEmails;
        if (plugin_setting($p, 'enabled', true)) {
            if ($emails !== '') {
                $check('MailNotifier', '管理员邮箱已配置', 'ok', $emails);
            } else {
                $check('MailNotifier', '管理员邮箱已配置', 'warn', '未配置 — 设置面板或 INQUIRY_ADMIN_EMAILS');
            }
            $check('MailNotifier', '邮件驱动', 'ok', 'mailer='.config('mail.default').(config('mail.default') === 'log' ? '（本地开发写日志）' : ''));
        } else {
            $check('MailNotifier', '插件状态', 'warn', '已停用');
        }

        // ---------- whatsapp-chat ----------
        $p = 'whatsapp-chat';
        if (plugin_setting($p, 'enabled', true)) {
            $phone = (string) plugin_setting($p, 'phone', '');
            if ($phone !== '' && ctype_digit($phone)) {
                $check('WhatsAppChat', '号码已配置', 'ok', 'wa.me/'.$phone);
            } elseif ($phone !== '') {
                $check('WhatsAppChat', '号码格式', 'fail', '必须为纯数字（含国家码），当前: '.$phone);
            } else {
                $check('WhatsAppChat', '号码已配置', 'warn', '未配置 — 挂件不会显示');
            }
        } else {
            $check('WhatsAppChat', '插件状态', 'warn', '已停用');
        }

        // ---------- ai-seo-auditor ----------
        $p = 'ai-seo-auditor';
        if (plugin_setting($p, 'enabled', true)) {
            $base = (string) (plugin_setting($p, 'base_url') ?: config('app.url'));
            $hour = plugin_setting($p, 'schedule_hour', 6);
            $check('AiSeoAuditor', '每日调度', 'ok', sprintf('每天 %02d:00（scheduler 运行时自动执行）', $hour));

            if ($this->option('deep')) {
                try {
                    $resp = Http::timeout(8)->get(rtrim($base, '/').'/sitemap.xml');
                    if ($resp->successful() && str_contains((string) $resp->body(), '<urlset')) {
                        $count = substr_count((string) $resp->body(), '<loc>');
                        $check('AiSeoAuditor', 'sitemap 连通', 'ok', rtrim($base, '/')."/sitemap.xml（{$count} URLs）");
                    } else {
                        $check('AiSeoAuditor', 'sitemap 连通', 'fail', 'HTTP '.$resp->status());
                    }
                } catch (\Throwable $e) {
                    $check('AiSeoAuditor', 'sitemap 连通', 'fail', mb_substr($e->getMessage(), 0, 80));
                }
            } else {
                $check('AiSeoAuditor', '巡检目标', 'ok', $base.'（加 --deep 实测连通）');
            }
        } else {
            $check('AiSeoAuditor', '插件状态', 'warn', '已停用');
        }

        // ---------- ai-content-writer ----------
        $p = 'ai-content-writer';
        $tier = config('ai.tiers.chat', 'deepseek');
        $key = config("ai.providers.{$tier}.key");
        $model = config("ai.providers.{$tier}.model", '?');
        if (filled($key)) {
            $check('AiContentWriter', 'LLM Provider', 'ok', "{$tier} / {$model}");
        } else {
            $check('AiContentWriter', 'LLM Provider', 'warn', "未配置 API Key — .env 填 DEEPSEEK_API_KEY（或 AI_CHAT_PROVIDER 指向其他 provider）");
        }
        $check('AiContentWriter', '运行方式', 'ok', '手动命令 ai:article（批量选题源待建，未挂调度）');

        // ---------- 输出 ----------
        $this->table(['', '插件', '检查项', '结果', '说明'], $rows);
        $this->line(sprintf(
            '插件可用性: <fg=green>%d 可用</> / <fg=yellow>%d 需配置</> / <fg=red>%d 异常</>',
            $counts['ok'], $counts['warn'], $counts['fail'],
        ));

        return $counts['fail'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
