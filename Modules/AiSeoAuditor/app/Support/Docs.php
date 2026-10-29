<?php

namespace Modules\AiSeoAuditor\Support;

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
<p>对站点页面做 AI 视角的 SEO 体检：抓取页面 → 按 30+ 项 SEO 规则检查 → AI 汇总问题与改法，报告存档可回看。</p>
<h4>配置步骤</h4>
<ol>
<li>本页「插件设置」确认 AI 接口（默认用 DeepSeek，可自定义 base_url/key/model，与 AI 写作室共用供应商）；</li>
<li>SSH 跑一次全站体检：<code>php artisan seo:audit https://你的域名 --limit 50</code>；</li>
<li>报告在后台「AI SEO 审计报告」页查看（问题分级 + AI 修改建议）。</li>
</ol>
<h4>建议节奏</h4>
<ul>
<li>上线初期每周跑一次（每次限 30~50 页控制 token 成本）；</li>
<li>大改版/批量发布新内容后跑一次对比；</li>
<li>报告文件保留在 storage/app/seo-audits/，插件设置可配保留份数。</li>
</ul>
DOCS;
    }
}
