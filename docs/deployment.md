# BtoBcms 生产部署指南

> 适用：全新 Linux 服务器（Ubuntu 22.04/24.04 为例）从零部署到上线。

## 一、服务器要求

| 组件 | 要求 |
|---|---|
| PHP | 8.3 / 8.4，扩展：`cli fpm mysql nd pdo_mysql mbstring xml curl zip gd intl bcmath` |
| 数据库 | MySQL 8.0+（开发用 SQLite，生产建议 MySQL） |
| Web | Nginx + PHP-FPM |
| 缓存/队列 | 可先用 database 驱动（零依赖）；量大后换 Redis |
| HTTPS | Let's Encrypt（certbot）免费证书 |
| 内存 | 1G 起步（跑 queue worker + php-fpm 够用），2G 更稳 |

不需要 Node.js——前端是手写 CSS/JS，无构建步骤。

## 二、上传代码

方式 A（推荐 git）：

```bash
cd /var/www
git clone <你的仓库> btobcms
cd btobcms
```

方式 B（zip 上传后解压到 /var/www/btobcms）。

## 三、安装依赖

```bash
cd /var/www/btobcms
composer install --no-dev --optimize-autoloader
```

## 四、配置 .env

```bash
cp .env.example .env
php artisan key:generate
```

编辑 .env，**逐项核对**：

```ini
APP_ENV=production
APP_DEBUG=false
APP_URL=https://你的域名          # ⚠️ 最关键：sitemap/canonical/og:image 全部由它生成

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=btobcms
DB_USERNAME=btobcms
DB_PASSWORD=强密码

QUEUE_CONNECTION=database        # 邮件/飞书通知走队列，不阻塞询盘响应
CACHE_STORE=database             # 或 redis
SESSION_DRIVER=database

# 真实 SMTP（阿里云邮件推送/SES/Resend 均可）
MAIL_MAILER=smtp
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_USERNAME=noreply@你的域名
MAIL_PASSWORD=SMTP密码
MAIL_FROM_ADDRESS=noreply@你的域名
MAIL_ENCRYPTION=tls
```

建库（MySQL）：

```sql
CREATE DATABASE btobcms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'btobcms'@'localhost' IDENTIFIED BY '强密码';
GRANT ALL PRIVILEGES ON btobcms.* TO 'btobcms'@'localhost';
FLUSH PRIVILEGES;
```

## 五、初始化数据库

```bash
php artisan migrate --force          # 建表
php artisan db:seed --force          # 初始分类/演示数据（可跳过后用后台自建）
php artisan storage:link             # 产品图片等上传文件软链
```

开发库已有内容想搬过去：后台「产品 → CSV 导出」再在生产「CSV 导入」；或用 `php artisan import:products`。

## 六、缓存优化（生产必做）

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

⚠️ 以后每次改 .env 后需 `php artisan config:clear` 再 config:cache。

## 七、Nginx 配置

`/etc/nginx/sites-available/btobcms`：

```nginx
server {
    listen 80;
    server_name 你的域名 www.你的域名;
    root /var/www/btobcms/public;    # 必须指向 public
    index index.php;

    client_max_body_size 20m;        # 插件 zip / 图片上传

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;   # 按实际 FPM 版本
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~* \.(css|js|png|jpg|jpeg|svg|webp|ico|woff2?)$ {
        expires 30d;
        add_header Cache-Control "public, immutable";
    }

    location ~ /\.(env|git) { deny all; }
}
```

```bash
ln -s /etc/nginx/sites-available/btobcms /etc/nginx/sites-enabled/
nginx -t && systemctl reload nginx
```

## 八、目录权限

```bash
chown -R www-data:www-data /var/www/btobcms
find /var/www/btobcms -type d -exec chmod 755 {} \;
chmod -R 775 storage bootstrap/cache   # web 需写日志/缓存/上传
```

## 九、HTTPS

```bash
apt install certbot python3-certbot-nginx
certbot --nginx -d 你的域名 -d www.你的域名
# 自动续期已内置，验证：certbot renew --dry-run
```

## 十、队列 Worker（邮件/飞书通知靠它）

systemd 方式（`/etc/systemd/system/btobcms-queue.service`）：

```ini
[Unit]
Description=BtoBcms queue worker
After=network.target mysql.service

[Service]
User=www-data
WorkingDirectory=/var/www/btobcms
ExecStart=/usr/bin/php8.4 artisan queue:work database --sleep=3 --tries=3 --max-time=3600
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

```bash
systemctl enable --now btobcms-queue
```

## 十一、调度器（每日 SEO 巡检）

crontab（root 或 www-data）：

```cron
* * * * * cd /var/www/btobcms && php8.4 artisan schedule:run >> /dev/null 2>&1
```

## 十二、创建管理员 & 插件启用

1. 访问 `https://你的域名/admin`，用 seeder 的账号登录（或 `php artisan tinker` 里 `User::create(...)` 后访问 /admin 注册首个账号——首用户自动可进面板）。
2. 插件中心启用需要的插件并配置：
   - **FeishuNotifier**：填飞书群机器人 Webhook URL
   - **MailNotifier**：管理员收件邮箱（多邮箱逗号分隔）
   - **AiSeoAuditor / AiContentWriter**：LLM API Key（OpenAI 兼容协议）
   - **WhatsAppChat**：号码 + 预填消息
