# 踩坑实录与修复全记录（aples.xyz 上线实战）

> 2026-10-26~27 真机部署全过程遇到的问题、根因与修复。
> 每条都已在代码或手册中固化，重装/新站部署不会再踩。

## 一、环境类（宝塔 / PHP）

### 1.1 扩展缺失
| 问题 | 根因 | 修复 |
|---|---|---|
| 安装向导报 fileinfo 未通过 | 宝塔 PHP 默认不装 fileinfo | PHP 设置 → 安装扩展 |
| fileinfo 编译报 `Cannot find autoconf` | 服务器缺编译工具链 | `apt install -y autoconf automake gcc make` |
| 队列 worker 报 `pcntl_signal() undefined` | pcntl 扩展未装 + 函数被禁 | 装 pcntl 扩展 + 禁用函数列表删除所有 pcntl_* |
| 海外服务器扩展下载失败 | 国内源超时 | 软件商店 → 设置 → 切境外线路 |

### 1.2 禁用函数
 Composer/artisan/storage:link 需要 `putenv proc_open proc_get_status symlink`；队列 worker 需要**全部 pcntl_***。宝塔默认禁了大半——漏删一个对应功能就瘫。

## 二、部署形态类

### 2.1 zip 上传部署的天然缺陷（本次最大教训）
- 新插件（AutoSeo）上线后服务器 **composer.json 没有 PSR-4 映射** → `Class not found` → 整站 500
- **根治**：服务器改 git 部署（.git 移植 + checkout tag），此后 composer install / 更新中心全部可用
- zip 模式装新插件必须手动补映射 + `composer dump-autoload`（已写入手册）

### 2.2 Livewire 生产模式 JS 以 404 状态输出
- 内容正常但状态码 404（Livewire 反探测设计），部分浏览器拒绝执行 → **后台登录按钮无反应**
- 修复：`vendor:publish --tag=livewire:assets` 发布为真实静态文件；v1.0.6 起安装时自动执行

### 2.3 队列 worker 的环境陷阱（两次踩）
- www 用户无 HOME → composer 报 `HOME or COMPOSER_HOME must be set`
- 修复：更新器内自动设置 HOME/COMPOSER_HOME 指向站点内目录（v1.1.3）
- worker 常驻内存持有**旧代码** → 更新后必须 `queue:restart`（已加入更新流程 v1.1.4）
- worker 以 www 运行 → GitHub SSH 密钥要配在 **/home/www/.ssh**（root 的不算），且需要 `~/.ssh/config` 指定 IdentityFile（默认密钥名不匹配 → publickey denied）

## 三、代码类（写代码时踩的）

### 3.1 语言文件
- 手写 JSON/PHP 数组转义出错 → module.json 解析失败（改用 `json_encode` 生成杜绝）
- 批量正则脚本**毁掉 5 个 PHP 文件 + 1 个文档**（shell 双引号吞 `$` 变量）→ 全靠 git 恢复；教训：文件修改一律用 Edit 工具或 PHP 常量拼接
- Laravel 翻译键按点号层级下钻：平铺键 `'services.index'` 无法命中 → 必须嵌套结构
- 同键重复定义后者覆盖前者（index_body 坏值 'r' 静默覆盖正确文案）→ 测试锁定

### 3.2 Filament / Livewire
- 控制器构造器 `middleware()` 在 Laravel 11 移除 → 中间件移到路由定义
- 视图引用不存在组件 `filament::textarea` → 用原生 HTML + fi-input 类
- 页面方法调用必须 `$this->method()`（Livewire 编译上下文）
- 内联 `@unless` 与 Livewire 预处理冲突 → 改三元表达式
- 安装期临时 APP_KEY 每请求随机 → 419 CSRF 失效 → 密钥持久化 `storage/app/install.key`

### 3.3 HTTP / 网络
- 空输入被中间件转 null → 验证规则需 `nullable` 前缀再 `required_unless`
- Http::fake 响应体**不能多包一层数组**（OpenAI 结构直接给 choices 顶层）
- `php artisan optimize` 缓存后 Livewire 路由被冻结 → 更新流程改为不缓存路由（v1.0.3+）
- git checkout 后根目录 safe.directory 拦 root 操作 → `git config --global --add safe.directory`

