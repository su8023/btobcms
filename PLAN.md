# BtoBcms — 外贸获客系统完整建设方案

> 定位：面向外贸全品类的询盘型获客网站（非在线交易站），具备完整功能与插件化扩展能力。
> 本文档假设：单企业站起步，架构预留多站点/多租户能力；如做平台型（多商家入驻），见 §10。

## 0. 实施进度（2026-10-24 更新）

**已完成 — M1 骨架（当日完成）**

- [x] 环境就绪：PHP 8.4 + Composer（用户级 E:\dev-tools）、Laravel 13 + SQLite
- [x] 核心数据模型：sites/categories（物化路径）/attr_sets/attributes/products（JSON 属性）/inquiries(+items,followups)/posts/seo_meta/redirects/settings/ai_calls
- [x] 插件系统：nwidart/laravel-modules，三个插件模块生成并启用
- [x] LLM 底座：OpenAI 兼容协议 LlmManager（DeepSeek/GLM/OpenAI 可切换）+ ai_calls 计量
- [x] 插件 feishu-notifier：询盘事件→飞书卡片（Webhook 未配置时优雅降级），feishu:test 命令
- [x] 插件 ai-seo-auditor：seo:audit 命令（sitemap 抓取 + TDK/H1/canonical/状态码规则检查 + JSON 报告 + 事件推送），每日 06:00 调度
- [x] 插件 ai-content-writer：ai:article 命令生成 SEO 文章草稿（强制 draft 待审）
- [x] 询盘 API /api/inquiries：校验、honeypot、UTM/落地页归因、事件分发（已实测）

**已完成 — M2 前台与后台（当日完成）**

- [x] 多语言路由：en 无前缀 + es/ar/ru 前缀，SetLocale 中间件，localized_route/locale_alternates 辅助函数，4 套语言包，RTL（ar）
- [x] SEO 引擎：Seo 解析器（SeoMeta 覆盖 → 摘要 → 配置模板三级回退）、TDK/canonical/hreflang+x-default/OG、JSON-LD（Product+AggregateOffer/Organization/Breadcrumb/Article）、分片 sitemap（pages/categories/products/posts 含 hreflang 互链）、动态 robots.txt
- [x] 前台页面：首页/产品列表(搜索)/产品详情(属性表+图片+询盘表单+相关产品)/分类页(子分类+属性分面筛选+分页)/博客列表与详情/About/Contact；原生 CSS 设计系统 + UTM 首触归因 JS
- [x] 301 跳转：RedirectRequests 中间件 + 后台管理 + 命中计数（缓存 1 小时）
- [x] Filament 后台（8 个资源）：产品（多语言 Tabs + KeyValue 属性 + 多图 + SEO 覆盖）、分类（树选择/防环 + 属性模板挂载 + SEO）、文章（多语言 + AI 标记 + 草稿徽标）、询盘（状态管道/分配/归因/跟进记录 RelationManager/快速标记已读/垃圾）、属性模板与属性定义（枚举选项/可筛选）、站点、301 规则
- [x] 仪表盘：询盘/产品/待审文章统计 widget

**已完成 — M2.5 转化闭环与运营效率（2026-10-24 第三轮，多 agent 并行）**

- [x] 询盘篮 RFQ：产品加购按钮 + 篮子页一次提交多产品（/inquiry-basket，noindex），四语言
- [x] 邮件闭环插件 mail-notifier：买家确认信 + 管理员通知（事件驱动，优雅降级），mail:test 命令
- [x] WhatsApp 挂件插件 whatsapp-chat：全站浮动按钮（RTL 适配），经前台 Slots 插槽系统注入
- [x] 前台插槽系统 Slots（head / floating），GA4 接入位（GA4_ID）
- [x] 后台中英文切换：user.locale 持久化 + SetAdminLocale 中间件 + 全后台文案双语（172 键 ×2）+ 用户菜单切换项；修复 scoped 导航缓存（forgetScopedInstances）
- [x] 询盘 CRM 深度：邮件回复买家（自动写跟进记录）、同邮箱客户历史、CSV 导出（BOM）、30 天趋势图 + 来源分布图（ChartWidget）
- [x] 产品批量效率：一键复制（slug/名称/图片处理）、批量改状态、批量移动分类、CSV 导出、CSV 导入（import:products 命令 + 后台上传入口，upsert by slug，dry-run，多语言列）、图片 Alt 多语言编辑
- [x] 全站设置页：站点名/联系方式/每语言首页 SEO 文案，前台品牌、联系页、JSON-LD、首页标题即时生效
- [x] SEO 巡检可视化页 /admin/seo-audit-reports：报告列表 + 明细表 + 一键巡检（AiSeoAuditor 插件内，插件停用自动隐藏）
- [x] AI 文章工具：PRODUCT_LINK 占位符一键替换为产品内链（唯一匹配校验）、一键发布
- [x] 全局搜索：顶栏搜产品（slug/SKU）、搜询盘（客户/邮箱/公司）
- [x] 测试 27 个用例 96 断言全绿；CSV 导入端到端实测（dry-run/多语言/坏行跳过）

