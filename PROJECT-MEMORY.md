# 项目记忆（Project Memory）

> 给后续 AI 会话 / 新协作者的项目上下文速查。读完这份 + `docs/release-build.md` 就能开工。
> 通用踩坑史见 `docs/troubleshooting-history.md`（本文只记高频与结构性的事实，不重复故障叙事）。

---

## 一、这是什么项目

**BtoBcms**：面向 B2B 外贸获客的多语言 CMS（内容营销 → 询盘）。

| 事实 | 值 |
|---|---|
| 技术栈 | Laravel 13 + Filament 5 + Livewire v4 + Spatie Translatable + nwidart 模块 |
| 线上站 | https://www.chinasupplier.shop（Dallas 服务器，宝塔 nginx + PHP-FPM） |
| 线上当前版本 | 看后台上「更新中心」，或 GitHub `releases/latest`（发版流程见第五节） |
| 本仓库 | `E:\桌面文件\文件夹\BtoBcms`，remote `github.com/su8023/btobcms`（HTTPS + Windows 凭据管理器） |
| Shell | Git Bash on Windows；**php/composer 不在 PATH**（见下） |
| 语言 | 站点 8 语言（en 默认无前缀，es/ar/ru/pt/fr/de/it 带 `/es/` 前缀）；`site_locales()` / `default_locale()` helpers |
| 版本号 | 唯一出处 `config/version.php`，必须与 git tag 完全一致（如 `v1.4.30`） |

## 二、本机环境（路径要背下来）

| 工具 | 路径 |
|---|---|
| PHP 8.4（主力，跑 artisan/测试/构建） | `E:/dev-tools/php84/php.exe` |
| PHP 8.3（备用，勿混用） | `C:/php-cli/php.exe` |
| Composer | `E:/dev-tools/composer.phar`（构建时 `--composer=` 传入） |
| 本地测试站代码 | `C:/Users/Alion/AppData/Local/Temp/btob-install`（**独立副本，不是 junction**） |
| 本地测试站服务 | `php -S 127.0.0.1:8140 -t public`（常驻进程，重启站不用管它） |
| 本地站管理员 | `tbskhk@gmail.com` / `Admin@2026`（密码曾重置过，登不上就 tinker 重置） |
| 本地站 APP_URL | `install-test.local`（**签名 URL 校验含 host**，curl 验证要带 `-H "Host: install-test.local"`） |

⚠️ **改代码 ≠ 本地站生效**：主仓库改完后必须 `cp` 同步改动文件到 btob-install 对应路径，再
`"E:/dev-tools/php84/php.exe" C:/Users/Alion/AppData/Local/Temp/btob-install/artisan optimize:clear`。
判断 8140 serve 的是谁：往某边 `public/` 放标记文件 curl 一下即可（见 troubleshooting-history）。

### 2.1 Laravel Boost（v1.4.31 起已装）
- `laravel/boost` v2.10.4（dev 依赖）；`boost:install --guidelines --skills --mcp` 已执行。
- **guidelines 落在 `CLAUDE.md` + `AGENTS.md`**（两者内容相同，Boost 自动写入）。原 bootstrap 提示已被替换。
- **skills 落在 `.claude/skills/`**（5 个：infer-conventions / deploying-to-cloud / laravel-best-practices / testing-best-practices / tailwindcss-development）——团队共享，**要入库**。
- **`.mcp.json` 含本机绝对路径**（`E:/dev-tools/php84/php.exe`，因 php 不在 PATH）——**已 gitignore**，逐人不同不入库。
- `boost.json` 是 Boost 的开关清单，入库。

## 三、架构要点（动代码前先读这节）

