# 更新中心使用指南（GitHub 版本化部署）

> 一次搭建后，改代码只需：本地 commit → push → GitHub 发 Release → 网站后台点一下「更新」。
> 代码与数据分离：更新只换程序，不碰数据库 / 上传图片 / .env。

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
- git / composer 路径：默认 PATH 内不用填

配置后「更新中心」页显示：当前版本 vs 最新 Release、变更说明、历史版本列表。

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

### 更新时发生了什么（全自动）

1. 站点进入**维护模式**（访客看到维护页，不是报错）
2. **备份数据库**（MySQL 用 mysqldump，SQLite 直接复制；保留最近 5 份）
3. `git fetch --tags` → `git checkout v1.0.1`
4. `composer install --no-dev` 同步依赖
5. `php artisan migrate --force` 数据库结构升级
6. `php artisan optimize` 重建缓存
7. 退出维护模式，站点恢复

**任一步失败：自动回滚**到更新前版本并恢复访问，失败原因写在日志区。
前提检查不通过（git 未装 / 非 git 部署 / 服务器有未提交改动）时按钮不可用并列出原因。

## 三、回滚到任意历史版本

更新中心 → 历史版本列表 → 任意版本右侧「更新到此版本」（git checkout 旧 tag，同样走备份+回滚保护）。

## 四、安全要点

- 仓库**私有** + Deploy key 只读 + Token 最小权限（Contents: Read）
- 更新功能仅登录管理员可用；`.env`/数据/上传文件不在 git 里，更新永不触碰
- 每次更新自动产生数据库备份：`storage/app/backups/`（建议纳入异地同步）
- 更新由队列 worker 执行——**确保进程守护在跑**（宝塔手册第 9 步）

## 五、常见问题

| 症状 | 处理 |
|---|---|
| 页面显示"尚未配置 GitHub 仓库" | 插件设置里填 owner/repo |
| 检测报 403 限流 | 匿名 API 60 次/小时上限，配置 Token 即提升到 5000 |
| 前置检查：非 git 仓库 | 站点是 zip 上传部署的——按上面"首次搭建 3"重新 git clone 部署（或服务器 git init + remote add + fetch + checkout v1.0.0） |
| 前置检查：有未提交改动 | 服务器上直接改过文件——`git status` 查看，`git stash` 或还原；确要保留设 `UPDATE_CENTER_ALLOW_DIRTY=true` |
| 更新失败自动回滚了 | 看更新中心日志区的具体失败步骤（多为 composer 路径未配） |
