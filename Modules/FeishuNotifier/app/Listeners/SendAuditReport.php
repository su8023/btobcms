<?php

namespace Modules\FeishuNotifier\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\AiSeoAuditor\Events\SeoAuditFinished;
use Modules\FeishuNotifier\Support\FeishuClient;

class SendAuditReport implements ShouldQueue
{
    public function handle(SeoAuditFinished $event): void
    {
        // 设置面板开关：巡检报告推送可独立关闭
        if (! plugin_setting('feishu-notifier', 'notify_audit_report', true)) {
            return;
        }

        $lines = [
            '**站点：'.$event->baseUrl.'**',
            '- 检查页面：'.$event->pagesChecked,
            '- 发现问题：'.$event->issueCount,
        ];

        foreach (collect($event->summary)->sortDesc()->take(8) as $type => $count) {
            $lines[] = '- '.$type.'：'.$count;
        }

        FeishuClient::sendCard(
            '🔍 SEO 巡检报告',
            implode("\n", $lines),
            $event->issueCount > 0 ? 'orange' : 'green',
            plugin_setting('feishu-notifier', 'admin_url', config('feishu-notifier.admin_url')),
            '打开后台处理',
        );
    }
}
