<?php

namespace Modules\TrustBuilder\Support;

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
<p>信任背书：客户案例（/case-studies）+ 认证证书管理 + 全站页脚信任条。B2B 买家决策里信任信号权重极高，这是站点的"信用面"。</p>
<h4>使用步骤</h4>
<ol>
<li>后台 → 增长 → 客户案例：录入案例（客户名/行业/国家/多语言标题正文/封面/图集），发布后出现在 <code>/case-studies</code> 列表；</li>
<li>后台 → 增长 → 认证证书：录 ISO/CE/SGS 等证书（名称/机构/编号/有效期/证书图）；</li>
<li>页脚上方的<b>信任条自动出现</b>（证书徽章 + 案例数链接），无需任何配置；</li>
<li>案例详情页自带相关案例推荐与询盘 CTA，8 语言独立内容。</li>
</ol>
<h4>内容建议</h4>
<ul>
<li>案例写「场景-方案-结果」：买家行业痛点 → 供了什么 → 数据化结果（交期/良率/成本）；</li>
<li>每个核心行业至少 1 个案例（与行业落地页互相导流）；</li>
<li>证书图用扫描件，有效期到期前更新（过期证书展示反而减分）。</li>
</ul>
DOCS;
    }
}
