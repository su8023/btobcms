<?php

namespace Modules\FeishuNotifier\Support;

/**
 * 插件使用教程（约定：App\Support\PluginDocs 读取 content()）。
 * 纯静态 HTML：插件中心卡片「使用教程」展开块 + 插件设置页顶部折叠区共用。
 */
class Docs
{
    public static function content(): string
    {
        return <<<'DOCS'
<h4>这个插件做什么</h4>
<p>把网站的关键事件（新询盘、飞书推送失败告警等）实时推送到你的飞书群，销售在手机上第一时间看到买家询盘。</p>
<h4>配置步骤（约 2 分钟）</h4>
<ol>
<li>打开飞书群 → 右上角「设置」→「群机器人」→「添加机器人」→ 选「自定义机器人」；</li>
<li>复制生成的 <code>Webhook 地址</code>（形如 https://open.feishu.cn/open-apis/bot/v2/hook/xxxx），如设置了签名校验，把 <code>密钥</code> 也复制；</li>
<li>回到本页「插件设置」粘贴 Webhook 地址（和密钥），保存；</li>
<li>点插件中心顶部「运行可用性检查」，看到 ✓ 即配置成功。</li>
</ol>
<h4>日常行为（全自动，无需操作）</h4>
<ul>
<li>买家提交询盘 → 群里收到蓝色卡片：客户名/邮箱/国家/来源/留言摘要 + 「打开后台处理」按钮；</li>
<li> honeypot 蜜罐识别的垃圾询盘不推送，不打扰；</li>
<li>推送失败时站内铃铛会收到告警，方便排查 Webhook 是否失效。</li>
</ul>
<h4>常见问题</h4>
<ul>
<li>收不到推送：先查设置里的开关是否打开，再确认 Webhook 未过期（飞书机器人可被手动移除）；</li>
<li>想换群：换一个新的 Webhook 地址保存即可，历史配置自动覆盖。</li>
</ul>
DOCS;
    }
}
