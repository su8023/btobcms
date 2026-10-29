<?php

namespace Modules\LeadMagnet\Support;

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
<p>资料磁铁：用「下载报价单/规格书/手册」换买家邮箱——B2B 首访 97% 不发询盘，磁铁把路过流量变成可反复触达的邮箱资产。</p>
<h4>创建第一个磁铁（约 3 分钟）</h4>
<ol>
<li>后台 → 增长 → 资料磁铁 → 新建：slug（如 <code>price-list-2026</code>）、多语言标题/描述、上传文件（PDF/ZIP/DOCX，最大 20MB）、启用；</li>
<li>落地页自动上线：<code>https://你的域名/download/price-list-2026</code>（8 语言自动前缀）；</li>
<li>把链接放到文章 CTA、页脚、WhatsApp 快捷回复里推广。</li>
</ol>
<h4>买家侧流程（全自动）</h4>
<ol>
<li>买家填邮箱提交 → 收到含 <b>16 小时有效签名下载链接</b> 的邮件（防直链转发）；</li>
<li>同时系统自动创建一条询盘（来源 magnet）——飞书/邮件通知插件会照常提醒你；</li>
<li>后台磁铁列表可见每份资料的下载次数。</li>
</ol>
<h4>内容建议</h4>
<ul>
<li>磁铁选买家真想要的东西：完整价格区间表、选型对比表、认证清单、案例集；</li>
<li>每年更新一次版本号（2026→2027）即可长期引流；</li>
<li>配合 3 天后跟进邮件（后续版本）转化率更高。</li>
</ul>
<h4>前置条件</h4>
<ul>
<li>发信依赖站点邮件配置（.env SMTP），未配置时买家收不到下载邮件——先用 MailNotifier 的排查方法确认能发信。</li>
</ul>
DOCS;
    }
}