3. 站点设置页：站点名、联系邮箱/电话/地址（首页/页脚/JSON-LD 即时生效）。

## 十三、上线检查清单

- [ ] `https://你的域名` 首页正常，浏览器无混合内容警告
- [ ] /admin 可登录，中英文切换正常
- [ ] 提交一条测试询盘：入库 + 飞书收到卡片 + 两个邮箱收到邮件
- [ ] /robots.txt 的 Sitemap 行指向生产域名
- [ ] /sitemap.xml 打开正常（空库时无 products/blog 分片属预期）
- [ ] 后台「SEO 巡检报告 → 立即巡检」跑一遍，报告无新增问题（该插件已内置站点抓取与规则体检）
- [ ] Google Search Console 添加资源并提交 sitemap.xml
- [ ] 换掉占位内容：首页 hero 认证徽章（ISO/CE/SGS 为占位）、og-default.png 品牌图（如需定制）、联系信息
- [ ] 备份策略（见下）

## 十四、备份

```bash
# 每日 3 点：数据库 + 上传文件
0 3 * * * mysqldump -u btobcms -p密码 btobcms | gzip > /var/backups/btobcms-db-$(date +\%F).sql.gz
10 3 * * * tar -czf /var/backups/btobcms-storage-$(date +\%F).tar.gz -C /var/www/btobcms storage/app/public
```

异地备份：把 /var/backups 同步到对象存储（rclone + cron）。

## 十五、常见坑

| 症状 | 原因/解法 |
|---|---|
| sitemap/canonical 里是 localhost | .env 的 APP_URL 没设生产域名 → 改后 `config:clear && config:cache` |
| 询盘提交成功但没邮件/飞书 | 队列 worker 没跑 → `systemctl status btobcms-queue`；或插件未在后台启用 |
| 500 白屏 | APP_DEBUG=false 时看 `storage/logs/laravel.log`；常见是 .env 配错后没 config:clear |
| 图片 404 | 没执行 `storage:link` |
| 时区 | .env 加 `APP_TIMEZONE=Asia/Shanghai`（询盘时间戳/巡检调度按此时区） |
| 插件上传失败 | `storage/app` 权限；或 zip 内多套了一层目录 |
| 询盘里 IP 全是 Cloudflare 节点 IP | .env 加 `TRUSTED_PROXIES=<Cloudflare 官方 IP 段>`，再 `config:clear && config:cache`（见下节） |
| 后台某个面板打开 403 | 该面板只对管理员开放 → `php artisan user:role <邮箱> admin` |
| 装完仍能打开 /install | `storage/app/installed.lock` 丢了（容器没挂卷）→ 见下节 |
| AI 功能报"AI 接口地址不可用" | 自建内网模型被 SSRF 防护拦下 → .env 加 `AI_ALLOW_PRIVATE_HOSTS=true` |
| 飞书推送失败 | 自建中继域名被拦 → 插件中心打开"允许自定义 Webhook 主机" |

## 十五之二、CDN / 反向代理（Cloudflare）

站点在 Cloudflare 或负载均衡之后时，**必须**在 `.env` 里声明可信代理，
否则询盘记录的 IP 是 CDN 节点 IP（而不是买家真实 IP），且基于 IP 的限流会失准：

```ini
# Cloudflare 官方 IP 段（https://www.cloudflare.com/ips-v4 与 ips-v6）
TRUSTED_PROXIES=173.245.48.0/20,103.21.244.0/22,103.22.200.0/22,103.31.4.0/22,141.101.64.0/18,108.162.192.0/18,190.93.240.0/20,188.114.96.0/20,197.234.240.0/22,198.41.128.0/17,162.158.0.0/15,104.16.0.0/13,104.24.0.0/14,172.64.0.0/13,131.0.72.0/22
```

```bash
php artisan config:clear && php artisan config:cache
```

要点：
- **留空 = 不信任任何代理**（默认，最安全）。此时 X-Forwarded-For 不被采信，客户端无法伪造 IP 绕过限流。
- **不要填 `*`**：那等于信任任何来源的 X-Forwarded-For，限流与 IP 统计都会失真。
- 生效路径是 `config/trustedproxy.php`，改完必须 `config:clear && config:cache` 才会重新烘焙。

## 十五之三、安装锁（防匿名重装）

`storage/app/installed.lock` 是安装向导的锁文件。**它所在的 `storage/` 必须持久化**
（Docker 部署要挂卷，容器重建后锁文件不能丢）。

锁丢失时仍有一道防线：根目录 `.env` 里存在且配置了非空 `APP_KEY` 时，`/install` 同样返回 404。
两道防线都在才安全 —— 两者都失效时，匿名访客可以重跑安装向导改写 `.env` 并建出自己的管理员账号。


## 后续优化路线（可选）

- Redis 缓存/队列（QPS 上升后）
- Meilisearch 多语言搜索（产品目录恢复时）
- CDN（Cloudflare 免费档即可，顺带防护）
- GSC API 接入 + AI 内容管线（M3 计划）