### 3.1 模块化插件体系
- 15 个模块在 `Modules/`，启停状态在 `modules_statuses.json`（SelfUpdater 更新时会按保留清单合并，旧站点手动关掉的保持关闭）。
- 核心代码引用模块的**门禁惯例**：`class_exists(\Modules\Xxx\...)` 防模块被删；要区分「停用」用 `Module::isEnabled('Xxx')` 包 try/catch（模块被删会抛异常，见 `RecordTranslation::available()`）。
- **统一走 `App\Support\PluginGuard::available($class, $module)`**（v1.4.31 起）：类存在 + 模块启用，一站式判定；模块被删时 `isEnabled` 抛 `ModuleNotFoundException` 会被吞掉返回 false。**短路必须在静态调用之前**——`Foo::class` 是编译期字符串常量不报错，但 `Foo::bar()` 会 autoload 并抛 `Error`。
- 惯例式发现（核心零插件依赖）：`Modules/{Name}/app/Support/PostActions.php::editorActions($page)` → 文章编辑页动作（见 `App\Support\PluginPostActions`）；`PluginPages` 同模式。
- 更新机制：SelfUpdater v2 自包含 zip（代码+vendor），下载双源（GitHub API octet-stream + browser_download_url 兜底，因为 GitHub CDN 屏蔽过 Dallas 服务器 IP）。

### 3.2 Filament 样式限制（高频坑）
- Filament 预编译 CSS **不含**应用层 Tailwind 类：自定义 admin 样式只能写进 `public/css/admin-plugins.css`（经 AdminPanelProvider 注册），页面内用自绘 `.xxx-类` + inline style。
- 色板变量带 shade 后缀（`--primary-600`）；**裸 `var(--primary)` 未定义 → 背景 transparent**。`:root` 别名块已在 admin-plugins.css 里补过一轮。
- 图标用 `heroicon-o-*`；自定义组件（`x-plugin-stat` / `x-plugin-empty` / `.tp-badge--{success,warning,danger,info,gray,primary}`）已存在，优先复用。
- 文档详情：`docs/update-center.md` / v1.4.27-28 提交说明。

### 3.3 多语言翻译体系
- 内容列用 Spatie Translatable（`$translatable` 数组 + JSON 列）；读单语言：`getTranslation($field, $locale, false)`（第三参 false = 不回退）；读整份：`getTranslations($field)`（一次解码，表格列渲染优先用它）。
- slug **不是** translatable（纯字符串）；title/excerpt/content（Post）、name/summary/description（Product/Category）是。
- 前台路由：默认语言无前缀、其余带前缀，路由名带语言前缀（`es.blog.show`）；`localized_route()` helper。
- 前台 `PostController::show` 只查 `published()`——**未发布内容必 404**；草稿预览走签名路由 `/blog/{slug}/preview`（`blog.preview`，3 天有效，noindex 双保险）。
- SEO：`Seo::for($model, 'blog.show', $routeParams)`；布局支持 `noindex => true` 变量。

### 3.4 AI 翻译引擎（v1.4.29 起）
- 引擎：`Modules/AiContentWriter/app/Services/RecordTranslator.php`（抽象基类，两段式：短字段 JSON + 正文裸 HTML；每语言 2 次调用，正文空只 1 次）。
- 子类只写 `$config`：PostTranslator / ProductTranslator / CategoryTranslator（新实体类型加一行映射到 `RecordTranslation::$map`）。
- 队列：`ai-translate` 独立队列；调度器每分钟 `queue:work --stop-when-empty --max-time=50 --queue=default,ai-translate`；`$tries=1`（LLM 按 token 计费不自动重试）。
- 进度：`TranslationProgress`（cache 键 `translation-progress:{type}:{id}`，状态 queued→running→done/failed，终态留 30 分钟）；列表徽章口径在 `RecordTranslation::badge()`（完整绿/缺 N 种琥珀/⟳M 蓝在途）。
- 队列期间人工补译的语言，任务执行时幂等跳过。
- LLM 调用：`LlmManager::chat(messages, purpose:)` 返回文本；`chatResult()` 返回 `['content','raw','finish_reason']`；测试里用 `config('ai.providers.deepseek.key' => 'sk-test-fake')` + `Http::fake()` 序列应答。