### 3.4 更新中心自举（跨版本引导问题）
- 更新器由**旧版代码**执行：新版修复对当次更新无效（鸡生蛋）
- 解法：重大修复发版后**手动跨一次**（git checkout + composer install），此后新版更新器上岗，后续全自动
- 已固化的自举能力：composer HOME 设置（v1.1.3）、queue:restart（v1.1.4）、GitHub 结果缓存 30 分钟（v1.1.5，防匿名限流耗尽；该缓存的 30 分钟盲区已在 v1.4.7 修复——5 分钟节流回源 + 「检查更新」按钮，见 docs/release-build.md 第九节）

## 四、运维类

- **站点名填了描述句**（33 字符）→ 全站 40 页标题超长（改一处全站生效，勿在站点名里写句子）
- nginx 伪静态遗漏 → /livewire/update 等 POST 路由 nginx 层 404 → 登录无反应（先查伪静态再怀疑代码）
- 双域名（www / 非 www）Cookie 隔离 → 419；固定主域名 + 301
- 测试与生产 Cookie/域名混用 → 永远用同一域名测试

## 五、经验法则（浓缩版）

1. **文件修改禁用 shell 批量脚本**——Edit 工具或 PHP 常量拼接
2. **git 是救命的**——三次大事故全靠 checkout 恢复；先 commit 再折腾
3. **新插件上线三件套**：上传目录 → composer 映射+dump-autoload → 启用+清缓存
4. **队列 worker = 常驻进程**：代码更新后必须 restart；环境变量要自举
5. **测试锁契约**：每个被实战暴露的坑，写一条测试防回归

## 六、补录：AI 生成与更新中心（v1.4.22~v1.4.26，2026-10-31~11-02）

### 6.1 AI 长文塞进单个 JSON → 解析整包报废 → 两段式生成
- **问题**：AI 写作室/AI 翻译把整篇正文 HTML 塞进 JSON 字符串字段，长文里漏转义一个引号整包报废；被 max_tokens 截断的长 JSON 必然解析失败，页面报「无解析」且原始响应不留痕
- **根因**：单次大 JSON 同时承担转义正确性与输出完整性两个风险，长正文下两个都守不住
- **修复**：改**两段式**——元数据走小 JSON（几乎不会失败）+ 正文裸 HTML 直出（无转义负担，截断时自动补齐未闭合标签）；解析失败自动重试一次，原始响应留档 `storage/app/ai-articles/` 供自查（共享工具 `Modules/AiContentWriter/app/Support/LlmOutput.php`，v1.4.24 写作室 / v1.4.26 翻译）

### 6.2 多语言同步翻译超时 500 → 按语言队列化
- **问题**：文章编辑页「AI 翻译」勾多语言在一个 HTTP 请求里同步跑 5~10 分钟，nginx/PHP-FPM 掐断连接 → Livewire 报「页面加载出错」，翻译半途而废
- **根因**：同步请求承载多语言长任务，超过 Web 服务器超时上限
- **修复**：按语言拆队列任务（`TranslatePostJob`，database 队列）自动保存，调度器每分钟消化一个（`queue:work --stop-when-empty`），单语言失败不拖累其余；`QUEUE_CONNECTION=sync` 时退化为单语言内联并提示（v1.4.26）

### 6.3 GitHub 资产 CDN 被机房拦截 → 双源自动轮换
- **问题**：机房网络拦截 GitHub 某个 CDN 域名，下载安装包三重校验重试 3 次全失败；收到的其实是拦截错误页/JSON 元数据，旧日志只报「SHA-256 不匹配」，看不出根因
- **根因**：只认 GitHub API octet-stream 单一下载源，且失败诊断缺实际响应信息
- **修复**：API（octet-stream）与下载页直链**双源自动轮换**（两个来源最终落不同 CDN 域名，只拦其一也能下成）；失败日志输出实际收到的内容类型与开头摘录，被拦情形一眼可辨（v1.4.23）

### 6.4 Filament 预编译 CSS 不含应用 Tailwind 类 → admin-plugins.css
- **问题**：后台插件页 UI 重构后样式失效——写了 `bg-…` / `grid` 等应用 Tailwind 类全部不生效，表格/卡片排版错乱
- **根因**：Filament 面板的预编译 CSS 只编译面板自身用到的类，**不含应用 Tailwind 编译产物**，视图里写应用类等于裸奔
- **修复**：新增 `public/css/admin-plugins.css` 在 AdminPanelProvider 全局注册承载插件页样式；同步把数据类页面侧边栏独立成「数据与优化」分组（v1.4.22）
