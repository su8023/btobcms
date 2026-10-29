<?php

namespace Modules\ImageForge\Support;

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
<p>图片自动 WebP 优化：上传的图片自动生成 WebP 版本（最大边 1600px、原图永远保留），前台产品页自动用 &lt;picture&gt; 输出——改善谷歌 Core Web Vitals 与加载速度。</p>
<h4>使用步骤</h4>
<ol>
<li>启用即工作：后台上传的每张图片自动生成 <code>原名.webp</code> 兄弟文件（队列执行）；</li>
<li><b>历史图片回填</b>：后台 → 增长 → 图片优化 → 点「全量转换」，或 SSH 跑 <code>php artisan imageforge:convert</code>；</li>
<li>前台产品主图已自动接线（&lt;picture&gt; + webp 优先，无 webp 自动回退原图）；</li>
<li>页面显示图片总数 / 已转数 / 估算节省体积。</li>
</ol>
<h4>说明</h4>
<ul>
<li>原图永不删除：正文富文本里已嵌入的旧图片链接不受影响；</li>
<li>纯 GD 实现，无需安装任何 composer 依赖；</li>
<li>模板开发者可用 <code>imageforge_picture($path, $alt)</code> / <code>imageforge_src($path)</code> 帮助函数。</li>
</ul>
DOCS;
    }
}
