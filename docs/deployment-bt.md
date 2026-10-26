# BtoBcms 宝塔面板部署手册

> 配套通用手册：docs/deployment.md（含 .env 全量说明与上线检查清单）。
> 本手册按宝塔操作路径编写，适用于宝塔 7.x/8.x + Nginx。

## 总览（10 步）

1. 装环境（PHP 8.4 + 扩展 + MySQL 8）
2. 放开 PHP 禁用函数
3. 建站点 + 域名
4. 上传代码
5. Composer 安装依赖
6. 配 .env + 建数据库
7. 初始化（迁移/种子/storage 链接/缓存）
8. 站点设置（运行目录 /public + 伪静态 + 上传限制）
9. 常驻进程（队列 worker + 每分钟调度）
10. SSL 证书 + 终验

---

## 1. 软件商店装环境

- **Nginx**（任意稳定版）
- **MySQL 8.0**（或 5.7+）
- **PHP 8.4**（本项目开发环境即 8.4；8.3 也可）

装好后进 **PHP 8.4 → 设置 → 安装扩展**，确认以下已装（未装的点安装）：

```
fileinfo  gd(curl 自带)  intl  bcmath  zip  exif  redis(暂不需要)  pdo_mysql
```

（mbstring/openssl/xml 等默认自带。）

## 2. 放开 PHP 禁用函数（关键坑）

**PHP 8.4 → 设置 → 禁用函数**，删除以下函数（Composer 与 artisan 需要）：

```
putenv  proc_open  proc_get_status  symlink  pcntl_signal
```

不放开的表现：composer 报错、`storage:link` 失败、队列启动失败。

## 3. 创建站点

**网站 → 添加站点**：

- 域名：`你的域名.com www.你的域名.com`（没解析的先去域名商加 A 记录到服务器 IP）
- 数据库：MySQL，utf8mb4，记下库名/用户/密码
- PHP 版本：8.4

## 4. 上传代码

本地打包（排除开发产物）：

```bash
cd /e/桌面文件/文件夹/BtoBcms
tar --exclude=node_modules --exclude=vendor --exclude=.git \
    --exclude=storage/framework --exclude=storage/logs \
    --exclude=.env --exclude=database/database.sqlite \
    -czf btobcms.tar.gz .
# ⚠️ 排除 .env（本地密钥不上服务器，生产由安装向导生成全新 .env）
#    排除 database.sqlite（本地开发库，生产用 MySQL）
```

方式 A（**推荐：git 部署**，解锁后台一键更新中心）：

```bash
# 服务器先配好 GitHub Deploy Key（见 docs/update-center.md 第一节）
cd /www/wwwroot
git clone git@github.com:你的账号/btobcms.git 你的域名
cd 你的域名 && git checkout v1.0.0
```

方式 B（zip 上传，后续无法用更新中心）：

宝塔 **文件 → 进入站点目录 `/www/wwwroot/你的域名`**：
先删掉默认的 `index.html`、`404.html`，上传 `btobcms.tar.gz` → 右键解压。

## 5. Composer 安装依赖

SSH 连服务器（宝塔首页有 SSH 信息），执行：

```bash
# 装 Composer（已装可跳过）
cd /tmp && curl -sS https://getcomposer.org/installer | /www/server/php/84/bin/php
mv composer.phar /usr/local/bin/composer && chmod +x /usr/local/bin/composer

# 国内镜像加速
composer config -g repos.packagist composer https://mirrors.aliyun.com/composer/

# 安装依赖（--no-dev 不装测试依赖）
cd /www/wwwroot/你的域名
composer install --no-dev --optimize-autoloader
```

> 内存不足报错时：`/www/server/php/84/bin/php -d memory_limit=1G /usr/local/bin/composer install --no-dev --optimize-autoloader`

## 6. 一键 Web 安装（推荐）

环境自检 + 配置 + 建表 + 管理员一步完成，不再手工编辑 .env：

1. 浏览器访问 `http://你的域名/install`
2. **第 1 步 · 环境检测**：自动检查 PHP 版本/扩展/目录权限/禁用函数，全部 ✔ 通过才能继续（未通过项会给出宝塔操作提示，处理后刷新）
3. **第 2 步 · 配置安装**：填数据库（宝塔「网站 → 数据库」可查）+ 站点名 + 管理员邮箱密码，点「开始安装」
   - 仅允许装到空库（有 users 表的旧库会被拒绝）；中断后可重提交断点续装
4. **第 3 步 · 完成**：自动生成 .env（production/密钥/时区/APP_URL）、建全部数据表、创建管理员、写安装锁

装完后 `/install` 自动 404（锁文件 `storage/app/installed.lock`，删它+清库可重装）。

> SMTP 不在向导里配置（避免复杂化）：装完后在后台「插件中心 → MailNotifier」配置管理员收件邮箱，SMTP 参数写 .env 的 MAIL_* 段（示例见下）或使用插件的兜底发信。

## 7. 初始化（一键安装已覆盖大半）

向导已自动完成：建表、管理员、storage 链接。剩下补两条：

```bash
cd /www/wwwroot/你的域名
PHP=/www/server/php/84/bin/php

$PHP artisan optimize             # 性能缓存（config+route+view）

# 权限（上传插件 zip / 图片需要）
chown -R www:www /www/wwwroot/你的域名
chmod -R 775 storage bootstrap/cache
```

如需 SMTP 发信，编辑 .env 的 MAIL_* 段后 `$PHP artisan config:clear && $PHP artisan config:cache`。

