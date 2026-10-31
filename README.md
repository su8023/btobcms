# BtoBcms — B2B 外贸获客 CMS

面向外贸全品类的**询盘型获客网站系统**（非在线交易站）：SEO 优先、多语言、插件化扩展。核心思路是"内容与产品页获取 Google 自然流量 → 询盘表单/询盘篮收集线索 → 飞书/邮件即时通知 → 后台轻 CRM 跟进"。

## 项目简介

- **全品类产品目录**：无限级分类树 + 属性模板（AttrSet）+ JSON 属性字段，机械类挂"功率/电压"，纺织类挂"克重/成分"，互不干扰
- **多语言 SEO**：内置 `en / es / ar / ru / pt / fr / de / it` 共 8 种语言（子目录 + hreflang 互链，`ar` 自动 RTL），框架支持任意扩展
- **SEO 引擎**：三级可编辑 TDK（全局/分类/产品，模板 + 单条覆盖）、canonical、结构化数据、分片 sitemap、301 重定向管理
- **询盘获客**：产品页 Get Quote、询盘明细（RFQ）多语言自动回复邮件、垃圾防护
- **插件化架构**：基于 [nwidart/laravel-modules](https://nwidart.github.io/laravel-modules)，核心只做"目录 / 内容 / 询盘 / SEO / 多语言"五大件，其余能力全部以插件形式挂载
- **AI 能力**：统一 LLM 调用层（OpenAI 兼容协议，DeepSeek / GLM / OpenAI 一键切换），AI SEO 巡检 + AI 文章草稿（人审后发布）

## 技术栈

| 层 | 选型 | 说明 |
|---|---|---|
| 后端框架 | Laravel 13 | 单体全栈，PHP ^8.3 |
| 管理后台 | Filament 5 | 产品/分类/询盘/文章/重定向/站点 CRUD，路径 `/admin` |
| 插件系统 | nwidart/laravel-modules 13 | 插件 = `Modules/` 下独立模块，可启停 |
| 内容翻译 | spatie/laravel-translatable | 字段级多语言（JSON 列） |
| 数据库 | SQLite（开发）/ MySQL 8（生产） | 开发零配置，生产切 `.env` 即可 |
| 前台 | Blade + 原生 CSS | 服务端渲染，SEO 天然友好，无 SPA/构建复杂度 |
| 队列 | Laravel Queue（database 驱动） | 生产可切 Horizon/worker |

## 快速开始（Windows 本机）

本机工具链实际位置（Git Bash 路径）：

| 工具 | Windows 路径 | Git Bash 路径 |
|---|---|---|
| PHP 8.4 | `E:\dev-tools\php84\php.exe` | `/e/dev-tools/php84/php.exe` |
| Composer | `E:\dev-tools\composer.phar` | `/e/dev-tools/composer.phar` |

```bash
# 1. 安装依赖
/e/dev-tools/php84/php.exe /e/dev-tools/composer.phar install

# 2. 初始化环境（首次；.env 与 database/database.sqlite 已就绪则跳过）
cp .env.example .env
/e/dev-tools/php84/php.exe artisan key:generate

# 3. 建表 + 种子数据
/e/dev-tools/php84/php.exe artisan migrate --seed

# 4. 启动开发服务器
/e/dev-tools/php84/php.exe artisan serve
```

- 前台：<http://127.0.0.1:8000>
- 管理后台：<http://127.0.0.1:8000/admin>
  - 账号：`admin@btobcms.local` / 密码：`Admin@2026`
  - **该密码仅限本地开发，上线前必须修改**

## 部署到服务器（发布包）

```bash
# 构建生产包（产物：dist/btobcms-v{版本}.zip，自带生产依赖，无需服务器装 composer）
php scripts/build-release.php

# 验收包完整性（18 项检查：artisan/vendor/模块/lang/目录骨架 + 排除清单 + vendor 自洽）
php scripts/verify-release.php dist/btobcms-v1.4.19.zip
```

上传 `dist/btobcms-v1.4.19.zip` 到服务器解压后：

```bash
cp .env.example .env
php artisan key:generate      # 必须：没有它会话无法加解密
# 编辑 .env：APP_ENV=production / APP_DEBUG=false / APP_URL / DB_* / TRUSTED_PROXIES / 邮件 / 飞书 / AI Key
php artisan migrate --force    # 含 users.role 迁移
php artisan storage:link
chmod -R 775 storage bootstrap/cache
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan queue:work --daemon   # 询盘邮件 / SEO 巡检 / AI 任务都靠它
```

完整步骤、CDN 可信代理配置与常见坑见 **[docs/deployment.md](docs/deployment.md)**。

## 核心命令清单

| 命令 | 说明 |
|---|---|
| `/e/dev-tools/php84/php.exe artisan seo:audit --base-url=https://example.com --limit=100` | 抓取站点 sitemap 做规则化 SEO 巡检（TDK 缺失/长度、H1 数量、canonical、HTTP 状态码），报告写入 `storage/app/seo-audits/` 并触发 `SeoAuditFinished` 事件。`--base-url` 缺省取 `APP_URL`，另有 `--timeout` 控制单页超时 |
| `/e/dev-tools/php84/php.exe artisan ai:article "how to choose led strip supplier" --lang=en --words=1200` | AI 生成一篇 SEO 文章并保存为**草稿**（含 FAQ 与内链占位），后台人审后才发布 |
| `/e/dev-tools/php84/php.exe artisan feishu:test` | 向配置的飞书群发送测试卡片，验证 Webhook 连通性 |
| `/e/dev-tools/php84/php.exe artisan module:list` | 列出全部插件及启停状态 |
| `/e/dev-tools/php84/php.exe artisan module:make Xxx` | 在 `Modules/` 下生成新插件骨架 |

## 环境变量说明

### 站点信息（`config/site.php`）

| 变量 | 说明 | 默认值 |
|---|---|---|
| `SITE_NAME` | 站点名称（后台品牌名、TDK 模板 `{site}` 占位符） | `BtoBcms` |
| `SITE_EMAIL` | 对外联系邮箱 | `sales@example.com` |
| `SITE_PHONE` | 对外联系电话 | `+86 755 0000 0000` |
| `SITE_ADDRESS` | 公司地址 | `Shenzhen, Guangdong, China` |

### 飞书通知（`Modules/FeishuNotifier/config/config.php`）

| 变量 | 说明 |
|---|---|
| `FEISHU_WEBHOOK_URL` | 飞书群自定义机器人 Webhook 地址，必填才会推送 |
| `FEISHU_WEBHOOK_SECRET` | 群机器人签名校验密钥（开启"签名校验"时填写，可选） |
| `FEISHU_ENABLED` | 插件总开关，默认 `true` |

### AI 能力（`config/ai.php`，OpenAI 兼容协议）

| 变量 | 说明 | 默认值 |
|---|---|---|
| `AI_CHAT_PROVIDER` | 常规生成档位用哪个 provider | `deepseek` |
| `AI_CHEAP_PROVIDER` | 批量打分/分类等低成本任务档位 | `deepseek` |
| `DEEPSEEK_API_KEY` | DeepSeek API Key | — |
| `DEEPSEEK_MODEL` / `DEEPSEEK_BASE_URL` | DeepSeek 模型 / 接口地址 | `deepseek-chat` / `https://api.deepseek.com/v1` |
| `GLM_API_KEY` | 智谱 GLM API Key | — |
| `GLM_MODEL` / `GLM_BASE_URL` | GLM 模型 / 接口地址 | `glm-4-flash` / `https://open.bigmodel.cn/api/paas/v4` |
| `OPENAI_API_KEY` | OpenAI API Key | — |
| `OPENAI_MODEL` / `OPENAI_BASE_URL` | OpenAI 模型 / 接口地址 | `gpt-4o-mini` / `https://api.openai.com/v1` |

切换模型只改 `.env`，不改代码。

## 目录结构导览

```
├── app/
│   ├── Events/                    # 领域事件（InquiryCreated 等，插件监听这些事件扩展功能）
│   ├── Filament/                  # 后台（Resources：产品/分类/询盘/文章/属性/重定向/站点）
│   ├── Http/Controllers/Site/     # 前台控制器（首页/产品/分类/文章/页面/Sitemap）
│   ├── Models/                    # Eloquent 模型（Product、Category、Inquiry、Post、SeoMeta…）
│   ├── Services/Ai/               # LLM 统一调用层（LlmManager，OpenAI 兼容协议）
│   └── Support/
│       ├── helpers.php            # 多语言辅助函数：localized_route() / locale_alternates() 等
│       └── Seo.php                # SEO 元信息解析器（SeoMeta 覆盖 → 内容摘要 → 配置模板）
├── Modules/                       # 插件目录（见下节）
├── config/
│   ├── ai.php                     # LLM provider 与能力档位配置
│   └── site.php                   # 站点信息、语言矩阵、SEO TDK 模板
├── database/migrations/           # 数据表迁移（sites/categories/products/inquiries/content 等）
├── lang/{en,es,ar,ru,pt,fr,de,it}/  # 界面语言包（zh_CN 供后台使用）
├── resources/views/               # Blade 前台模板（home/products/categories/blog/pages/sitemap）
├── routes/web.php                 # 多语言前台路由 + sitemap/robots 路由
└── modules_statuses.json          # 插件启停状态
```

## 插件系统

每个插件是一个独立的 Laravel Module，位于 `Modules/`，自带路由、控制器、控制台命令、配置与迁移，通过 `modules_statuses.json` 控制启停。当前内置三个插件，同时也是插件架构的验收用例：

| 插件 | 功能 |
|---|---|
| `FeishuNotifier` | 监听 `InquiryCreated` / `SeoAuditFinished` / 文章发布等事件，推送飞书群卡片消息 |
| `AiSeoAuditor` | `seo:audit` 命令 + SEO 规则巡检 + 报告落盘 + 飞书周报推送 |
| `AiContentWriter` | `ai:article` 命令，AI 生成多语言 SEO 文章草稿（人审闸门，草稿不直接发布） |

插件与核心的集成方式以**事件监听**为主（核心发布领域事件，插件订阅），不改核心代码。

**插件开发指南见 [docs/plugin-development.md](docs/plugin-development.md)**（编写中，稍后提供）。

## 多语言机制

- 语言矩阵定义在 `config/site.php`：`locales = [en, es, ar, ru, pt, fr, de, it]`，`default_locale = en`
- **默认语言 `en` 不带 URL 前缀**，其余语言带 `/es/`、`/ar/`、`/pt/`、`/fr/`、`/de/`、`/it/` 前缀，SEO 权重集中在同一域名
- 路由按语言前缀注册两套（`routes/web.php`），路由名带语言命名空间（如 `es.home`），由 `localized_route()` 生成当前语言 URL、`locale_alternates()` 生成全语言互链
- 每个页面输出 `hreflang` 互链 + `x-default`（指向 `en` 版本）与 canonical；`ar` 自动启用 RTL 布局
- sitemap 采用 **index + 分片**结构（`/sitemap.xml` → `sitemap-pages/categories/products/posts.xml`），每个 `<url>` 条目携带全语言的 hreflang `xhtml:link` 互链
- 内容翻译用 spatie/laravel-translatable（模型字段 JSON 多语言），界面文案在 `lang/<locale>/`

## 生产部署要点

0. **后台角色**：`admin` 全量权限（含插件中心/主题中心/更新中心/站点设置/AI 写作室/属性 code），`editor` 仅内容运营（产品/分类/文章/询盘）。安装向导与种子账号为 `admin`；新增账号默认 `editor`，提权用 `php artisan user:role <email> admin`
1. **数据库切 MySQL**：修改 `.env` 中 `DB_CONNECTION=mysql` 及 `DB_HOST` / `DB_PORT` / `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD`
2. **队列**：保持 `QUEUE_CONNECTION=database`，常驻 `php artisan horizon`（或 `php artisan queue:work --daemon`）处理 AI 调用、邮件等异步任务
3. **调度器**：配置 cron `* * * * * cd /path/to/BtoBcms && php artisan schedule:run`
4. **CDN 与 HTTPS**：域名接入 Cloudflare（CDN/WAF），源站强制 HTTPS，并更新 `APP_URL`；若走 Cloudflare，在 `.env` 设 `TRUSTED_PROXIES` 为 Cloudflare 官方 IP 段（留空则不信任任何代理，询盘 IP 与限流用真实连接 IP）
5. **安全**：立即修改默认管理员密码 `Admin@2026`；确认 `.env` 不在 web 可访问目录；确认 `storage/app/installed.lock` 已生成（安装向导据此关闭，防止被匿名重装）；配置 SPF/DKIM/DMARC 后用 SES/Postmark 等发信
6. 上线前执行 `php artisan config:cache && php artisan route:cache && php artisan view:cache` 提升性能

## 测试

```bash
/e/dev-tools/php84/php.exe artisan test
```

## 相关文档

- [PLAN.md](PLAN.md) — 完整建设方案（业务模型、架构、里程碑、AI 能力设计）
- [docs/plugin-development.md](docs/plugin-development.md) — 插件开发指南（编写中）
