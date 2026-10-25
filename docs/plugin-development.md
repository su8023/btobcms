# BtoBcms 插件开发指南

> 本文基于仓库中的三个真实插件（`FeishuNotifier` / `AiSeoAuditor` / `AiContentWriter`）编写，所有代码片段均可在 `Modules/` 目录下找到对应实现。

---

## 1. 架构总览

核心只做五大件：**目录（分类/产品/属性）、内容（文章）、询盘（CRM）、SEO（TDK/sitemap/301）、多语言**。其余一切能力都是插件。

插件 = 一个独立的 [nwidart/laravel-modules](https://nwidart.github.io/laravel-modules) 模块，拥有自己的目录、路由、配置、迁移、命令与事件监听，可独立启用/停用，**不修改任何核心代码**。

```
app/…                          核心（五大件）
Modules/FeishuNotifier/        插件：飞书通知
Modules/AiSeoAuditor/          插件：AI SEO 巡检
Modules/AiContentWriter/       插件：AI 内容工厂
modules_statuses.json          插件启停状态
```

---

## 2. 快速开始：创建一个新插件

```bash
php artisan module:make MyPlugin
```

生成的骨架在 `Modules/MyPlugin/`。然后做两件事：

**① 在根 `composer.json` 的 `autoload.psr-4` 中注册命名空间**（v13 模块的类在模块的 `app/` 子目录下）：

```json
"Modules\\MyPlugin\\": "Modules/MyPlugin/app/",
"Modules\\MyPlugin\\Database\\Factories\\": "Modules/MyPlugin/database/factories/",
"Modules\\MyPlugin\\Database\\Seeders\\": "Modules/MyPlugin/database/seeders/",
```

**② 刷新自动加载并确认**：

```bash
composer dump-autoload
php artisan module:list        # 应显示 [Enabled] MyPlugin
```

> 参照根 `composer.json` 中 FeishuNotifier 等三个模块的现有写法照抄即可。

---

## 2.5 插件中心（类别、设置页、上传安装）

后台「插件中心」（导航 → 插件 → 插件中心）按类别分组展示全部插件，并支持界面启停。
在 `module.json` 中声明 `"category"` 与 `"version"` 即可归类：

```json
{
    "name": "MyPlugin",
    "description": "一句话说明插件做什么",
    "version": "1.0.0",
    "category": "notification"
}
```

**类别取值**（对应核心模块的扩展方向，选择最贴近的一项）：

| category | 含义 | 典型扩展点 | 现有示例 |
|---|---|---|---|
| `notification` | 通知触达 | 监听 `InquiryCreated` / `SeoAuditFinished` 等事件，推送 IM 或邮件 | feishu-notifier、mail-notifier |
| `ai` | AI 智能 | 调用 `LlmManager`，队列化批处理，产出落 draft；支持调度自主运行 | ai-seo-auditor、ai-content-writer |
| `service` | 客服 | 前台 Slots（`floating` / `head`）注入挂件 | whatsapp-chat |
| `marketing` | 营销转化 | 事件监听 + 前台插槽 + 队列（EDM、再营销、弹窗） | 待开发 |
| `seo` | SEO 增强 | 监听内容事件、扩展 sitemap/结构化数据 | 待开发 |
| `integration` | 外部集成 | 事件监听 + 出站 API（ERP/CRM/物流） | 待开发 |
| `general` | 通用 | 未明确归类 | — |

**独立设置页**：每个声明了 `Settings/PluginSettings` 的插件都有专属设置页
`/admin/plugins/{Name}/settings`（插件中心卡片 → ⚙ 设置 链入），
保存后插件运行时立即读取（`plugin_setting()`）。字段标签经
`admin.plugins.fields.{configPrefix}.{key}` 双语化。详见 §4.3。

**上传安装**：插件中心右上角「上传安装插件」支持直接上传插件 ZIP 包：
服务（`App\Services\PluginUploader`）会做安全校验（拒绝路径穿越/绝对路径/盘符）、
解析 module.json、解压到 `Modules/{Name}`、注册 PSR-4、写入启用状态并刷新 autoload。
CLI 等价命令：`php artisan plugins:upload {zip}`。
ZIP 结构要求：`module.json` 在包根或唯一一级子目录内，`name` 匹配
`/^[A-Z][A-Za-z0-9]+$/`，`providers` 数组非空（Provider 须定义 `$name` 与 `$nameLower` 属性）。
插件已存在时拒绝覆盖（先停用并删除旧目录再上传）。

启停操作写 `modules_statuses.json`，从下一个请求起生效；
注意停用启用了 `AiSeoAuditor` 会同时隐藏后台「SEO 巡检报告」页（该页面按插件启用状态条件注册）。

---

## 3. 插件目录结构（对照 FeishuNotifier 实例）

```
Modules/FeishuNotifier/
├── module.json                 # 模块清单：名称、providers
├── composer.json               # 模块自己的 PSR-4 映射（供参考，实际以根 composer.json 为准）
├── config/
│   └── config.php              # 插件配置（读取 .env，后台可配化的入口）
├── app/
│   ├── Providers/
│   │   ├── FeishuNotifierServiceProvider.php   # 主 Provider：注册命令、事件监听、合并配置
│   │   ├── EventServiceProvider.php
│   │   └── RouteServiceProvider.php            # 注册模块 routes/
│   ├── Console/Commands/
│   │   └── FeishuTestCommand.php               # php artisan feishu:test
│   ├── Listeners/
│   │   ├── SendInquiryNotification.php         # 监听 InquiryCreated
│   │   └── SendAuditReport.php                 # 监听 SeoAuditFinished
│   └── Support/
│       └── FeishuClient.php                    # 飞书 API 客户端
├── routes/
│   ├── web.php
│   └── api.php
├── database/
│   ├── migrations/             # 插件自己的表
│   └── seeders/
└── resources/views/            # 插件视图（如需后台/前台页面）
```

---

## 4. 四个扩展点

### 4.1 事件监听（最常用）

**核心事件清单：**

| 事件类 | 载荷 | 触发时机 |
|---|---|---|
| `App\Events\InquiryCreated` | `$event->inquiry`（含客户信息/UTM/落地页/关联产品） | 新询盘入库且非垃圾（蜜罐拦截的不触发） |
| `Modules\AiSeoAuditor\Events\SeoAuditFinished` | `$baseUrl / $pagesChecked / $issueCount / $summary / $reportPath` | 每次巡检完成 |

**注册方式**（在模块主 Provider 的 `boot()` 中，照抄 `FeishuNotifierServiceProvider`）：

```php
use App\Events\InquiryCreated;
use Illuminate\Support\Facades\Event;

public function boot(): void
{
    parent::boot();

    $this->mergeConfigFrom(__DIR__.'/../../config/config.php', 'my-plugin');

    Event::listen(InquiryCreated::class, SendInquiryNotification::class);
}
```

**监听器**（需要异步时实现 `ShouldQueue`，记得队列连接默认是 `sync`，生产切 `database`/`redis`）：

```php
class SendInquiryNotification implements ShouldQueue
{
    public function handle(InquiryCreated $event): void
    {
        $inquiry = $event->inquiry;
        // … 推送/写库/调外部 API
    }
}
```

> 设计约定：监听器内的外部调用失败**不得抛出异常影响主流程**——参考 `FeishuClient::sendCard()` 的做法：未配置时记日志返回 false，请求异常时捕获记日志。

### 4.2 控制台命令与调度

在 Provider 中声明命令类，并可用 `configureSchedules()` 挂到调度器（参考 `AiSeoAuditorServiceProvider` 的每日 06:00 巡检）：

```php
protected array $commands = [MyCommand::class];

protected function configureSchedules(Schedule $schedule): void
{
    $schedule->command('myplugin:run')->dailyAt('06:00');
}
```

命令本身是标准 Laravel 命令，写在 `app/Console/Commands/`，签名如 `myplugin:run {--limit=100}`。参考：`seo:audit`（含选项参数、进度输出、表格汇总、JSON 报告落盘 `storage/app/`）。

### 4.3 配置项（后台可视化设置）

插件有两层配置来源，**后台保存的数据库设置优先于 .env**：

1. **后台设置面板**：在 `Modules/{Name}/app/Settings/PluginSettings.php` 声明字段，插件中心自动渲染表单并保存到 `settings` 表（键 `plugin.{ModuleName}`），保存后插件立即读取新值。
2. **.env/config**：`config/config.php` 作为默认值兜底（未在后台保存时生效）。

字段声明格式（key/label/type 必填）：

```php
public static function fields(): array
{
    return [
        ['key' => 'enabled', 'label' => 'Enabled', 'type' => 'boolean'],
        ['key' => 'webhook', 'label' => 'Webhook URL', 'type' => 'text', 'required' => true],
        ['key' => 'limit', 'label' => 'Max items', 'type' => 'number'],
        ['key' => 'lang', 'label' => 'Language', 'type' => 'select', 'options' => ['en' => 'English', 'es' => 'Español']],
        ['key' => 'note', 'label' => 'Note', 'type' => 'textarea'],
        ['key' => 'secret', 'label' => 'Secret', 'type' => 'password'],
    ];
}

public static function configPrefix(): string  // 对应 config/config.php 的前缀
{
    return 'my-plugin';
}
```

**读取设置一律用 `plugin_setting()` 辅助函数**（数据库 → config → 默认值）：

```php
$webhook = plugin_setting('my-plugin', 'webhook', '');
```

**标签双语化**：字段标签优先取语言键 `admin.plugins.fields.{configPrefix}.{key}`（en/zh_CN 两份），
不存在时回退到 schema 里声明的英文原文。中文界面下设置面板自动全中文。

> 注意：配置前缀若对应**连续大写**的模块名（如 WhatsAppChat），需在 `app/Support/helpers.php` 的 `$moduleMap` 中补一行映射。
> 设置面板类型支持：text / textarea / password / number / boolean / select（options 数组）。

### 4.4 路由与视图

模块的 `routes/web.php`、`routes/api.php` 由 `RouteServiceProvider` 自动注册（默认带 `my-plugin` URL 前缀），视图放 `resources/views/`，用 `view('myplugin::index')` 引用。

---

## 5. 实战走读：询盘 → 飞书群通知的完整链路

1. **买家提交** `POST /api/inquiries`（核心 `Api\InquiryController`）→ 校验、蜜罐检测、UTM/落地页归因入库。
2. **核心分发事件**：`InquiryCreated::dispatch($inquiry)`（垃圾询盘不分发）。
3. **插件监听**：`FeishuNotifierServiceProvider` 在 `boot()` 中把事件绑到 `SendInquiryNotification`（`ShouldQueue` 异步执行）。
4. **拼装消息**：监听器从 `$event->inquiry` 取客户/国家/来源/UTM/落地页，调用 `FeishuClient::sendCard()` 发飞书卡片（标题+Markdown 正文+「打开后台」按钮）。
5. **优雅降级**：`.env` 未配 `FEISHU_WEBHOOK_URL` 时只记日志不发请求，核心流程零感知。

本地验证：配置 Webhook 后执行 `php artisan feishu:test` 发测试卡片。

---

## 6. AI 插件开发规范

核心提供了统一的 LLM 底座（`app/Services/Ai/LlmManager.php`），插件**不要自己接 HTTP**：

```php
$answer = $llm->chat(
    messages: [
        ['role' => 'system', 'content' => '…'],
        ['role' => 'user', 'content' => '…'],
    ],
    purpose: 'article.generate',   // 计量用途标识（入 ai_calls 表）
    tier: 'chat',                  // chat / cheap（见 config/ai.php）
);
```

- **可切换供应商**：`config/ai.php` 用 OpenAI 兼容协议定义 DeepSeek/GLM/OpenAI，`.env` 改 `AI_CHAT_PROVIDER` 即切换，模型只改 `*_MODEL`。
- **成本可审计**：每次调用的 token 用量、状态、错误都写入 `ai_calls` 表。
- **铁律**：AI 产出一律落 **draft 状态**，人工审核后才发布（参考 `ai:article` 命令 → `Post::STATUS_DRAFT`）。低质内容直接上线会伤整站 SEO。
- **批量任务**用 cheap 档位（打分/分类），**生成任务**用 chat 档位。

---

## 7. 插件生命周期

```bash
php artisan module:list                 # 查看全部模块与状态
php artisan module:disable MyPlugin     # 停用（不删代码）
php artisan module:enable MyPlugin      # 启用
php artisan module:migrate MyPlugin     # 执行该插件的迁移
```

启停状态存在根目录 `modules_statuses.json`（可提交到 git，保证各环境一致）。

**升级注意**：新增类后要 `composer dump-autoload`；新增事件监听在 Provider `boot()` 注册即可即时生效；新增数据库结构走 `database/migrations/` + `module:migrate`。

---

## 8. 编写规范

1. **不改核心**：插件需求一律通过事件/命令/路由/配置实现。如果核心缺少必要的事件，先给核心加事件（保持向后兼容），再在插件里监听。
2. **事件命名用过去式**：`InquiryCreated`、`SeoAuditFinished`、未来的 `ArticlePublished`、`InquiryAssigned`。
3. **配置键 = 插件别名前缀**，`.env` 变量大写：`MYPLUGIN_*`。
4. **外部调用必须容错**：失败记日志、返回 false，绝不让插件故障影响询盘主链路。
5. **监听器默认 `ShouldQueue`**，避免拖慢用户请求。
6. 模块内命名空间统一 `Modules\{Name}\…`，目录名用大驼峰。

---

## 9. 规划中的扩展点（尚未实现，欢迎按此方向贡献）

- **前台插槽（Slots）**：核心 Blade 布局将暴露 `product_sidebar`、`inquiry_form_after`、`footer` 等插槽位，插件通过 Provider 注册视图片段注入（客户挂件、信任徽章等场景）。
- **后台菜单注入**：`module.json` 声明导航项，Filament 面板自动挂载插件的设置页。
- **出站 Webhook**：核心事件同时支持转发到外部 HTTP 端点（对接 Zapier / 企业自有系统）。