### 3.5 测试约定
- 全套：`"E:/dev-tools/php84/php.exe" artisan test`（326+ 例，约 4-5 分钟）；单套 `--filter="XxxTest"`。
- 惯例：`RefreshDatabase` + `$this->seed()` + `actingAs(User::first())`；测试输出是 **phpunit JSON 风格**（result/passed/failed 字段）。
- ⚠️ 断言中文文案前先 `app()->setLocale('zh_CN')`（测试默认 locale 是 en）。
- LLM 相关测试一律 Http::fake，**绝不真调 AI**。
- 后台全页面巡检：`AdminFullSweepTest`；前台冒烟：`SiteSmokeTest`。

## 四、Git / 会话工作约定

- **发版纪律（用户明令）**：批量修复期间只做本地 commit，**不 push 不发版**；用户明确说「提交/发版」才走发版流程。本地 commit 随时可以做。
- commit message 风格：`feat(模块)：一句话主题` + 正文分点（中文）；release 提交 `release vX.Y.Z：主题`。
- 会话恢复：先看 `git log --oneline -10` + `git status` + 本文档，再动手。
- 多 agent 并行有限额风险（Token 计划上限），重活可以主线自己做，但独立调研类任务适合并行 Explore。

## 五、发版流程（五步，详见 docs/release-build.md）

```bash
# 0) 前置：全量测试通过；改动已本地 commit
# 1) 改版本号（唯一一处）
sed -i "s/'version' => 'v1.4.XX'/'version' => 'v1.4.XY'/" config/version.php
git add config/version.php && git commit -m "release v1.4.XY：主题"
# 2) 构建（自包含 zip，约 1-3 分钟）
"E:/dev-tools/php84/php.exe" scripts/build-release.php --composer="E:/dev-tools/composer.phar"
# 3) 双校验（18 项完整性 + 16 项覆盖语义，非 0 退出即失败，别发）
"E:/dev-tools/php84/php.exe" scripts/verify-release.php dist/btobcms-v1.4.XY.zip
"E:/dev-tools/php84/php.exe" scripts/verify-swap.php  dist/btobcms-v1.4.XY.zip   # 较慢，可放后台
# 4) tag + push
git tag -a v1.4.XY -m "v1.4.XY" && git push origin main --tags
# 5) GitHub Release + 上传资产（gh 未安装！见下）
```

**建 Release（无 gh CLI）**：用 git 凭据库里的 token 走 API，token 不落屏（一条命令内完成）：

```bash
TOKEN=$(printf "protocol=https\nhost=github.com\n" | git credential fill | grep "^password=" | cut -d= -f2-)
RID=$(curl -s -X POST -H "Authorization: token $TOKEN" -H "Accept: application/vnd.github+json" \
  https://api.github.com/repos/su8023/btobcms/releases -d @/tmp/rel-notes.json | grep -o '"id": *[0-9]*' | head -1 | grep -o '[0-9]*')
curl -s -X POST -H "Authorization: token $TOKEN" -H "Content-Type: application/zip" \
  --data-binary @dist/btobcms-v1.4.XY.zip \
  "https://uploads.github.com/repos/su8023/btobcms/releases/$RID/assets?name=btobcms-v1.4.XY.zip"
# 匿名验证（等于线上更新中心的视角）：
curl -s https://api.github.com/repos/su8023/btobcms/releases/latest | grep tag_name
```

⚠️ 资产名**必须**是 `btobcms-{tag}.zip`（SelfUpdater 按名精确匹配）。发完后告诉用户：后台 → 更新中心 → 立即更新。

## 六、高频踩坑速查（新发现往这里补）

