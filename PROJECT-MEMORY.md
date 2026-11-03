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
| 线上当前版本 | **v1.4.32**（2026-11-03 核查；仓库已到 v1.4.33 → 线上落后一个版本，v1.4.33 的 P0「文章编辑页静默无法保存」线上仍在生效）。看后台上「更新中心」，或 GitHub `releases/latest`（发版流程见第五节） |
| 本仓库 | `/home/bskhk/文件/BtoBcms`，remote `github.com/su8023/btobcms`（HTTPS） |
| Shell | Debian 13 (trixie) + bash；php/composer/node 均在 PATH（见下） |
| 语言 | 站点 8 语言（en 默认无前缀，es/ar/ru/pt/fr/de/it 带 `/es/` 前缀）；`site_locales()` / `default_locale()` helpers |
| 版本号 | 唯一出处 `config/version.php`，必须与 git tag 完全一致（如 `v1.4.30`） |

## 二、本机环境（Debian 13，2026-11-03 重建）

| 工具 | 路径 / 版本 |
|---|---|
| PHP 8.4（主力，跑 artisan/测试/构建） | `/usr/bin/php`，8.4.27；扩展：sqlite3/mbstring/xml/curl/zip/gd/bcmath/intl |
| Composer | `~/.local/bin/composer`，2.11.1（构建时 `--composer="composer"`） |
| Node / npm | `/usr/local/bin/node` v24.9.0 / npm 11.21.0 |
| 本地开发站 | `php artisan serve` → 127.0.0.1:8000（前台 200、/admin 302 登录跳转正常） |
| 数据库 | `database/database.sqlite`（已在，迁移已跑完） |

⚠️ 本仓库已从 Windows 迁到 Linux：历史文档里的 `E:/dev-tools/...` 路径全部失效，
命令一律写 `php artisan ...` / `composer ...`。`.mcp.json` 改成 `"command": "php"`。

### 2.1 Laravel Boost（v1.4.31 起已装）
- `laravel/boost` v2.10.4（dev 依赖）；`boost:install --guidelines --skills --mcp` 已执行。
- **guidelines 落在 `CLAUDE.md` + `AGENTS.md`**（两者内容相同，Boost 自动写入）。原 bootstrap 提示已被替换。
- **skills 落在 `.claude/skills/`**（5 个：infer-conventions / deploying-to-cloud / laravel-best-practices / testing-best-practices / tailwindcss-development）——团队共享，**要入库**。
- **`.mcp.json` 指向本机 php**（Linux 走 PATH 的 `"php"`）——**已 gitignore**，逐人不同不入库。
- `boost.json` 是 Boost 的开关清单，入库。

### 2.2 线上服务器（宝塔 + 共享多站点机）
- 站点目录 `/www/wwwroot/www.chinasupplier.shop`（该机还跑着另外十几个站，别动别人的目录）。
- **PHP CLI 路径 `/www/server/php/84/bin/php`**（宝塔软件商店装的 8.4）。`/usr/local/php/bin/php` **不存在**——写脚本/计划任务时别用这个。
- **调度器（cron）**：宝塔 → 计划任务 → Shell 脚本 → 每 N 分钟（1）→
  ```bash
  cd /www/wwwroot/www.chinasupplier.shop && /www/server/php/84/bin/php artisan schedule:run >> /dev/null 2>&1
  ```
  **v1.4.32 之前一直漏配** → 队列类功能（AI 翻译 / AutoSeo / 询盘通知 / GSC 拉数 / 每日巡检）全部静默瘫痪。队列相关问题先查这条。
- 调度器消化队列靠 `queue:work --stop-when-empty --max-time=50 --queue=default,ai-translate`（routes/console.php 里定义），每分钟一轮、每轮最多 50 秒 → **约 1 分钟消化 1 个翻译任务**。
- 线上是**私有仓库**，匿名 `api.github.com/repos/su8023/btobcms` 返回 404 是正常的，验证发布要用带 token 的请求。