## 8. 站点设置

**网站 → 你的站点 → 设置**：

1. **网站目录**：运行目录选 `/public`（最重要的一步，选错会暴露源码）。
2. **伪静态**：选 `laravel5`（宝塔内置模板），或粘贴：

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

3. **配置文件**（或在伪静态顶部加）——屏蔽敏感文件 + 上传限制：

```nginx
location ~ /\.(env|git) { deny all; }
client_max_body_size 20m;
```

4. **PHP 版本**确认 8.4。

## 9. 常驻进程

### 9a. 队列 worker（邮件/飞书通知靠它）

装宝塔插件 **「进程守护管理器」（Supervisor）** → 添加守护进程：

- 名称：`btobcms-queue`
- 启动用户：`www`
- 运行目录：`/www/wwwroot/你的域名`
- 启动命令：

```bash
/www/server/php/84/bin/php artisan queue:work database --sleep=3 --tries=3 --max-time=3600
```

- 进程数：1

### 9b. 每分钟调度（SEO 巡检等）

**计划任务 → 添加任务**：

- 类型：Shell 脚本；周期：N 分钟（每 1 分钟）
- 脚本内容：

```bash
cd /www/wwwroot/你的域名 && /www/server/php/84/bin/php artisan schedule:run >> /dev/null 2>&1
```

## 10. SSL + 终验

**网站 → 设置 → SSL → Let's Encrypt**：勾选域名 → 申请 → 开启「强制 HTTPS」。

然后按顺序验证：

```bash
curl -I https://你的域名                 # 200
curl -s https://你的域名/robots.txt | grep Sitemap   # 指向生产域名
```

浏览器走一遍：
1. 首页正常、无混合内容告警
2. `/admin` 登录（首个注册用户自动进面板），中英文切换
3. 提交一条测试询盘 → 后台看到记录 + 飞书收到卡片 + 两个邮箱收到邮件（验证队列 worker 活着）
4. 后台「插件中心」启用并配置各插件（飞书 Webhook / SMTP 收件箱 / LLM Key / WhatsApp）
5. 「全站设置」填站点名、联系方式、社交链接（sameAs）

最后在服务器上跑一次外部审计（可选）：

```bash
curl -O https://你的域名/... # 或本地执行：
php seo_external_audit.php https://你的域名    # 应 100/100
php seo_strict_audit.php https://你的域名      # 应 ≥99/100
```

再到 [Google Search Console](https://search.google.com/search-console) 添加资源、提交 `https://你的域名/sitemap.xml`。

## 上线安全自检（1 分钟）

确认敏感文件不可被下载（三条都应 404/403，返回 200 = 立即排查）：

```bash
curl -s -o /dev/null -w "%{http_code}\n" https://你的域名/.env          # 应用密钥/数据库密码
curl -s -o /dev/null -w "%{http_code}\n" https://你的域名/.git/config   # 源码仓库
curl -s -o /dev/null -w "%{http_code}\n" "https://你的域名/storage/../.env"  # 路径穿越
```

若出现 200：九成是「运行目录」没选 `/public`（Nginx 把项目根当 web 根，静态文件直出）。
修复：网站设置 → 网站目录 → 运行目录改回 `/public`，并确认配置文件里保留了：

```nginx
location ~ /\.(env|git) { deny all; }
```

敏感信息分布速查：
- **.env**（服务器文件，不进 git/上传包）：APP_KEY、数据库密码、SMTP 密码 —— 全部生产密钥的"根"
- **数据库 settings 表**（随库走，web 不可达）：飞书 Webhook、LLM API Key、WhatsApp 号码、管理员收件邮箱
- **代码包**：不含任何密钥（插件代码只读配置，不硬编码）
## 宝塔专属备份

**计划任务**：
- 类型「备份数据库」，选本项目库，每天 3:00，保留 7 份
- 类型「备份网站」，选站点目录，每天 3:20，保留 7 份

（宝塔备份默认存本机；「软件商店 → 存储方案」可挂阿里云 OSS 等异地存储。）

## 排错速查

| 症状 | 处理 |
|---|---|
| 500 白屏 | 看 `storage/logs/laravel.log`；多为 .env 配错，改后 `$PHP artisan config:clear && $PHP artisan config:cache` |
| composer 报 putenv/proc_open | 第 2 步没放开禁用函数 |
| 图片 404 | `storage:link` 没跑或没权限 |
| 询盘无通知 | 进程守护管理器看 queue 进程状态；或插件没启用 |
| sitemap 里是 localhost | .env 的 APP_URL 没改 |
| 页面样式全丢 | 运行目录没选 /public 或伪静态没配 |
| 上传插件 zip 失败 | `chmod -R 775 storage`；zip 里不能多包一层目录 |
| 后台登录按钮无反应/登录后弹回 | Livewire 脚本未加载：v1.0.6 起安装时自动发布静态资源。老站点修复：`php artisan vendor:publish --tag=livewire:assets --force` |

## 重装（已部署站点重新走安装向导）

```bash
cd /www/wwwroot/你的域名
rm -f storage/app/installed.lock                                   # 删安装锁，/install 重新打开
/www/server/php/84/bin/php artisan migrate:fresh --force           # 清空所有数据表并重建（数据不可恢复！）
/www/server/php/84/bin/php artisan config:clear
```

刷新 `http://域名/install` 从头走一遍向导即可。需要保留询盘数据先在后台导出 CSV。
