<?php

namespace Modules\AutoSeo\Support;

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
<p>零配置全自动 SEO：文章<b>发布即</b>① AI 生成缺失的 SEO 标题/描述/关键词（逐语言补全）② 推送全部语言版本 URL 到 IndexNow（Bing/Yandex 即时收录）。</p>
<h4>首次使用（无需设置页）</h4>
<ol>
<li>.env 配好 AI Key（与 AI 写作室共用 DeepSeek 等）；</li>
<li>密钥文件 <code>storage/app/indexnow.key</code> 首次发布文章时自动生成，验证地址 <code>https://你的域名/{32位key}.txt</code>；</li>
<li>发布第一篇文章后，到 <a href="https://www.bing.com/indexnow" target="_blank" rel="noopener">Bing IndexNow</a> 或直接等搜索引擎按协议拉取验证。</li>
</ol>
<h4>日常行为（全自动）</h4>
<ul>
<li>发布文章 → 队列里 AI 逐语言补齐缺失的 TDK（已有人工填的不覆盖）→ 全部语言 URL 推送 IndexNow；</li>
<li>TDK 生成失败只记日志，不影响发布，下次更新文章可重试。</li>
</ul>
<h4>搭配建议</h4>
<ul>
<li>配合编辑页「SEO 体检」（AI 写作室提供）在发布前把人工能填的先填好；</li>
<li>Google 收录请同时在 GSC 提交 sitemap（可配 GscPulse 插件自动回流数据）。</li>
</ul>
DOCS;
    }
}