**已完成 — 系统验证（2026-10-24 第四轮）**

- [x] 全链路验证脚本 verify_system.php（71 项检查，可重复执行）：多语言 SEO 要素 ×4 语言互指一致性、Product/AggregateOffer 结构化数据、sitemap×4 分片、robots、301 新增即生效、noindex、询盘 API 归因/篮子/蜜罐/422、通知链路（飞书降级+双向邮件+垃圾不触发）、seo:audit 实抓巡检——**71/71 通过**
- [x] 修复 3 个验证发现的 bug：骨架静态 robots.txt 覆盖动态路由、301 未命中缓存致新规则延迟生效、缓存模型对象跨进程反序列化损坏（改标量数组）
- [x] 回归测试增至 31/31（131 断言）；验证报告见 docs/system-verification.md
- [x] 插件中心：插件导航组 + 按类别展示/启停（module.json category 约定，docs 已更新）

**已完成 — 纯线索站转型（2026-10-24 第五轮）**

- [x] 用户决策：不放产品/分类目录，站点纯引流获客（"我们可以帮他做什么"）
- [x] 行业落地页系统：config/industries.php 定义 18 个行业（8 语言名称 + SVG 图标），IndustryController index/show，`/industries` + `/industries/{slug}` 路由（8 语言前缀变体）；行业页含定制导语 + 4 能力卡 + 行业名 CTA + 行业预填询盘表单，Service JSON-LD
- [x] 服务页 /services 重构：四大服务卡（OEM/ODM、质检、物流、寻源）+ 5 步流程 + 页内询盘表单
- [x] 导航重构：Home / Industries / Services / Blog / About Us / Contact（产品下拉移除）；footer 快捷链接与移动端 CTA 条同步改行业入口
- [x] 首页改版为纯获客布局：双栏 hero（左文案+认证徽章 / 右首屏询盘表单卡）、保障承诺条、8 行业入口卡（图标+名称，View all → /industries）、信任特性区、5 步流程；产品/分类区块保留但空库自动隐藏（随时可恢复目录模式）
- [x] 自适应 GEO：无产品时 WebSite JSON-LD 不声明 SearchAction，ItemList 改列行业落地页 URL；hero 统计条无产品时显示行业数
- [x] 语言覆盖扩至 8 种：en/es/ar/ru + pt/fr/de/it（新增 4 语全套语言包：站点 UI + 行业 + 服务）
- [x] 修复行业能力标题键不匹配（capability_* → capability_*_title ×8 语言）
- [x] 回归 73/73 测试（320 断言）全绿，新增行业/服务页冒烟测试；浏览器截图验证首页双栏 hero / 行业区 / 行业落地页能力卡

**已完成 — 外部视角 SEO 审计与修复（2026-10-25 第六轮）**

