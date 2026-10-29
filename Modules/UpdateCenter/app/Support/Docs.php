<?php

namespace Modules\UpdateCenter\Support;

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
<p>后台一键更新站点程序：从 GitHub 拉取新版本 → 自动备份数据库 → 切换版本 → 重装依赖 → 迁移 → 失败自动回滚。</p>
<h4>首次配置（一次性）</h4>
<ol>
<li>本页「插件设置」填 GitHub 仓库（如 <code>su8023/btobcms</code>）；私有仓库再填 Token（GitHub → Settings → Developer settings → Personal access tokens，勾 repo 只读权限）；</li>
<li>确认服务器满足：git 可用、站点目录是 git 仓库（宝塔：软件商店装 Git）；</li>
<li>PHP 禁用函数需放开 proc_open（更新器要跑 composer）。</li>
</ol>
<h4>日常更新（三步）</h4>
<ol>
<li>后台 → 更新中心：看到红点即有新版本，先读更新说明；</li>
<li>点「更新」→ 站点自动进维护模式（访客看 503）→ 全程日志实时可见 → 完成自动恢复；</li>
<li>更新后建议提交几条测试询盘确认功能正常。</li>
</ol>
<h4>安全保障</h4>
<ul>
<li>每次更新前自动备份数据库（保留最近 5 份，storage/app/backups/）；</li>
<li>任一步失败自动回滚到更新前版本并恢复访问；</li>
<li>队列 worker 会在更新后自动重启加载新代码。</li>
</ul>
<h4>常见问题</h4>
<ul>
<li>提示"有本地改动"：SSH 跑 <code>git status</code> 查看并还原，或在 .env 设 <code>UPDATE_CENTER_ALLOW_DIRTY=true</code>（不推荐）；</li>
<li>GitHub 限流：匿名 60 次/小时，配 Token 后解除。</li>
</ul>
DOCS;
    }
}
