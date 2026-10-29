<?php

namespace Modules\MailNotifier\Support;

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
<p>新询盘同步邮件抄送给你的销售邮箱（与飞书通知互补，邮件可转发/归档）。</p>
<h4>配置步骤</h4>
<ol>
<li>先确保站点能发信：宝塔站点 .env 里配置 <code>MAIL_MAILER=smtp</code> 与 SMTP 主机/账号/密码（推荐用企业邮箱或阿里云邮件推送）；</li>
<li>本页「插件设置」填 <code>收件邮箱</code>（多个用英文逗号分隔），保存；</li>
<li>提交一条测试询盘（或等真实询盘）验证收件。</li>
</ol>
<h4>日常行为（全自动）</h4>
<ul>
<li>每条新询盘发一封通知邮件：客户信息 + 留言 + 落地页 + UTM 来源，可直接回复给买家邮箱；</li>
<li>垃圾询盘（蜜罐命中）不发送。</li>
</ul>
<h4>常见问题</h4>
<ul>
<li>收不到：检查 .env 邮件配置（可先跑 <code>php artisan tinker</code> 发 Mail::raw 测试）、收件人是否写错、垃圾箱；</li>
<li>邮件进垃圾箱：建议配置 SPF/DKIM（域名解析层）。</li>
</ul>
DOCS;
    }
}
