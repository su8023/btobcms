<?php

namespace Modules\AiContentWriter\Support;

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
<p>AI 内容生产全套：<b>AI 写作室</b>（独立页面，两段式出方案→成文）+ 文章编辑页的 <b>AI 填充 / AI 翻译 / SEO 体检</b> 三个按钮。</p>
<h4>首次配置</h4>
<ol>
<li>本页「插件设置」选 AI 供应商：默认 DeepSeek（.env 填 <code>DEEPSEEK_BASE_URL / DEEPSEEK_API_KEY / DEEPSEEK_MODEL</code>），或直接在设置里填自定义 OpenAI 兼容接口（base_url + key + model，兼容 GLM/OpenAI/中转站）；</li>
<li>可调默认字数、语气、目标读者、FAQ 数量。</li>
</ol>
<h4>日常用法（推荐流程）</h4>
<ol>
<li><b>方式 A · AI 写作室</b>（后台 → AI 写作室）：输入主题 → 出方案（大纲/关键词/FAQ）→ 可改方案 → 成文 → 自动建草稿；</li>
<li><b>方式 B · 编辑页 AI 填充</b>：新建文章 → 点「AI 填充」→ 输入主题 → 标题/摘要/正文/关键词直接填进表单（不落库），检查后自己保存；</li>
<li><b>AI 翻译</b>：文章写好（默认语言）→ 编辑页点「AI 翻译」→ 勾选目标语言 → 整篇（含 SEO 元数据）翻译合并，已有语言不覆盖；</li>
<li><b>SEO 体检</b>：发布前点一下，秒级列出问题清单（标题长度/字数/H2 结构/图片 alt/内链/SEO 元数据）。</li>
</ol>
<h4>搭配建议</h4>
<ul>
<li>写完发布 → AutoSeo 自动补 SEO 并推送收录 → LinkWeaver 自动织内链 → GeoBoost 补 FAQ，一条龙全自动；</li>
<li>翻译内容质量建议抽查（尤其阿语/俄语），确认后再发布对应语言。</li>
</ul>
DOCS;
    }
}
