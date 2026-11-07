# MailNotifier 通知邮件状态（2026-11-07 检查）

模块代码未做任何修改，仅记录现状。要让管理员通知邮件真正送达，需要人工补齐以下两项。

## 现状

| 项 | 当前值 | 来源 |
|---|---|---|
| `MAIL_MAILER` | `log` | `.env` 第 55 行 |
| `MAIL_FROM_ADDRESS` | `hello@example.com` | `.env` 第 61 行（占位值，非真实邮箱） |
| `MAIL_HOST` / `MAIL_PORT` | `127.0.0.1` / `2525`（无本地 SMTP 在监听） | `.env` 第 57–58 行 |
| `INQUIRY_ADMIN_EMAILS` | 未设置（`env()` 返回 null） | `.env` 中不存在该键 |
| `plugin.MailNotifier` 设置行 | **不存在**（`settings` 表中 0 行） | `Setting::get('plugin.MailNotifier')` → `NULL` |
| `plugin_setting('mail-notifier','admin_emails')` | `''`（空串） | DB 空 → 回落到 `config('mail-notifier.admin_emails')` → `env('INQUIRY_ADMIN_EMAILS','')` |

## 影响

1. `MAIL_MAILER=log`：邮件只写进 `storage/logs/laravel.log`，不外发。
2. `admin_emails` 为空：`Modules/MailNotifier/app/Listeners/SendInquiryEmails.php:75-81`
   直接 `return`，并记一条日志「未配置管理员邮箱，跳过管理员通知邮件」——管理员通知邮件被跳过。
3. `Modules/MailNotifier/app/Mail/InquiryConfirmation.php:22-27`：`$replyTo` 取不到，
   买家确认邮件没有 Reply-To 管理员邮箱。

## 需要人工决定/配置（不要臆造）

- 真实 SMTP 主机、端口、账号、密码（当前 `127.0.0.1:2525` 无服务在监听）
- 真实的发件地址（替换 `hello@example.com` 占位值）
- 管理员收件邮箱列表：在「插件中心 → MailNotifier → 设置 → admin_emails」填逗号分隔的真实邮箱，
  或设 `INQUIRY_ADMIN_EMAILS` 环境变量。
- `MAIL_MAILER` 改为实际 mailer（如 `smtp`）。

以上任一项填好后，`mail:test` 命令可用于自检。
