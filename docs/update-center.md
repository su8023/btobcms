# 更新中心使用指南（GitHub 版本化部署）

> 一次搭建后，改代码只需：本地 commit → push → GitHub 发 Release → 网站后台点一下「更新」。
> 代码与数据分离：更新只换程序，不碰数据库 / 上传图片 / .env。
>
> ⚠️ **v1.4.0 起更新机制已改版**：不再走 git checkout + composer install，改为下载自包含 zip
> 覆盖更新——**更新**不再需要 git / composer / 队列 worker（本文第一节 2、3 的 git 部署仅是
> 初始部署的一种可选方式）。最新流程、恢复通道与修复纪要见 docs/release-build.md。

## 一、首次搭建（10 分钟）

### 1. GitHub 建仓库

- 新建**私有**仓库（如 `your-name/btobcms`）
- 本地推送现有仓库（已含 v1.0.0 首提交）：

```bash
cd 项目目录
git remote add origin git@github.com:your-name/btobcms.git
git push -u origin main --tags
```

### 2. 配置 Deploy Key（服务器只读拉取）

```bash
# 服务器生成密钥（一路回车）
ssh-keygen -t ed25519 -C "btobcms-deploy" -f ~/.ssh/btobcms_deploy

# 打印公钥，粘贴到 GitHub 仓库 → Settings → Deploy keys → Add（只读，不勾 write）
cat ~/.ssh/btobcms_deploy.pub

# 让 git 用这把钥匙
cat >> ~/.ssh/config << 'EOF'
Host github.com
  IdentityFile ~/.ssh/btobcms_deploy
EOF
```

验证：`ssh -T git@github.com` 看到 "Hi your-name/btobcms!" 即通。

### 3. 服务器按 git 方式部署（宝塔）

把部署手册第 4 步的"上传 zip"换成：

```bash
cd /www/wwwroot
git clone git@github.com:your-name/btobcms.git 你的域名
cd 你的域名
git checkout v1.0.0        # 检出正式版本，不用 main 开发分支
```

后续步骤（composer install / 访问 /install 安装）不变。

### 4. 后台配置更新中心

插件中心 → UpdateCenter → 设置：

- **GitHub 仓库**：`your-name/btobcms`
- **GitHub Token**：私有仓库必填（GitHub → Settings → Developer settings → Personal access tokens → Fine-grained，只给该仓库 Contents 只读权限）
- 仅此两项——v1.4.0 起不再需要配置 git / composer 路径

配置后「更新中心」页显示：当前版本 vs 最新 Release、变更说明、版本列表，并提供
「检查更新」（清缓存立即回源 GitHub）与「本地上传安装包」（不依赖 GitHub 的更新通道）。

## 二、日常发版流程

```
本地改代码
  ↓
git add -A && git commit -m "改动说明"
  ↓ 改 config/version.php 的 version 值（如 v1.0.1）
git tag -a v1.0.1 -m "版本说明"
git push origin main --tags
  ↓
GitHub → Releases → Draft a new release
  选 tag v1.0.1，标题写版本名，正文写变更说明（会显示在后台）
  ↓
网站后台 → 更新中心 → 看到 "有新版本可更新" → 点「更新到 v1.0.1」
```

### 更新时发生了什么（全自动，v1.4.0 起自包含 zip 版）

1. 从 GitHub 下载 `btobcms-{tag}.zip`，下载后做**三重校验**（字节数与 Release asset 声明比对 +
   SHA-256 + zip 完整性，未通过自动重试共 3 次，v1.4.8）
2. 站点进入**维护模式**（访客看到维护页，不是报错）
3. **备份数据库**（MySQL 用 mysqldump，SQLite 直接复制；保留最近 5 份）
4. 解压包内**全部代码 + vendor 生产依赖**，保留式覆盖站点（`.env`、`storage/`、数据库不动）
5. `php artisan migrate --force` 数据库结构升级
6. `php artisan optimize` 重建缓存
7. 退出维护模式，站点恢复

更新在后台 HTTP 请求内同步跑完，**不依赖队列 worker**；每步落状态文件
`storage/app/update-state.json`，中断后点「继续更新」断点恢复（各步骤幂等）。

**任一步失败：自动回滚**到更新前版本并恢复访问，失败原因写在日志区。
前置检查（preflight）不通过时按钮不可用并列出原因：缺 zip 扩展、关键目录不可写、
磁盘余量不足 512 MB（git / composer 已不在检查之列）。

## 三、回滚

更新失败时自动回滚到更新前版本并恢复访问。需要手动回退到更早版本：从 GitHub Release 下载
该版本的 `btobcms-{tag}.zip`（或后台「本地上传安装包」直传），再走一次同样的更新流程即可。

## 四、安全要点

- 仓库**私有** + Deploy key 只读 + Token 最小权限（Contents: Read）
- 更新功能仅登录管理员可用；`.env`/数据/上传文件不在 git 里，更新永不触碰
- 每次更新自动产生数据库备份：`storage/app/backups/`（建议纳入异地同步）
- 更新在后台 HTTP 请求内同步执行，**不依赖队列 worker**（worker 仍为邮件/飞书通知所需——确保进程守护在跑，宝塔手册第 9 步）

## 五、常见问题

| 症状 | 处理 |
|---|---|
| 页面显示"尚未配置 GitHub 仓库" | 插件设置里填 owner/repo |
| 检测报 403 限流 | 匿名 API 60 次/小时上限，配置 Token 即提升到 5000 |
| 前置检查不通过 | 检查项：zip 扩展、storage/app 等目录可写、磁盘余量 ≥512 MB——按提示处理（git / composer 已不要求） |
| 显示「已是最新」但 GitHub 有新版 | 点「检查更新」立即回源（v1.4.7 起提供）；否则等缓存过期，或靠 5 分钟节流自动复查 |
| 下载报「传输截断 / SHA-256 不匹配」 | GitHub CDN 节流，v1.4.8 起自动重试共 3 次；仍失败走 docs/release-build.md 第七节两条恢复通道 |
| 本地上传安装包报 must not be greater than 12288 kilobytes | 旧版本 Livewire 临时上传 12 MB 限制，v1.4.9 起放开到 200 MB；旧版本用文件管理器放 `storage/app/update/{tag}.zip` 后点「继续更新」 |