- [x] sitemap 补录：新增 industries 分片（18 行业 × 8 语言 hreflang），pages 分片加入 /services、/industries；路由白名单同步；空库时 products/blog 列表页自动移出 sitemap（有内容自动回归）
- [x] llms.txt 重构：新增 Industries we serve 清单（18 行业），产品/分类区块空库时不再输出空清单
- [x] 外部视角审计脚本 seo_external_audit.php（Screaming Frog 式黑盒爬取：robots → sitemap 索引 → 全分片 URL 含语言变体 → 逐页 15 项体检 → 扣分制评分），可重复执行
- [x] 通用页 TDK 按语言本地化：Seo::page 回退链改为 后台设置 → 语言包 → 配置；lang/{9}/seo.php（嵌套结构，6 页 × title/description），修复 Laravel 点号键平铺不命中问题
- [x] 行业页/索引页 TDK 增强：show_title 模板缩短 ×9（≤60 字符），show_description de/es 模板收紧，IndustryController/ServiceController 统一走 Seo::page
- [x] og:image 站点级补齐：GD 生成 1200×630 品牌分享图 public/images/og-default.png，seo partial 输出 og:image ×2 尺寸 + twitter summary_large_image
- [x] JSON-LD 全站覆盖：未显式提供结构化数据的页面自动输出 WebPage 兜底图（contact/services/industries 等 24 页受益）
- [x] 内容深度补齐 ×9 语言：行业索引页导语 + 尾段、联系页三段说明（合作流程/工作时间/信息安全）；修复 8 个语言文件 index_body 重复坏键
- [x] 审计脚本修复非拉丁文字分词偏差（阿拉伯/西里尔/中文按字符折算）
- [x] 终验：测试 73/73（330 断言）、verify_system 62/62、外部审计 **100/100（184 页零问题）**；浏览器抽查联系页/西语行业页无视觉回归

**已完成 — Google 严格标准 SEO 审查与修复（2026-10-25 第七轮）**

- [x] 严格审计脚本 seo_strict_audit.php（对标 Lighthouse SEO + 富媒体结果测试 + 搜索中心指南）：标题像素宽估算（580px 截断）、H1→H6 层级跳跃、富媒体必填字段（Organization logo/Service provider/FAQPage 可见性）、E-E-A-T 信任基建、404 体验、图片尺寸/lazy/alt、锚文本质量、移动视口缩放、sitemap lastmod
- [x] 基线 63/100 → **99/100**（唯一剩余项 org_sameas 留给用户在后台填真实社交链接，字段已就绪）
- [x] 隐私政策 + 服务条款页 /privacy /terms（GDPR 核心条款 ×8 语言 + zh_CN；上线前建议律师复核）+ TDK ×9 + sitemap pages 收录
- [x] 自定义 404 页（大字视觉 + Home/Industries/Inquiry 三 CTA + noindex）×8 语言
- [x] footer 底部法律链接行（Privacy / Terms）
- [x] Organization 升级：logo 改 512×512 光栅 PNG（GD 生成，Google 富媒体要求）+ sameAs 字段（后台全站设置新增社交链接 textarea，每行一个 URL）
- [x] 行业落地页 BreadcrumbList（Home > Industries > {行业}）+ 索引/服务页补 H2 修正层级跳跃（×9 语言新键）
- [x] sitemap-industries 真实 lastmod（config/industries.php 文件 mtime）
- [x] 修复基础审计回归：terms TDK 补齐 ×9、ar 隐私描述加长
- [x] 终验：测试 73/73、verify_system 62/62、基础审计 100/100（196 URL）、严格审计 99/100、浏览器验证隐私页/404 页

**已完成 — Web 安装向导（一键安装 + 权限自检）（2026-10-26 第八轮）**

- [x] /install 三步向导：① 环境自检（PHP≥8.2 / 13 扩展 / storage 五目录可写 / vendor 存在 / proc_open 放开——逐项 ✔/✘ 与宝塔操作提示）② 配置安装（MySQL/SQLite + 管理员，表单校验 + 错误内联显示）③ 完成页（后续清单 + optimize 指引）
- [x] 安装动作：.env 生成（production/随机密钥/时区/APP_URL 自动取域名/DB 写入；SQLite 自动转绝对路径）、连接预检（拒绝非空旧库，防误覆盖）、独立 install_run 连接迁移（不动默认连接）、可选演示数据（DemoContentSeeder 与默认管理员解耦）、创建管理员、storage:link、安装锁 storage/app/installed.lock（已装后 /install 全 404 防接管）
- [x] 断点续装：migrate 幂等 + 有迁移进度无 users 的半装库允许重提交继续
- [x] set_time_limit(600) 应对慢盘建表
- [x] 修复验证规则 nullable 前置（HTTP 空输入转 null 导致 sqlite 分支误报）；.env 重复键 bug（dotenv 不可变模式取首值会让生产 APP_KEY 为空——测试加断言锁定）
- [x] 测试 4 个（含 .env 重复键/非空库拒绝/必填校验）；全量 77/77（362 断言）；curl 端到端实测：302→done 0.76s、锁、管理员全部落地
- [x] 宝塔手册改为一键安装流程（第 6-7 节重写）
- [x] 开发库 seeder 重构：DatabaseSeeder（默认 admin+内容）→ ContentSeeder 抽出 → DemoContentSeeder（仅内容，生产可选）

