# 增长插件路线图：引流获客 / SEO / GEO

> 面向 BtoBcms 的可开发插件方案库。每个插件独立成模块（沿用 nwidart 架构），
> 按投入产出比排序。GEO = Generative Engine Optimization，指针对 ChatGPT /
> Perplexity / Google AI Overviews 等 AI 搜索引擎的优化。
>
> **进度（v1.2.3）**：LinkWeaver ✅、LeadLens ✅、GeoBoost ✅、编辑器 AI 提效（填充/翻译/体检）✅。
> 第二轮提案见文末「第二轮提案」。

## 现状盘点（已具备的基础）

| 能力 | 现状 |
|---|---|
| 技术 SEO | 双层审计 96+/100，hreflang/sitemap/canonical 齐全 |
| 收录加速 | AutoSeo 插件：发布即 IndexNow 推送 |
| 内容生产 | AI 写作室（两段式，自定义模型）+ AutoSeo 自动 TDK |
| 获客入口 | 询盘表单（honeypot+UTM）+ 询盘篮 + WhatsApp 悬浮 |
| GEO | 已有静态 /llms.txt 路由 |
| 短板 | 无数据回流（GSC）、内链靠人工、无邮箱沉淀、AI 引擎流量不可见 |

---

## 第一优先级：快速见效（每个 2~4 天）

### 1. LinkWeaver — 智能内链网

**原理**：内链是 B2B 长尾词排名最便宜的手段。博客→产品页/行业页的内链传递权重，
AI 写作室产出的文章目前内链为零。

- 后台维护「关键词 → 目标页」词典（如 `gate valve manufacturer` → /product/gate-valve）
- 监听 PostPublished/ProductSaved：自动在正文首次出现关键词处插入内链
  （每篇上限 N 条、已手工加链的关键词跳过、记录插入位置可撤销）
- 文章底部自动追加「相关产品」区块（按分类/标签匹配）
- 与 PageForge（见 4）联动：程序化页面之间织成网

**表**：`link_dictionary`（keyword、target、locale、max_per_post）、`applied_links`（post_id、keyword、target、created_at）
**产出**：全站内链密度 3 个月内翻倍，长尾页权重提升

### 2. LeadLens — 询盘雷达（增强 + 评分）

**原理**：现在询盘只有裸数据，销售不知道先跟谁。事件链（InquiryCreated → 飞书）已通，
插入评分层即可。

- 监听 InquiryCreated 自动增强：
  - 邮箱域名 → 企业官网猜测（gmail/outlook 等免费邮箱降权）
  - IP → 国家/地区（复用语言检测的 ip-api 逻辑，30 天缓存）
  - 询盘商品数、留言长度、是否有公司名
- 规则评分 0~100 → 热线（≥70 飞书强提醒）/ 温线 / 冷线索自动打标
- Filament 询盘列表加热度徽章 + 筛选；飞书推送带评分和增强信息

**表**：`inquiry_enrichments`（inquiry_id、score、company_site、free_email、details json）
**产出**：销售响应优先级明确，高价值线索响应从小时级到分钟级

### 3. GEOBoost — AI 搜索引擎优化器

**原理**：B2B 采购决策者越来越多用 ChatGPT/Perplexity 搜供应商。AI 引擎引用内容的
三大偏好：FAQ 结构、明确数据、可引用的 llms.txt。

- **动态 llms.txt**：从 sitemap + 热门内容自动生成（含每页一句话摘要），每日队列刷新，
  替换现在的静态路由
- **FAQ 区块**：产品/分类/文章保存时 AI 自动生成 5 组问答（可编辑确认），前台渲染 +
  FAQPage JSON-LD（同时拿谷歌富摘要，一鱼两吃）
- **AI 爬虫白名单**：robots.txt 显式 Allow GPTBot、ClaudeBot、PerplexityBot、Google-Extended
- **AI 引流看板**：识别 referrer 中的 chatgpt.com / perplexity.ai / gemini / copilot，
  记录到表，后台仪表盘显示「AI 引擎来访趋势」——这个指标目前几乎没有竞品在看

**表**：`faqs`（metable 多态、locale、q、a）、`ai_visits`（referrer、path、ip、created_at）
**产出**：AI 引擎可见性 + 可量化；FAQ 富摘要提升 CTR

---

## 第二优先级：数据驱动（每个 4~7 天）

### 4. GSC Pulse — 谷歌搜索_console 数据回流

**原理**：没有数据回流的 SEO 是盲打。GSC API 免费给出每个词/页的展现、点击、排名。

- OAuth2 授权站主 GSC 账号（一次性配置引导页）
- 每日队列任务拉取近 28 天 searchAnalytics（按 query/page/国家维度）入表
- 后台仪表盘：
  - **临门一脚榜**：排名 4~15 位、展现 >100 的词 → 一键「让 AI 优化这篇文章」
    跳转写作室并预填目标关键词（与 AutoSeo/AI 写作室打通）
  - **衰退预警**：周环比点击 -30% 的页面 → 内容保鲜清单
  - 新词发现：有展现零点击的长尾词 → 建议开新文
