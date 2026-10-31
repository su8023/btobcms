<?php

namespace Modules\LinkWeaver\Support;

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
<p>智能内链网：你维护「关键词 → 目标页」词典，文章发布时自动在正文首次出现关键词处织入内链，把博客权重持续导向产品/行业页。</p>
<h4>使用步骤</h4>
<ol>
<li>后台 → 增长 → 内链词典 → 新建词条：关键词（如 <code>gate valve manufacturer</code>）+ 目标链接（如 <code>/product/gate-valve</code>）；</li>
<li>建议先录入 10~20 个核心产品词/行业词；可按语言区分（西语词只在西语正文生效）；</li>
<li>录入完点右上角「全量回织」——把历史已发布文章一次性补上内链；</li>
<li>文章很多时单次回织有处理时长上限，提示「部分完成」时再点一次即可接着跑（幂等，不会重复织）；</li>
<li>之后每篇新文章发布时自动织链，无需再操作。</li>
</ol>
<h4>织链规则（保护性设计）</h4>
<ul>
<li>每篇文章最多织 3 条（可在 .env <code>LINK_WEAVER_MAX_LINKS</code> 调整）；</li>
<li>同一关键词同一文章只织一次；已有链接、代码块内永不改动；</li>
<li>纯 ASCII 关键词按词边界匹配（<code>valve</code> 不会错切 <code>valvebody</code>）。</li>
</ul>
<h4>建议</h4>
<ul>
<li>目标链接优先指向已发布的产品页/行业页（未发布的 404 会伤 SEO）；</li>
<li>关键词写具体些（3 词以上），过短容易误链。</li>
</ul>
DOCS;
    }
}