**已完成 — 更新中心：GitHub 版本化 + 后台一键升级（2026-10-26 第九轮）**

- [x] git 仓库就绪：.gitignore 补丁（sqlite/安装锁/审计报告/备份），首提交 528 文件（零敏感文件），tag v1.0.0 → v1.0.1
- [x] UpdateCenter 插件（category=system）：GitHubReleases 客户端（latest/列表/变更说明/prerelease 跳过/限流与 404 友好报错，token 支持私有仓库）、GitService 薄封装（tag 检出/dirty 检查/fetch/checkout）、Updater 编排器（维护模式 → 数据库备份 MySQL+SQLite → git 切换 → composer install → migrate → optimize → 恢复访问；任一步失败自动回滚+尽力 up）、RunUpdate 队列任务（timeout 900s，进度写日志文件）
- [x] Filament 更新中心页：三卡版本对比（含 git tag 检出状态）、最新版变更说明、历史版本列表（可升任意版本）、一键更新确认弹窗、5 秒轮询执行日志、导航新版本红点 badge；前置体检（git 未装/非仓库/无远程/工作区 dirty）阻断并列出人话原因
- [x] 版本源：config/version.php（v1.0.1），git tag 与之一致
- [x] 双语文案（admin.php ×2）+ 插件设置面板（repo/token/git/composer 路径）+ 面板条件注册
- [x] 修复：module.json JSON 语法（PHP json_encode 生成杜绝手写转义）、composer.json PSR-4 注册、Filament slug、Livewire 视图方法调用（$this->）
- [x] 测试 7 个（API 映射/prerelease/限流异常/未配置降级/配置后渲染/模块结构/git tag），全套 **84/84（380 断言）**；浏览器验证：页面渲染/中文文案/未配置引导/日志区
- [x] 文档：docs/update-center.md（GitHub 建仓/Deploy Key/git 部署/发版流程/回滚/FAQ）+ 宝塔手册部署方式改 git clone 优先

**M3 剩余路线（按优先级）**

- [ ] 搜索与筛选升级：Meilisearch 接入（多语言分词 + 全属性分面）
- [ ] AI 巡检 v2：GSC API 接入（收录/点击数据）+ AI 生成 TDK 优化建议进审核队列
- [ ] AI 内容工厂 v2：关键词导入聚类、多语言翻译管线（es/ar/ru 产品文案回退英文问题）
- [ ] 多站点正式化：站点切换中间件、每站点独立分类/语言/收件箱
- [ ] 权限体系（用户决定不上线前不做）：角色 + Filament 策略收紧 canAccessPanel
- [ ] 部署：MySQL + Redis + 队列 worker + Docker Compose + Cloudflare + HTTPS + 备份

---

## 1. 业务模型：先明确"获客站"到底要什么

外贸获客站的核心不是"卖货"，而是漏斗：

```
Google 搜索 / Google Ads / 社媒
        ↓
产品页 · 分类页 · 内容页（多语言、强SEO）
        ↓
询盘（表单 / 询盘篮RFQ / WhatsApp / 邮件）
        ↓
轻CRM（分配业务员、状态流转、跟进记录）
        ↓
邮件孵化（自动回复、Drip 序列）→ 成交
```

由此推论：**交易/支付/库存/物流统统不需要**，需要的是 CMS + SEO 引擎 + 询盘系统 + CRM 的组合。这决定了选型——所有"电商交易系"开源（Shopify、Medusa、Saleor）都不贴场景。

---

## 2. 路线对比

