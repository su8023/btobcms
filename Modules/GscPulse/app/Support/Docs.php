<?php

namespace Modules\GscPulse\Support;

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
<p>Google Search Console 数据回流：每日自动拉取搜索数据（哪些词带来展现/点击/排名），在后台出「临门一脚榜 / 衰退预警 / 机会词」，让内容优化从拍脑袋变成看数据。</p>
<h4>首次配置（约 5 分钟）</h4>
<ol>
<li>Google Cloud Console 建项目 → 启用「Search Console API」→ 创建 OAuth 客户端 ID（应用类型 Web）→ 授权重定向 URI 填 <code>https://你的域名/admin/gsc-pulse/callback</code>；</li>
<li>复制 Client ID 和 Client Secret；</li>
<li>后台 → 增长 → GSC 连接：填入凭据 + 确认资源网址（默认站点 URL，须与 GSC 资源完全一致）→ 保存 → 点「连接 Google」→ 授权；</li>
<li>回到 GSC 看板点「立即拉取」，数据到位后三个榜单开始工作；之后每天 06:10 自动拉取。</li>
</ol>
<h4>三个榜单怎么用</h4>
<ul>
<li><b>临门一脚</b>（排名 4~15、展现≥100）：差一点就进首页的词——优先用 AI 写作室把对应文章优化掉，性价比最高；</li>
<li><b>衰退预警</b>（周环比 -30%）：内容保鲜清单，更新或补写；</li>
<li><b>机会词</b>（有展现零点击）：用户在搜但你没覆盖的词——开新文的选题库。</li>
</ul>
<h4>常见问题</h4>
<ul>
<li>连接后无数据：新站 GSC 本身数据有 2~3 天延迟；确认 GSC 里已提交 sitemap 且有收录；</li>
<li>Token 过期：插件自动用 refresh token 续期，若提示重新连接，重新走一遍授权即可。</li>
</ul>
DOCS;
    }
}
