<?php

namespace Modules\FeishuNotifier\Console\Commands;

use Illuminate\Console\Command;
use Modules\FeishuNotifier\Support\FeishuClient;

class FeishuTestCommand extends Command
{
    protected $signature = 'feishu:test';

    protected $description = '向配置的飞书群发送一条测试消息，验证 Webhook 连通性';

    public function handle(): int
    {
        if (blank(plugin_setting('feishu-notifier', 'webhook', ''))) {
            $this->error('未配置 Webhook — 请在插件中心「设置」面板填写，或在 .env 设置 FEISHU_WEBHOOK_URL。');

            return self::FAILURE;
        }

        $result = FeishuClient::sendCard(
            '✅ BtoBcms 连通性测试',
            'feishu-notifier 插件工作正常。'.PHP_EOL.'时间：'.now()->toDateTimeString(),
            'green',
        );

        if ($result['status'] === 'sent') {
            $this->info('已发送，请到飞书群查看。');

            return self::SUCCESS;
        }

        $this->error('发送失败：'.($result['error'] ?? '未知原因').'（详见 laravel.log 的 [feishu-notifier] 日志）');

        return self::FAILURE;
    }
}