| 路线 | 上线速度 | 插件/拓展能力 | 长期可控性 | 适合 |
|---|---|---|---|---|
| A. WordPress + WooCommerce（询盘模式） | ★★★★★（1~2周） | ★★★★★（6万+插件） | ★★（插件冲突、深度业务逻辑受限） | 快速验证、预算小、无开发团队 |
| B. 自建 Laravel B2B CMS（本方案推荐） | ★★（2~3个月） | ★★★★（自定插件架构） | ★★★★★ | 有开发能力、要做平台/多站点、长期资产 |
| C. Strapi/Directus + Next.js | ★★★ | ★★★★ | ★★★★ | 前端团队强、追求现代栈 |
| D. Bagisto / Odoo 改造 | ★★★ | ★★ | ★★ | 不推荐：偏交易/偏ERP，改造成本高于自建 |
| E. SaaS 建站（Ueeshop、SHOPLINE等） | ★★★★★ | ★（封闭，不可插件化） | ★ | 不符合你的插件化要求 |

**结论：**
- 只想先验证流量和询盘转化 → 用路线 A 先跑 1~2 周，成本几乎为零。
- 认真做（要全功能、要插件化、要全品类）→ **路线 B：自建 Laravel + Filament B2B CMS**，即本项目 `BtoBcms`。
- 两者不冲突：WordPress 可以先上，自建站好后再切域名。

---

## 3. 技术选型（路线 B）

| 层 | 选型 | 理由 |
|---|---|---|
| 后端框架 | Laravel（最新稳定版） | 单体全栈、人才好找、生态包成熟 |
| 管理后台 | Filament | 免费强大，CRUD/权限/图表开箱即用 |
| 模块/插件系统 | nwidart/laravel-modules | 现成的模块化架构，插件=独立目录，可启停 |
| 数据库 | MySQL 8（JSON 字段）+ Redis | 全品类属性用 JSON，缓存/队列用 Redis |
| 搜索 | Meilisearch | 多语言分词好、faceted search、部署简单 |
| 多语言 | spatie/laravel-translatable（内容级）+ 语言包（界面级） | 行业标准方案 |
| 前台渲染 | Blade + Tailwind + Alpine.js（服务端渲染） | SEO 天然友好；不引入 SPA 复杂度 |
| 队列 | Laravel Horizon | 邮件、sitemap、批量导入异步化 |
| 文件存储 | 本地起步 → Cloudflare R2 / 阿里云 OSS | 产品图/目录PDF |
| 邮件 | SES / Mailgun / Postmark | 外贸发信信誉重要，勿用个人SMTP |
| 部署 | Docker + 海外 VPS（2C4G 起）+ Cloudflare | 一台机器起步，CDN 必须 |

> 若团队前端能力强，可在 M5 后把前台替换为 Next.js（ISR）+ Laravel API，后台不动。

---

## 4. 系统架构

```
Cloudflare (CDN/WAF)
        ↓
Nginx ── Laravel 应用 (Blade 前台 + Filament 后台)
        │            ├── Modules/           ← 插件目录（每个插件独立模块）
        │            ├── Core: Product / Category / Inquiry / CRM / Content / SEO / I18n
        │            ├── Filament Admin
        │            └── Queue (Horizon)
        ├── MySQL 8
        ├── Redis
        └── Meilisearch
        └── R2/OSS（图片、PDF）
外部：GA4 + Search Console、SMTP(SES)、hCaptcha/Turnstile、WhatsApp 链接
```

---

## 5. 功能模块清单（分期）

### P0 — MVP：能被搜到、能收到询盘（第 1~6 周）
- **产品目录**：无限级分类树、产品 CRUD、多图/视频、属性参数、PDF 目录下载、相关产品、浏览量
- **多语言框架**：界面语言包 + 内容级翻译 + hreflang + 语言切换
- **SEO 引擎**：TDK（全局/分类/产品三级可编辑）、XML sitemap（分片+index）、结构化数据（Product/Offer/Organization/BreadcrumbList/FAQPage）、canonical、301 管理、robots
- **询盘系统**：产品页"Get Quote"、全局询盘篮（RFQ 购物车式）、表单校验 + Turnstile 防垃圾、多语言自动回复邮件、管理员邮件通知、 honeypot + 频率限制
- **内容模块**：文章/新闻（SEO 引流主力）、案例、FAQ
- **基础页面**：About / Contact / Privacy / Terms / 404
- **基础设施**：CDN、图片 WebP 压缩、备份脚本