- 多语言分语言统计（8 个子目录分开看）

**表**：`gsc_credentials`、`gsc_daily`（date、page、query、country、clicks、impressions、position）
**产出**：内容策略从拍脑袋变成看数据；是后续所有内容决策的地基

### 5. LeadMagnet — 资料磁铁（邮箱沉淀）

**原理**：B2B 首访 97% 不发询盘。用「下载报价单/规格书」换邮箱是外贸最经典的转化器，
邮件列表还能养长期线索。

- 后台定义磁铁：`2026 阀门价格表 PDF`、`OEM 能力手册` 等（slug + 文件 + 落地页文案 + UTM）
- 公开页 /download/{slug}：表单只要邮箱（可选公司名），提交后：
  - 发签名下载链接邮件（16 小时有效，防直链转发）
  - 落库为线索（复用 Inquiry 模型 source=magnet 或独立 leads 表）+ 飞书通知
  - 队列排 3 天后自动跟进邮件（介绍工程师/案例，mailer 未配则跳过）
- 落地页本身可 SEO（8 语言），磁铁文件每年更新一次即持续引流

**表**：`lead_magnets`（slug、file、title、description、locale）、`magnet_downloads`（email、magnet_id、utm、ip）
**产出**：把 2%~5% 的路过流量变成可反复触达的邮箱资产

---

## 第三优先级：规模化（7~10 天，需流量基础后再上）

### 6. PageForge — 程序化落地页工厂

**原理**：18 行业 × N 产品类目 × 8 语言 × 修饰词（supplier/manufacturer/wholesale/factory）
= 数千个真实有人搜的长尾页（"valve supplier for oil and gas industry"）。

- 组合模板生成页面骨架 → 队列批量调 AI 写独特导语/FAQ（成本控制：每日上限、
  相似度检查去重）
- 每页 canonical + 进 sitemap + LinkWeaver 自动织内链
- 护栏：内容未填充的页 noindex；单语言先试点（es 或 pt 竞争小）验证收录率再放量
- 风险提示：必须保证每页有真实增量内容（AI 生成 + 参数表 + 案例），纯模板拼接
  会被判定 doorway page 惩罚——所以放在第三优先级，等 GSC 数据确认站点信任度后再开

**产出**：自然流量天花板从几百页抬到数千页

### 7. QuoteFlow — 询盘篮 → 正式报价 PDF

- 询盘篮提交后自动生成报价单 PDF（logo、签名有效期、FOB/CIF 模板）邮件回复买家
- 买家收到专业报价的体验远好于"我们已收到您的询盘"——尤其欧美客户
- 后台报价模板可编辑（条款、汇率、有效期）

**产出**：询盘转化率与专业形象双提升

---

## 开发顺序建议

| 阶段 | 插件 | 状态 |
|---|---|---|
| 已完成 v1.2.0 | **LinkWeaver** | ✅ 已上线（后台「增长 → 内链词典」） |
| 已完成 v1.2.0 | **LeadLens** | ✅ 已上线（后台「增长 → 线索雷达」，热线导航红点） |
| 次月 | GEOBoost → GSC Pulse | AI 搜索是蓝海窗口期；GSC 数据地基 |
| 流量起量后 | LeadMagnet → PageForge | 先有流量再谈沉淀与规模化 |
| 按需 | QuoteFlow | 询盘量 >10/天 时值得做 |

## 插件之外的三件免费小事（不需要开发）

1. Google Business Profile + Bing Places 注册（供应商搜索直接展示，权重外链）
2. 每个行业页配套 1 条 YouTube 工厂/产线视频嵌入（停留时长 + 视频搜索入口）
3. GSC 后台手动提交一次全语言 sitemap，配合 IndexNow 加速首批收录

---

# 第二轮提案（v1.2.3 后）

站点现状：刚上线、内容量小、插件生态已成型（内链/评分/GEO/AI 生产齐备）。
当前阶段的核心矛盾是**没有数据回流**和**流量太小**，方案按此排序。

## A. 插件推荐（按投入产出排序）

### A1. TransCoverage 翻译覆盖率看板 ⭐ 最快见效（1~2 天，零 AI 成本）

8 语言运营的实际痛点：AI 翻译是单篇操作，但**没有全局视野**——哪些产品/文章
缺哪种语言、SEO 元数据缺哪种语言，全靠一个个点开看。

- 扫描 Post/Product/Category 的 title/content/excerpt 与 SeoMeta 各语言填充情况
- 后台看板：矩阵视图（内容 × 语言，绿/黄/红），按「缺语言数」排序
- 每行一键「AI 翻译缺失语言」按钮（复用 PostTranslator，产品类复用同逻辑）
- 可选：每周队列扫描 + 飞书摘要（"本周新增 12 篇西语缺口"）
- 表：无新表，纯查询聚合 + 缓存

### A2. GSC Pulse 谷歌数据回流 ⭐ 内容决策地基（4~5 天，需一次 OAuth）

