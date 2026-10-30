# 站点迁移/换域名清单

> 把已有站点（内容+数据）搬到新服务器或新域名的完整 SOP。
> 全新站点部署见 docs/deployment-bt.md（git clone → /install 向导即可，无此文档的复杂度）。

## 先分清两种场景

| 场景 | 做法 |
|---|---|
| **全新站点**（新域名 + 空内容重新开始） | 按 docs/deployment-bt.md 全新安装。唯一红线：**先用宝塔绑定正式域名、DNS 生效，再通过域名访问 /install**——APP_URL 会按安装时的访问域名固化，用 IP 装完队列侧收录推送/审计/GSC 全指向错误地址（向导环境自检会警告） |
| **迁移现有站点**（保留文章/产品/询盘/插件设置） | 本文档 |

## 迁移 SOP（按顺序执行）

### 旧服务器：打包四样东西

1. **代码**：不用打包——新服务器 git clone 同 tag 即可（代码在 git 里）
2. **数据库**：
   - MySQL：`mysqldump -u用户 -p 数据库名 > dump.sql`
   - SQLite：直接拷 `database/database.sqlite`（或 storage/app 下的库文件，看 .env DB_DATABASE）
3. **storage/app 整个目录**（不在 git 里）：
   - `storage/app/public/` —— 产品图/封面/案例图（漏了 = 全站图片 404）
   - `storage/app/private/` —— LeadMagnet 磁铁文件（private 盘）
   - `storage/app/indexnow.key` —— IndexNow 密钥（沿用即可，同站）
4. **旧 .env 里抄下 `APP_KEY`**（只抄值，整个文件不照搬）

### 新服务器：部署

1. 按 docs/deployment-bt.md 步骤 1-5：建站（PHP 8.4/禁用函数）→ `git clone -b v1.3.3 ...` → `composer install --no-dev --optimize-autoloader` → 权限
2. **不要跑 /install 向导**（会生成新 APP_KEY 并拒绝装到非空库）
3. 还原数据库（导入 dump / 拷 SQLite 文件）
4. 还原 storage/app（旧服务器打包的整个目录覆盖过来）
5. 手写 `.env`（可拷旧 .env 改）：
   - `APP_KEY=` 粘贴**旧服务器的值**（GSC OAuth token 是它加密的——换了 Key token 解不开，Cookie 全失效）
   - `APP_URL=https://新域名`
   - 数据库连接 = 新服务器的
   - `SESSION_DRIVER=database`、`CACHE_STORE=database`（老版本装的站点如还是 file，顺手改掉）
6. SSH 收尾：
   ```
   php artisan storage:link        # public/storage 软链（图片入口）
   php artisan migrate --force     # 数据库结构对齐到当前版本
   php artisan optimize
   php artisan queue:restart
   ```

### 域名与外部服务切换

| 项 | 动作 |
|---|---|
| DNS/CF | 指向新服务器 IP；TLS 模式 Full |
| GSC | 新域名在 Search Console 重新验证资源；插件后台「GSC 连接」里 site_url 改成新资源（https://新域名/）；旧资源用「地址变更」提交 |
| IndexNow | key 文件沿用（同站同 key），无需操作 |
| Google Analytics | GA4 数据属性不变，无需操作 |
| 邮件/飞书 | Webhook/SMTP 与域名无关，沿用 |

### 迁移后验证清单（逐项过）

- [ ] 前台首页/产品/文章图片全部显示（storage 搬对 + storage:link 成功）
- [ ] 后台能登录（APP_KEY 沿用正确）
- [ ] 后台 → GSC 看板能拉数据（加密 token 解密成功；失败则重新连接一次）
- [ ] 发布一条测试文章 → IndexNow 推送成功（日志无 warning，APP_URL 正确）
- [ ] 提交一条测试询盘 → 飞书/邮件通知正常、线索雷达有评分
- [ ] 磁铁 /download/{slug} 下载正常
- [ ] 更新中心 preflight 全绿（zip 扩展、目录可写、磁盘余量；v1.4.0 起不再检查 git remote）
- [ ] HTTPS 证书生效、http 301 到 https

## 回滚

DNS 未切换前旧服务器原样保留即可零风险回滚；切换后发现问题，DNS 改回旧 IP 即恢复（数据库已分叉的话以新库为准再同步一次）。
