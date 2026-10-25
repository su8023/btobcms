# 主题开发指南

> 本文基于内置的 `themes/midnight/`（样式主题）与 `themes/focus/`（排版主题）编写，所有约定均可在 `app/Services/ThemeManager.php` 头注释中找到对应实现。

---

## 1. 主题系统架构

核心前台视图与 `public/css/site.css` 是"基础皮肤"；主题 = 一层**覆盖层**：

- **配色/组件覆盖**：主题 CSS 在核心 `site.css` 之后加载，覆盖 `:root` 变量即可全局换肤
- **视图覆盖**：主题 `views/` 目录前置到 View Finder，**同名视图即覆盖核心模板**（如 `layouts/app.blade.php`、`home.blade.php`）
- **静态资源**：激活时主题 `assets/` 复制到 `public/themes/{slug}/`，布局自动引入主题 CSS

启用状态存 `settings` 表（键 `site.active_theme`），切换即时生效、随时可切回。

---

## 2. 目录结构（照抄 themes/midnight/ 即可起步）

```
themes/midnight/
├── theme.json              # 清单（必需）
└── assets/
    └── css/
        └── theme.css       # 配色/样式覆盖
```

theme.json：

```json
{
    "name": "Midnight",
    "slug": "midnight",
    "version": "1.0.0",
    "author": "你的名字",
    "description": "暗夜模式示例主题"
}
```

- `slug` 必须匹配 `/^[a-z][a-z0-9-]*$/` 且与目录名一致（安装时的唯一性标识）
- `assets/css/theme.css` 会被布局自动引入（激活后），示例内容——只覆盖变量即全局换肤：

```css
:root {
    --primary: #5b8def;
    --primary-dark: #3f6fd4;
    --primary-deep: #101b31;
    --bg: #0b1220;
    --surface: #121b2d;
    --border: #22304a;
}
```

---

## 3. 覆盖视图（排版级主题）

在主题下创建 `views/` 目录，放入与 `resources/views/` 同相对路径的模板即可覆盖——
**这就是"排版主题"的实现方式**：不换配色，换整页布局。

```
themes/focus/
├── theme.json
├── assets/css/theme.css
└── views/
    └── home.blade.php            # 覆盖首页（区块顺序、首屏构图）
```

建议做法：把 `resources/views/home.blade.php` 复制过来改成你想要的构图（区块增删、顺序调整、首屏放询盘表单等），配色仍走 theme.css 变量。核心模板升级时你的覆盖不受影响。

**内置排版主题参考**（可直接复制改造）：
- `themes/focus/` — 询盘聚焦型：首屏左文案右表单，产品区前置（工业 B2B 经典高转化布局）

排版主题的 Controller 数据（$rootCategories/$latestProducts/$stats 等）对所有主题可用——换排版不用改控制器。

---

## 4. 安装与启用

1. **后台安装**：主题中心 → 「上传主题」→ 选择 ZIP 包（theme.json 在包根或唯一一级子目录内）→ 自动解压发布 → 列表中点「启用」
2. **本地开发**：直接把主题目录放进项目 `themes/` 下，主题中心列表即刻出现 → 点「启用」
3. **快速微调（无需建主题）**：站点设置 → 外观 → 「自定义 CSS」文本框，保存即注入全站

删除：仅非激活主题可删（同时清除已发布资源）。

---

## 5. 可用的核心 CSS 变量（覆盖即换肤）

```
--primary / --primary-dark / --primary-deep   主色三阶（按钮/渐变/链接）
--primary-soft / --primary-100                主色浅底
--accent                                      强调色（琥珀）
--ink / --muted / --bg / --surface / --border 文字与背景体系
--radius / --radius-lg / --shadow-*           形状与阴影
```

全部组件（hero 渐变、按钮、卡片、徽章）都引用这些变量——换一组值就是一套新主题。