### 2.3 CDP 浏览器自动化（线上验证专用）
- 工具：Windows 侧的 `E:/dev-tools/cdp.php`（本项目外，独立工具）——**Linux 迁移后尚未迁移该工具**，需要时再装。
- 命令：`nav <url>` / `eval "<js>"` / `shot <out.png>` / `console [N]` / `tabs` / `new` / `close`。
- **`eval` 的 URL 参数不要用嵌套引号**（Windows shell + PHP 双层转义会炸）→ 用 `eval` 的多行形式或写临时 JS 文件。
- 页面里 `Livewire.find(wireId)` 可直接调组件方法（`set()`/`call('save')`），但 **Filament v5 表单状态是数组式**，路径写成 `data.status` 会静默无效（真实路径 `data.data.0.status`）。
- 抓 Livewire 请求：`window.fetch` 打补丁拦 `/livewire/*`，读 body 的 `memo.errors` → 校验错误就在里面。
- 多个标签页时工具会优先选 URL 含 `chinasupplier` 的那个（否则会选中新标签页）。

## 三、架构要点（动代码前先读这节）

### 3.1 模块化插件体系
- 15 个模块在 `Modules/`，启停状态在 `modules_statuses.json`（SelfUpdater 更新时会按保留清单合并，旧站点手动关掉的保持关闭）。
- 核心代码引用模块的**门禁惯例**：`class_exists(\Modules\Xxx\...)` 防模块被删；要区分「停用」用 `Module::isEnabled('Xxx')` 包 try/catch（模块被删会抛异常，见 `RecordTranslation::available()`）。
- **统一走 `App\Support\PluginGuard::available($class, $module)`**（v1.4.31 起）：类存在 + 模块启用，一站式判定；模块被删时 `isEnabled` 抛 `ModuleNotFoundException` 会被吞掉返回 false。**短路必须在静态调用之前**——`Foo::class` 是编译期字符串常量不报错，但 `Foo::bar()` 会 autoload 并抛 `Error`。**`class_exists` 本身也要包 try/catch**（v1.4.33 补）：dump-autoload 失败后删插件 → Composer stale classmap 仍持有已删类条目 → `class_exists` 触发 include 缺失文件 → warning 被 Laravel 错误处理器转成 `ErrorException`（实测）。
- **查插件表前必须 `Schema::hasTable()`**（v1.4.33 补）：模块迁移只在「该插件的 Provider 被加载 + migrate 跑过」时建表；全新库若插件处于停用状态，表不存在但类文件在盘上 → 渲染路径查询直接 QueryException → **全站 500，连 404 页都挂**（404 页也要渲染全站插槽）。
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
- 全套：`php artisan test`（326+ 例，约 4-5 分钟）；单套 `--filter="XxxTest"`。
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
php scripts/build-release.php --composer="composer"
# 3) 双校验（18 项完整性 + 16 项覆盖语义，非 0 退出即失败，别发）
php scripts/verify-release.php dist/btobcms-v1.4.XY.zip
php scripts/verify-swap.php  dist/btobcms-v1.4.XY.zip   # 较慢，可放后台
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

⚠️ **仓库是私有的**：`curl https://api.github.com/repos/su8023/btobcms/releases/latest` 匿名查会返回 404（不是发布失败）。验证要用带 token 的请求。

⚠️ **构建不能并发跑**：`scripts/build-release.php` 用同一个 staging 目录，两个进程同时跑会互相删/写 → 报「构建结果缺少必需文件：artisan」。一次只跑一个。

⚠️ **`PluginUploadTest` 曾因 `composer dump-autoload` 跑 `filament:upgrade` 慢钩子而间歇失败**：已改用 `--no-scripts`（刷 PSR-4 映射不需要那些脚本），测试耗时 10s → 6.9s 且稳定。

## 六、高频踩坑速查（新发现往这里补）