| 症状 | 根因 / 解法 |
|---|---|
| 页面元素全透明 / 不上色 | 裸 `var(--primary)` 或 Tailwind 类不在编译 CSS → 用 admin-plugins.css 自绘类 + shade 后缀变量 |
| 点了按钮没反应（Livewire） | 浏览器自动化要用原生 `button[type=submit].click()`；合成 click 会被 Livewire 拦 |
| 线上 404 是 nginx 页不是 Laravel 页 | 宝塔 nginx `fastcgi_intercept_errors` 拦了 Laravel 404 —— 修 Laravel 侧根因即可，别去折腾 nginx |
| 签名 URL 明明对却 403 | 签名校验**含 host**；curl 验证带 `-H "Host: <APP_URL>"`，或从同域名页面生成 |
| artisan test 断言中文失败 | 测试 locale 是 en → setUp 里 `app()->setLocale('zh_CN')` |
| tinker --execute 报 parse error | Git Bash 引号问题 → 外层单引号、内层双引号；复杂逻辑写临时 PHP 文件执行 |
| 本地站改完没生效 | btob-install 是独立副本 → cp 同步 + optimize:clear |
| CoverageScanner 改了但看板没变 | 10 分钟指纹缓存；`Cache::forget(app(CoverageScanner::class)->cacheKey())`（TranslateRecordJob 已内置） |
| 迁移/种子在测试里失败 | RefreshDatabase + seed 是标配，别绕过；清种子数据用 `Xxx::query()->delete()`（统计类断言必须） |
| 删了插件后后台 500 | 核心静态调用插件类（`RecordTranslation::available()`）→ 类不存在抛 `Error`。改用 `App\Support\PluginGuard::available($class, $module)` 守卫，**短路必须在调用之前** |
| 测试里改 modules_statuses.json 无效 | `FileActivator` 是容器单例、构造时读盘；且测试中 `Module::find()` 每次重扫会新建 Module → 换掉容器里的 `ActivatorInterface` 实例才生效 |

## 七、重要决策记录（为什么这么做）

- **批量修复攒版本**：用户要求不要每修一个发一版（频繁更新线上有风险），本地攒批、集中发版。
- **两段式翻译**：长 HTML 塞 JSON 会翻车（转义/截断），Call A 只管短字段小 JSON，Call B 裸 HTML 直出 + `LlmOutput::closeTruncatedHtml` 兜底截断。
- **$tries=1 + 幂等跳过**：LLM 按量计费，失败自动重试会烧钱；失败留痕在进度里，用户可见可手动重试。
- **翻译落库前 fresh() 重取**：队列任务跑 1-2 分钟，期间编辑者可能保存过，旧快照回写会静默回滚人工修改。
- **草稿预览用签名 URL 而非登录态放行**：前台无 admin 会话概念；签名 3 天有效可分享给同事审阅，爬虫/无链接访客拿不到。
- **SelfUpdater 自包含 zip**：旧方案依赖服务器 git+composer+常驻 worker，worker 一挂更新卡死；现在服务器只需下载解压覆盖。
- **下载双源**：GitHub CDN 屏蔽过 Dallas 服务器 IP，objects 下载失败时回退 browser_download_url；诊断数据记 content-type。

## 八、关键文件索引

| 主题 | 文件 |
|---|---|
| AI 翻译引擎/队列/进度 | `Modules/AiContentWriter/app/Services/RecordTranslator.php`、`app/Jobs/TranslateRecordJob.php`、`app/Support/{RecordTranslation,TranslationProgress}.php` |
| 覆盖率看板 | `Modules/TransCoverage/app/Services/CoverageScanner.php`（10 分钟指纹缓存）、`app/Filament/Pages/CoveragePage.php` |
| 更新中心 | `Modules/UpdateCenter/app/Services/SelfUpdater.php`；构建/校验 `scripts/build-release.php`、`verify-release.php`、`verify-swap.php` |
| 插件体系 | `app/Filament/Pages/{PluginCenter,PluginSettingsPage}.php`、`app/Support/{PluginPages,PluginPostActions}.php` |
| 自定义 admin 样式 | `public/css/admin-plugins.css` |
| SEO | `app/Support/Seo.php`、`resources/views/partials/seo.blade.php`（支持 noindex） |
| 全部主题文档 | `docs/`（release-build / update-center / plugin-development / deployment / troubleshooting-history …） |