（原路线图第 4 项，提级）没有数据回流的 SEO 是盲打。站点已有内容生产闭环，
缺的就是"什么词该写/该改"的输入。

- OAuth2 授权 GSC → 每日队列拉取近 28 天 searchAnalytics（query/page/国家）
- 临门一脚榜（排名 4~15、展现>100 的词）→ 一键跳 AI 填充预填关键词
- 衰退预警（周环比 -30% 页面）+ 新词发现（有展现零点击）
- 分语言统计（8 个子目录分开看），与 TransCoverage 互补
- 表：`gsc_credentials`、`gsc_daily`

### A3. LeadMagnet 资料磁铁（3~4 天，邮件列表资产）

（原路线图第 5 项）B2B 首访 97% 不发询盘。「下载 2026 价格表 PDF / OEM 能力手册」
换邮箱是外贸最经典的转化器。

- 后台定义磁铁（slug + 文件 + 落地页文案）→ 公开页 /download/{slug}（8 语言可 SEO）
- 提交邮箱 → 签名下载链接邮件（16 小时有效）→ 落库为线索 + 飞书通知
- 可选 3 天自动跟进邮件
- 表：`lead_magnets`、`magnet_downloads`

### A4. TrustBuilder 信任背书（3~4 天，转化 + SEO 双吃）🆕 新增

B2B 买家决策链条里信任信号权重极高，目前站点完全空白：

- 后台维护：客户案例（行业/国家/场景/图）、认证证书、工厂实拍、团队
- 前台 /case-studies 列表 + 详情页（8 语言，进 sitemap，case study 是 SEO 内容金矿）
- 案例页 Organization/Review JSON-LD；产品页/关于页自动注入"相关案例"区块
- 表：`cases`（多语言）、`certifications`

### A5. ImageForge 图片优化（2~3 天，Core Web Vitals）🆕 新增

composer 里没有任何图片处理库，上传原图直接进前台：

- Filament 上传时自动转 WebP + 生成多尺寸（Filament 自带 resizing，缺 WebP）
- 前台 `<img>` 补 srcset/width/height（消 CLS）+ loading="lazy"
- 历史 attachments 批量转换 artisan 命令
- 依赖：intervention/image + php-gd（宝塔已装 gd）

### A6. PageForge 程序化落地页（7~10 天，等站点信任度起来再开）

（原路线图第 6 项，维持押后）18 行业 × 类目 × 修饰词 × 8 语言 = 数千长尾页。
风险：内容不足会被判 doorway page。**建议 GSC Pulse 数据确认收录健康后再启动**。

### A7. QuoteFlow 报价 PDF（按需，询盘 >10/天时）

（原路线图第 7 项，维持按需）询盘篮 → 自动报价单 PDF 邮件回复。

## B. 站点层面优化（不写新插件）

### B1. 页面缓存（性能，收益最大）⭐ 立即可做

访客流量 99% 是匿名 GET，Nginx fastcgi_cache 可把 TTFB 从几百 ms 降到 <50ms：

- 宝塔站点配置加 fastcgi_cache（跳过条件：登录 cookie、`/admin`、POST、
  查询串非空、`Set-Cookie` 响应头存在）
- **注意**：DetectVisitorLocale 的 302 必须排除在缓存外（仅缓存 200）
- 谷歌 CLS/TTFB 直接影响排名，这个比任何插件性价比都高

### B2. 安全加固（1 天内）

- 后台 `/admin` 改为隐蔽路径或加 IP 白名单（宝塔层），robots 已 Disallow
- 管理员账号开两步验证（Filament 有官方插件）
- 登录失败限流确认（Filament 默认有 throttle，确认阈值）

### B3. 运维兜底（半天）

- 宝塔计划任务：每日整站备份（代码+库）到异地（OSS/S3/另一台机）
- 上线 Uptime 监控（UptimeRobot 免费档）+ 飞书 webhook 告警
- `GA4_ID` 填入 .env（已有 gtag 支持）——没有流量数据谈不上优化

### B4. 内容运营节奏（持续）

- 每周 2~3 篇 AI 写作室文章（先 en，发布后 AI 翻译到 es/pt——竞争小）
- 每篇文章走一遍：AI 填充 → SEO 体检 → AI 生成 FAQ → AI 翻译 → 发布（内链自动织）
- 每月看一次 AutoSeo 审计 + GSC Pulse（上线后）调整关键词方向

## C. 推荐执行顺序

| 周 | 动作 | 理由 |
|---|---|---|
| 本周 | B1 页面缓存 + B3 备份/监控/GA4 | 半天~1 天运维，立即受益 |
| 本周 | A1 TransCoverage | 1~2 天，8 语言运营立刻可感知 |
| 下周 | A2 GSC Pulse | 数据地基，之后所有内容决策有依据 |
| 第 3 周 | A3 LeadMagnet | 流量开始进来前备好转化器 |
| 第 4 周 | A4 TrustBuilder | 转化率补强 |
| 流量起量后 | A5 → A6 → A7 | 性能微调与规模化 |
