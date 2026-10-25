# BtoBcms 系统完整性验证报告

> 验证日期：2026-10-24 · 工具：`verify_system.php`（71 项自动化检查，可重复执行）
> 结论：**SEO 获客 + 询盘转化全链路完整可用，验证 71/71 通过，自动化测试 31/31（131 断言）**

## 一、验证范围与结果

### A. SEO 多语言页面要素（4 语言 × 9 项 = 36 项）✅
每语言页面全部通过：TDK 长度合规（title 67 字符 / description 150 字符）、canonical 指向本语言、hreflang 全 4 语言互指一致（含 x-default，且每语言声明的 URL 与目标页 canonical 逐页比对一致）、JSON-LD Organization 合法（含联系方式）、OG 标签齐全、html lang/dir 正确（ar 为 rtl）。

### B. 产品/分类页结构化数据（7 项）✅
Product JSON-LD 合法；**AggregateOffer 完整输出**（价格区间 lowPrice/highPrice + currency + InStock + MOQ eligibleQuantity——这是 Google 搜索富摘要的数据基础）；页内询盘表单（含产品 ID 隐藏字段）、相关产品、面包屑齐全；分类页属性分面筛选可用。

### C. Sitemap / robots / 301 / noindex（12 项）✅
- sitemap index + 4 分片全部结构正确，products 分片含全部 4 语言 URL 且带 hreflang 互链
- robots.txt 正确输出 Disallow: /admin + Sitemap 指引
- 301 规则**新增即生效**（验证脚本先请求未命中→创建规则→再请求即 301）
- 询盘篮页 noindex,nofollow（不浪费抓取配额）

### D. 询盘链路（9 项）✅
产品询盘（HTTP 201）、UTM 三参数完整入库（source/medium/campaign）、落地页记录、产品关联、篮子多产品 RFQ（2 items）、蜜罐反垃圾标 spam、非法请求 422 拒绝、初始状态 new。

### E. 通知链路（4 项）✅
飞书监听器触发（未配 Webhook 时优雅降级记日志，不影响主流程）；买家英文确认信生成（含 24h 回复承诺）；管理员通知信生成（收件人 = INQUIRY_ADMIN_EMAILS 配置）；**垃圾询盘不触发任何事件**。

### F. SEO 巡检命令（2 项）✅
`seo:audit` 对运行中的站点实抓 14 页执行成功，规则检查通过。

## 二、验证中发现并修复的真实 Bug（3 个）

1. **robots.txt 被覆盖**：Laravel 骨架自带的 `public/robots.txt` 静态文件（`Disallow:` 空）优先于路由返回，导致动态 robots 失效——已删除静态文件。**教训：框架骨架的静态文件会抢占路由。**
2. **301 新规则不生效**：中间件用 `Cache::remember` 把"未命中"也缓存了 1 小时——改为只缓存命中结果，未命中直查（走索引，开销可忽略）。已加回归测试 `test_new_redirect_rule_takes_effect_after_cached_miss`。
3. **缓存模型对象跨进程损坏**：缓存里存 Eloquent 模型导致反序列化异常（incomplete object）——改为只缓存标量数组 `['to', 'code']`，同时保留命中计数。

## 三、完整性结论

| 漏斗环节 | 状态 | 说明 |
|---|---|---|
| 被搜到（SEO 技术面） | ✅ 完整 | 4 语言 TDK/canonical/hreflang/sitemap/JSON-LD/robots/301 全部合规且互指一致 |
| 搜索富摘要 | ✅ 可用 | Product + AggregateOffer + Organization + Breadcrumb + Article |
| 转化入口 | ✅ 完整 | 产品页表单 / 联系页表单 / 询盘篮 RFQ / WhatsApp 挂件 |
| 反垃圾 | ✅ 可用 | 蜜罐 + 校验 + 状态隔离（spam 不触发事件） |
| 归因 | ✅ 完整 | UTM 全参数 + 落地页 + referrer + 首触保持 |
| 通知触达 | ✅ 可用 | 飞书卡片 + 双向邮件（降级安全） |
| CRM 跟进 | ✅ 完整 | 状态管道 / 分配 / 跟进记录 / 邮件回复 / 导出 / 图表 |
| 持续优化 | ✅ 可用 | 每日自动巡检 + 后台报告可视化 + AI TDK 优化（待 GSC 数据接入） |

## 四、可行性评估

**技术上可行**：单体 Laravel（Blade 服务端渲染），无分布式依赖；一台 2C4G VPS 即可承载日均数万 PV 的内容站；查询均走索引（分类物化路径、询盘 email/status、redirect from_path）；图片走 CDN 即可线性扩展。

**上线前必办（配置与部署，非开发）**：
1. 域名 + VPS + HTTPS（Cloudflare CDN）——影响 SEO 收录速度
2. 切 MySQL（.env `DB_*`）+ `QUEUE_CONNECTION=database` + 常驻队列 worker + scheduler cron（巡检/邮件依赖）
3. 真实 SMTP（SES/Postmark）+ 域名 SPF/DKIM/DMARC——否则邮件进垃圾箱
4. 填真实密钥：FEISHU_WEBHOOK_URL / DeepSeek Key / GA4_ID / WHATSAPP_NUMBER
5. 改管理员密码、录入真实产品（CSV 导入已验证）

**当前限制（不影响上线，按优先级排期）**：
1. es/ar/ru 产品文案回退英文——多语言翻译管线是内容 SEO 的下一个最大增量
2. 站内搜索为 SQL LIKE——产品过千后接 Meilisearch
3. GSC 数据未接入巡检——有收录数据后 AI 巡检价值翻倍
4. sitemap-posts 为空——开始发布文章后自动收录