| 症状 | 根因 / 解法 |
|---|---|
| 页面元素全透明 / 不上色 | 裸 `var(--primary)` 或 Tailwind 类不在编译 CSS → 用 admin-plugins.css 自绘类 + shade 后缀变量 |
| 点了按钮没反应（Livewire） | 浏览器自动化要用原生 `button[type=submit].click()`；合成 click 会被 Livewire 拦 |
| 线上 404 是 nginx 页不是 Laravel 页 | 宝塔 nginx `fastcgi_intercept_errors` 拦了 Laravel 404 —— 修 Laravel 侧根因即可，别去折腾 nginx |
| 签名 URL 明明对却 403 | 签名校验**含 host**；curl 验证带 `-H "Host: <APP_URL>"`，或从同域名页面生成 |
| artisan test 断言中文失败 | 测试 locale 是 en → setUp 里 `app()->setLocale('zh_CN')` |
| tinker --execute 报 parse error | Git Bash 引号问题 → 外层单引号、内层双引号；复杂逻辑写临时 PHP 文件执行 |
| 本地站改完没生效 | 迁移前是 btob-install 独立副本需 cp 同步；Linux 下直接改本仓库即生效 |
| CoverageScanner 改了但看板没变 | 10 分钟指纹缓存；`Cache::forget(app(CoverageScanner::class)->cacheKey())`（TranslateRecordJob 已内置） |
| 迁移/种子在测试里失败 | RefreshDatabase + seed 是标配，别绕过；清种子数据用 `Xxx::query()->delete()`（统计类断言必须） |
| 删了插件后后台 500 | 核心静态调用插件类（`RecordTranslation::available()`）→ 类不存在抛 `Error`。改用 `App\Support\PluginGuard::available($class, $module)` 守卫，**短路必须在调用之前** |
| 测试里改 modules_statuses.json 无效 | `FileActivator` 是容器单例、构造时读盘；且测试中 `Module::find()` 每次重扫会新建 Module → 换掉容器里的 `ActivatorInterface` 实例才生效 |
| 插件停用后前台全站 500 | 全新库若插件停用再 `migrate`，模块迁移没跑 → 数据表没建；但类文件在盘上（`class_exists` 为 true）→ 渲染路径查询直接 QueryException。**连 404 页都挂**（404 页也要渲染全站插槽）。查询插件表前必须 `Schema::hasTable()` |
| module:migrate 报 include 失败 | Windows Git Bash 下中文路径被转义 → 用 `artisan migrate --path=Modules/Xxx/database/migrations --force` 绕过（Linux 无此问题） |
| 文章编辑页点「保存」没反应、什么都没存 | **SEO 关键词超 255 字符**（AutoSeo 写入时未截断）→ 后台 SEO 字段 `maxLength(255)` 校验失败，而错误藏在**默认折叠的 SEO 区域**→ 整个表单校验失败，连「状态」都存不进去。已修：AutoSeo 截断 + SEO 区域默认展开 + 数据迁移修存量 |
| 排查「Livewire 保存没反应」 | 抓 Livewire 请求快照看 `memo.errors`：CDP 里 patch `window.fetch` 拦 `/livewire/*` 请求，读 body 里的 `memo.errors`——校验错误就在那儿（Filament v5 表单状态是数组式，错误键形如 `data.data.seo.keywords.en`） |
| 改完 301 规则线上仍跳旧地址 | `RedirectRequests` 中间件缓存命中结果 1 小时 → Redirect 模型加 `saved`/`deleted` 钩子立即清缓存（含 from_path 变更时的旧路径、负缓存） |
| `composer dump-autoload` 慢/超时 | post-autoload-dump 钩子（`package:discover` + `filament:upgrade` 发布大量资源）很慢 → 刷 PSR-4 映射用 `--no-scripts` 即可（插件 zip 不含 composer 包） |
| 队列任务静默失败但本地测试全绿 | 构造器用 `$this->x = ...` 老式赋值但**未声明属性** → PHP 8.2 动态属性不入对象 schema → database 队列 serialize 时丢失 → 反序列化后 `Undefined property`。本地 `QUEUE_CONNECTION=sync` 同进程执行、`Queue::fake()` 不执行 handle，两种都测不到 → 必须真走 serialize→unserialize 往返 |
| git push 每次弹 GitHub 授权窗 | GCM 没持久化凭据 → `git config --global credential.https://github.com.provider github` + `credential.store true`，再跑一次 `git credential-manager github login` 完成 OAuth 即永久缓存 |
| Filament 表单字段值改了存不进去 | 多语言字段用 `wire:model`（deferred），DOM 改动要 `dispatchEvent(new Event('input', {bubbles:true}))`；select 要 `change` 事件。别用 `Livewire.find(id).set()`——Filament v5 的表单状态路径是数组式（`data.data.0.field`），写错路径会静默无效 |
| 同站两个品牌名（首页新名、模型页旧名） | 站点名有两个来源：`config('site.name')`（env，默认 BtoBcms）与 `site_setting('site.name')`（后台可改）。混用 → 首页标题带后台名、产品/分类/文章页标题带 env 默认名，JSON-LD brand/author 同理。**全站一律用 `site_name()` helper**（后台设置优先、config 兜底） |
| meta description 变成字面量 `<p></p>` | 分类 description 是 RichEditor，空内容存的是 `<p></p>`；`Seo::for()` 把它当正文直接输出 → Google 抓到标签不是文案。摘要统一转纯文本（`Seo::plainText()`：只剥真标签 `</?[a-z]...>`，不用 strip_tags——后者会吃掉正文的 `MOQ<100`） |
| 英文（默认语言）缺 SEO 元数据 | AI 翻译只翻「非源语言」，源语言（en）的 SEO title/description 不会自动生成——分类/产品的 en SEO 要人工填或走 AutoSeo（AutoSeo 只在 `PostPublished` 事件上跑，只覆盖文章）。翻译覆盖率看板「缺 SEO」列全为 1 就是这个原因 |
| 内链词典有词但「已织入」= 0 | 织入只挂在 `PostPublished` 事件上：词典是文章发布**之后**加的 → 旧文章不会被补织。运营点内链词典页右上「全量回织」（幂等，不重复织） |
| 后台配置项读出来是空字符串 | Livewire 多语言/插件设置面板的值要读 `input` 的 `value` 属性，但 `offsetParent` 过滤会漏掉折叠区的字段（值仍在 DOM 里）。逐个 `input` 读，别只筛可见 |

