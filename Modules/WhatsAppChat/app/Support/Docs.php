<?php

namespace Modules\WhatsAppChat\Support;

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
<p>全站右下角显示 WhatsApp 悬浮按钮，买家一键跳转 wa.me 直接和你开聊——外贸站转化利器。</p>
<h4>配置步骤</h4>
<ol>
<li>本页「插件设置」填 <code>WhatsApp 号码</code>：<b>国际格式纯数字</b>，例如中国号码 <code>8613800138000</code>（国家码 86 + 号码，不要 + 号、不要 0 开头）；</li>
<li>可选：自定义气泡文案（默认 "Chat on WhatsApp"）与预填消息；</li>
<li>保存后前台任意页面右下角出现绿色悬浮按钮。</li>
</ol>
<h4>日常使用</h4>
<ul>
<li>按钮点击即打开 WhatsApp 对话框并带上预填消息，买家无需手动输入；</li>
<li>安卓/iOS/桌面端均可正常唤起；号码填写错误是"点了没反应"的最常见原因，检查是否多了 + 或 0。</li>
</ul>
DOCS;
    }
}
