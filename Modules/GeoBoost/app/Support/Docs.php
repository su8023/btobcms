<?php

namespace Modules\GeoBoost\Support;

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
<p>GEO（AI 搜索引擎优化）：让 ChatGPT/Perplexity 等 AI 引擎更容易引用你的内容并带来询盘。三件事：文章 FAQ 结构化数据、AI 引流看板、llms.txt 近期文章。</p>
<h4>日常用法</h4>
<ol>
<li><b>FAQ 生成</b>：文章编辑页点「AI 生成 FAQ」→ 选语言 → 前台文章底部出现问答区块 + FAQPage JSON-LD（同时拿谷歌富摘要）。重新生成只替换 AI 来源，人工编辑的保留；</li>
<li><b>AI 引流看板</b>：后台 → 增长 → AI 引流看板。自动识别从 ChatGPT/Perplexity/Gemini 等点进来的访客：近 30 天总量、7 天环比、按引擎分布、热门落地页、每日趋势——这是衡量 GEO 是否见效的唯一指标；</li>
<li><b>llms.txt</b>：https://你的域名/llms.txt 自动带出近期文章+摘要，供 AI 引擎引用，无需管理。</li>
</ol>
<h4>建议节奏</h4>
<ul>
<li>存量英文文章逐篇生成一次 FAQ（每篇一次 AI 调用，成本可忽略）；</li>
<li>看板每周看一次趋势；若 ChatGPT 来源持续增长，可加大内容投入——AI 引用偏好 FAQ 结构和明确数据。</li>
</ul>
DOCS;
    }
}