## 六之二、线上排查手法（2026-11-03 建立）

| 场景 | 手法 |
|---|---|
| 登录线上后台 | 本机 Chrome 带 `--remote-debugging-port=9222`（`~/apps/start-chrome-debug.sh`），配 `~/cdp-control/browser.mjs`（`node browser.mjs open/eval/tabs`）或自写 playwright-core 脚本 `connectOverCDP('http://127.0.0.1:9222')` —— 用**真实 profile**，会话能留在浏览器里 |
| 线上账号 | `tbskhk@gmail.com`（密码问用户，不要写进仓库/文档） |
| 巡检线上所有后台页 | 逐个 `goto('/admin/...')` + 断言 `status===200` 且页面文本不含「页面加载出错/500/Whoops」——Filament 错误页仍是 200，只看状态码会漏 |
| ⚠️ 线上调试禁止写操作 | POST 询盘 API 会**真实落库**（还可能触发邮件/飞书推送）。测试完必须到后台删除，否则污染真实线索数据。只读巡检用 GET + DOM 断言 |
| 后台删除记录 | 行内最后一个 `<button>` 是「删除」→ 点击后弹确认框 → 弹窗内可见的「删除」按钮才是确认（页面里多个同名按钮，用 `offsetParent!==null` 筛可见的，取最后一个） |
| 核对 sitemap 与路由 | sitemap 里的 `<loc>` 逐个 curl 验 200。注意 `/cases` 不是真实路由（真实是 `/case-studies`）、`/basket` 也不是（是 `/inquiry-basket`）——探错 URL 不等于 bug |