### P1 — 运营效率（第 7~10 周）
- **飞书通知插件**（首个插件，同时验证插件架构）：询盘到达 → 飞书群卡片消息（含客户信息/产品/来源，@分配业务员）；AI 巡检周报、文章发布事件同样推送
- **轻 CRM**：询盘状态机（新→已读→跟进中→已报价→成交/流失）、业务员账号与自动/手动分配、跟进记录、标签与备注、询盘去重
- **统计看板**：询盘趋势、来源分析（UTM/着陆页/入口关键词）、转化漏斗
- **批量导入导出**：Excel/CSV 产品与分类导入（图片URL批量抓取）
- **在线客服**：WhatsApp 浮动按钮、Tawk.to / Crisp 嵌入
- **站内搜索**：Meilisearch 接入（分类/属性分面筛选）
- **页面装修**：首页区块化编辑（Banner/推荐分类/推荐产品/自定义区块，JSON 配置驱动）

### P2 — 平台化与增长（第 11 周起）
- **插件体系正式化**：模块市场目录、启停/配置界面、钩子文档（见 §7）
- **多站点/多租户**：一套后台管理多个域名站点，每站点独立语言、模板、分类、询盘收件箱（做矩阵站或 SaaS 的基础）
- **营销自动化**：邮件 Drip 序列（7天/14天跟进模板）、询盘未读提醒、再营销受众导出
- **报价工具**：在线生成 PDF 报价单 / PI（接近轻 ERP）
- **会员体系（可选）**：买家注册、收藏、历史询盘
- **开放能力**：REST API + Webhook（Inquiry.created 等事件），对接企业微信/飞书/Zapier
- **RFQ 公告板（平台模式才做）**：买家发需求，供应商抢单

---

## 6. 核心数据模型

```
sites              多站点/租户预留（id, domain, theme, default_locale, settings）
languages          语言（code, name, direction, enabled）
categories         分类树（parent_id, path 物化路径, attr_set_id, seo, translations）
attr_sets          属性模板（按分类挂载，全品类关键）
attributes         属性定义（type: text/number/enum/multi, unit, options）
products           产品（category_id, attrs JSON, status, price_range, moq, images…）
product_translations  名称/描述等字段级翻译（JSON 列方案可合并进 products）
attachments        图片/PDF（disk, path, alt 多语言）
inquiries          询盘（site_id, source, utm_json, landing_url, status, assignee_id, spam_score）
inquiry_items      询盘明细（product_id, qty, note）
inquiry_followups  跟进记录（inquiry_id, user_id, channel, content, next_action_at）
users / roles / permissions   管理员与业务员
posts / cases / faqs          内容（多语言，分类、标签）
seo_meta           多态 TDK 表（morphMap: Product/Category/Post/Page）
redirects          301 规则
menus / banners / page_blocks  导航与装修
email_templates    多语言邮件模板
mail_logs          发信日志
modules            插件注册表（name, version, enabled, config）
settings           全局配置
```

**全品类设计要点：**
- 分类用**物化路径**（`1/45/230`）而非邻接表递归，支持无限级 + 快速子树查询。
- 产品属性存 **JSON 列**，属性定义来自分类挂载的 `attr_set`——机械类挂"功率/电压"，纺织类挂"克重/成分"，互不干扰。
- 列表页筛选 = Meilisearch 分面（分类 + 该分类属性模板动态出筛选项），避免 MySQL 上做通用 EAV 查询。

---

## 7. 插件机制设计（后期拓展的核心）

每个插件 = 一个 Laravel Module（`modules/WhatsApp/`、`modules/AlibabaSync/`…），包含：

```
modules/Example/
├── module.json          # 名称、版本、依赖、描述、后台菜单声明
├── src/
│   ├── Providers/       # ServiceProvider：注册路由/视图/监听
│   ├── Http/Controllers/
│   ├── Models/
│   └── Console/
├── resources/views/     # 插件前台视图
├── database/migrations/
└── config/config.php    # 插件配置（后台自动生成设置表单）
```

