<?php

namespace Modules\TransCoverage\Support;

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
<p>翻译覆盖率看板：8 语言站点的全局视野——哪些文章/产品缺哪种语言、哪些 SEO 元数据没翻译，一张矩阵看全，并一键 AI 补翻。</p>
<h4>日常用法</h4>
<ol>
<li>后台 → 增长 → 翻译覆盖率；</li>
<li>矩阵行 = 每篇内容（按缺口数降序，最缺的排最前），列 = 8 种语言：绿✓ 完整 / 黄◐ 只有标题 / 红✗ 缺失；</li>
<li>顶部「AI 补翻」：选一篇缺语言最多的文章 → 确认 → AI 翻译所有缺失语言（含 SEO 元数据）→ 矩阵自动刷新；</li>
<li>目标：完全翻译率逐月上升；正文可以用 AI 翻，但发布对应语言前建议抽查质量。</li>
</ol>
<h4>说明</h4>
<ul>
<li>纯查询聚合，看板本身零 AI 成本，只有点「AI 补翻」才调用；</li>
<li>矩阵缓存 10 分钟，内容变更自动重算。</li>
</ul>
DOCS;
    }
}