## 七、重要决策记录（为什么这么做）

- **批量修复攒版本**：用户要求不要每修一个发一版（频繁更新线上有风险），本地攒批、集中发版。
- **两段式翻译**：长 HTML 塞 JSON 会翻车（转义/截断），Call A 只管短字段小 JSON，Call B 裸 HTML 直出 + `LlmOutput::closeTruncatedHtml` 兜底截断。
- **$tries=1 + 幂等跳过**：LLM 按量计费，失败自动重试会烧钱；失败留痕在进度里，用户可见可手动重试。
- **翻译落库前 fresh() 重取**：队列任务跑 1-2 分钟，期间编辑者可能保存过，旧快照回写会静默回滚人工修改。
- **草稿预览用签名 URL 而非登录态放行**：前台无 admin 会话概念；签名 3 天有效可分享给同事审阅，爬虫/无链接访客拿不到。
- **SelfUpdater 自包含 zip**：旧方案依赖服务器 git+composer+常驻 worker，worker 一挂更新卡死；现在服务器只需下载解压覆盖。
- **下载双源**：GitHub CDN 屏蔽过 Dallas 服务器 IP，objects 下载失败时回退 browser_download_url；诊断数据记 content-type。
- **AI 写入的字段必须与后台表单约束同口径**（v1.4.33）：AutoSeo 把 LLM 生成的 SEO 关键词原样写库（290 字符），而后台字段限 255 → 之后每次编辑都校验失败、且错误藏在折叠区 → 文章彻底不可编辑。**写 AI 产出时要按表单上限截断**。
- **表单校验错误必须可见**（v1.4.33）：SEO 区块从「默认折叠」改为「默认展开」（仍可折叠）——折叠状态下的错误提示运营看不到，只会以为系统坏了。
- **线上真实基线数据（v1.4.33 铺的）**：9 分类（3 根 6 子）、6 产品（闸阀/球阀/离心泵/潜水泵/弯头/法兰，含价格+MOQ）、3 客户案例、3 认证证书、6 询盘（不同画像：德国工业/英国/阿联酋/巴西/免费邮箱散单）。全部 8 语言齐备。

## 八、关键文件索引

| 主题 | 文件 |
|---|---|
| AI 翻译引擎/队列/进度 | `Modules/AiContentWriter/app/Services/RecordTranslator.php`、`app/Jobs/TranslateRecordJob.php`、`app/Support/{RecordTranslation,TranslationProgress}.php` |
| **插件门禁（核心↔插件唯一入口）** | `app/Support/PluginGuard.php`（类存在 + 模块启用，异常全吞） |
| 覆盖率看板 | `Modules/TransCoverage/app/Services/CoverageScanner.php`（10 分钟指纹缓存）、`app/Filament/Pages/CoveragePage.php` |
| 更新中心 | `Modules/UpdateCenter/app/Services/SelfUpdater.php`；构建/校验 `scripts/build-release.php`、`verify-release.php`、`verify-swap.php` |
| 插件体系 | `app/Filament/Pages/{PluginCenter,PluginSettingsPage}.php`、`app/Support/{PluginPages,PluginPostActions}.php`、`app/Services/PluginUploader.php` |
| **301 规则 + 缓存失效** | `app/Models/Redirect.php`（saved/deleted 钩子清缓存）、`app/Http/Middleware/RedirectRequests.php` |
| **多语言表单字段（SEO 区）** | `app/Filament/Support/TranslatableFields.php`（SEO 区默认展开：校验错误必须可见） |
| 自定义 admin 样式 | `public/css/admin-plugins.css` |
| SEO | `app/Support/Seo.php`、`resources/views/partials/seo.blade.php`（支持 noindex） |
| **队列序列化回归测试** | `tests/Feature/QueueJobSerializationTest.php`（属性必须声明，否则线上静默失败） |
| 全部主题文档 | `docs/`（release-build / update-center / plugin-development / deployment / troubleshooting-history …） |