**四个扩展点：**
1. **路由与页面**：插件自带路由，`/whatsapp-settings` 等独立功能页。
2. **前台插槽（Slots）**：`product_sidebar`、`inquiry_form_after`、`footer` 等预定义插槽，`@slot('footer')` 渲染所有启用插件的挂载内容——客户按钮、聊天挂件、信任徽章都这样注入，不改核心代码。
3. **事件钩子**：核心发布领域事件（`ProductCreated`、`InquiryCreated`、`PageRendering`），插件监听——如"新询盘 → 推送企业微信群"、"产品创建 → 自动同步到 Google Merchant"。
4. **后台注入**：module.json 声明菜单项与权限，Filament 自动挂载设置页。

**原则：核心只做 目录/内容/询盘/SEO/多语言 五大件，其余一切（WhatsApp、Google Feed、EDM、汇率、防刷增强、AI 翻译草稿…）都是插件。**

---

## 8. SEO 与获客专项

- **TDK 模板引擎**：`{product_name} Manufacturer & Supplier from China | {site_name}`，分类/产品页按模板批量生成，可单条覆盖。
- **多语言 hreflang**：每语言独立 URL（`/es/`, `/ar/`），spatie/sitemap 分片生成（产品量大时 sitemap index）。
- **结构化数据**：Product + Offer（价格区间/MOQ）+ Organization + BreadcrumbList + FAQPage。
- **速度预算**：LCP < 2.5s（CDN + WebP + 关键 CSS 内联）；Core Web Vitals 直接影响排名。
- **内容矩阵**：博客承载长尾词（"how to choose XXX supplier"），分类页做行业 landing（自动聚合 + 人工润色）。
- **询盘归因**：首访来源、UTM、着陆页、搜索词入 `inquiries`，看板复盘哪个渠道出询盘。

---

## 9. 里程碑与资源

| 阶段 | 周期（1~2名全栈） | 交付 |
|---|---|---|
| M1 | 第 1~2 周 | 骨架：认证/权限/多语言框架/分类树/产品CRUD+图片 |
| M2 | 第 3~4 周 | 前台模板（首页/列表/详情）、SEO 引擎、询盘表单 |
| M3 | 第 5~6 周 | 询盘篮RFQ、邮件通知、内容模块、部署上线 |
| M4 | 第 7~8 周 | 轻CRM、看板、批量导入、搜索 |
| M5 | 第 9~10 周 | 模块化重构、装修系统、插件接口文档 |
| M6+ | 第 11 周起 | 多站点、营销自动化、API/Webhook |

**运行成本**：VPS $20~40/月 + 域名 ~$10/年 + SES 邮件 $0.1/千封 + Cloudflare 免费 + Meilisearch 同机部署（后期独立）。

**WordPress 快速验证版（可选并行）**：WooCommerce（关闭结账）+ YITH Request a Quote + RankMath + Polylang + FluentCRM，1 周内上线，用于验证关键词与询盘率，验证完的词库直接喂给自建站。

---

## 10. 已确认决策（2026-10-24）

1. **形态**：全品类、多国家、多语言的获客站。URL 策略采用**子目录多语言**（`/es/`、`/ar/`，hreflang 互链），SEO 权重集中在一个域名；后期做国家矩阵站时启用多站点能力（表结构已带 `site_id`）。不做在线交易与买家注册。
2. **插件**：核心五大件 + 插件化扩展，支持自己开发插件加载（§7 机制）。
3. **AI 能力**：AI 自动巡检 SEO、AI 自动写文章，作为首批插件落地（§12）。
4. **通知**：询盘/巡检/文章事件推送到飞书群 Webhook（首个插件，用于验证插件架构）。
5. **首发语言**：en + es + ar + ru，框架支持任意扩展。
6. **产品数据**：人工 + Excel 批量导入；采集同步类需求后续以插件立项。

## 11. 风险与对策

- **Google 收录慢**：新域名沙盒期 3~6 个月 → 首月就铺 50~100 篇内容 + GSC 主动提交 + 外链。
- **询盘垃圾/爬虫**：Turnstile + honeypot + 频控 + 邮箱域名黑名单。
- **发信进垃圾箱**：用 SES/Postmark 并配 SPF/DKIM/DMARC，勿用服务器自带 SMTP。
- **插件失控**：从 M5 开始强制"核心不写业务，一切扩展走模块"，接口先冻结再开放。

---

## 12. AI 能力专项设计（首批三个插件）

### 12.0 LLM Provider 抽象（核心提供的底座，非插件）

核心内置一个统一的 LLM 调用层（`modules/` 外的 `app/Services/Ai/`），插件只声明用哪个能力：

- 协议：OpenAI-compatible（`base_url` + `api_key` + `model`），因此 **DeepSeek / GLM / Kimi / OpenAI / Claude(中转) 全都能接**，切换模型只改 `.env`。
- 能力分级：`chat`（生成）、`long-context`（长文）、`cheap`（批量打分/分类用便宜模型）。
- 队列化：所有 AI 调用走 Horizon 队列 + 速率限制 + 失败重试 + token 计量入库（`ai_calls` 表），成本可追溯。
- 内容安全：AI 产出一律进**草稿/待审**状态，不直接对外发布（防低质内容伤 SEO）。

### 12.1 插件：AI SEO 巡检（ai-seo-auditor）

**目标**：无人值守地持续发现 SEO 问题并给出（或自动执行）修复。

- **巡检频率**：每日增量 + 每周全站，Laravel Scheduler 驱动。
- **数据源**：
  - 自有 sitemap → 逐页抓取（队列化，遵守并发限制）；
  - Google Search Console API（点击/曝光/收录状态/排名词）；
  - Core Web Vitals（PageSpeed API，可选项）。
- **规则引擎（无 LLM 也能跑）**：TDK 缺失/过短/过长/重复、H1 缺失或多于一个、死链与 404 外链、canonical 冲突、hreflang 配对错误、图片缺 alt、结构化数据校验（Product/FAQ/Breadcrumb）、canonical 链劫持、近 30 天零收录零点击页面清单。
- **AI 层**：对有问题的页面，LLM 结合"该页产品/分类上下文 + 目标关键词"生成**优化版 TDK/首段**草稿 → 进入人工审核队列（后台一键采纳）；对 GSC 的"曝光高点击低"页面，LLM 分析标题/摘要并建议改写。
- **产出**：后台 SEO 健康分（趋势图）+ 问题清单（按优先级）+ 每周报告推送飞书。
- **边界**：只改 TDK/内容类问题走"审核后自动应用"；结构类问题（死链、跳转）生成工单由人处理。

### 12.2 插件：AI 内容工厂（ai-content-writer）

**目标**：批量产出对 SEO 有效的原创文章，保持人审闸门。

- **选题来源**：GSC 搜索词报表（有曝光无收录的词）、关键词插件导入（Ahrefs/5118 CSV）、人工输入；选题去重与簇聚类（同簇只发一篇支柱文）。
- **生成管线**：大纲 → 分节生成 → **自动插入内链**（从产品/分类库选相关锚文本，这是获客站内容的价值核心）→ 自动生成 TDK + FAQ Schema → 配图（先占位/后接生图）→ 落库为 `draft`。
- **质控**：原创度/空洞度打分（cheap 模型）+ 最低分拒绝；产品词硬校验（禁止编造参数，参数只能引用产品库）。
- **批量**：队列并发 N 篇/日（可配），避免集中发布触发沙盒审查。
- **多语言**：一稿源语言生成后，按站点语言矩阵翻译 + 本地化（不是逐字翻译，术语表 `glossaries` 表控制行业词）。

### 12.3 插件：飞书通知（feishu-notifier）

- 监听核心事件：`InquiryCreated`、`SeoAuditFinished`、`ArticlePublished`、`InquiryAssigned`。
- 每类事件可配开关、目标群、消息模板（飞书卡片 JSON）；询盘消息带 @分配人、产品链接、来源/UTM。
- 实现：自定义机器人 Webhook（无需企业自建应用），签名加签可配；后续进阶用飞书应用 API 实现"点卡片直接认领询盘"（回写 CRM）。

> 三个插件即插件架构的验收用例：事件监听（feishu）、后台注入（巡检报告页）、队列与命令（AI 内容），M1 完成后先落地 feishu-notifier 跑通全链路。
