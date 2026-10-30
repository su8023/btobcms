# 发布包构建手册（WordPress 式自包含 zip）

> 给开发者的操作手册：从改版本号 → 构建 → 校验 → 上传 GitHub Release → 后台点「更新」。
>
> **为什么是自包含 zip**：旧方案依赖服务器上有 git + composer + 常驻队列 worker，worker 一挂更新就永远卡住。
> 新方案在发 Release 时把**全部代码 + vendor 生产依赖**打进一个 zip，服务器只要下载解压覆盖即可，
> 不需要 git / composer / worker（见 `Modules/UpdateCenter/app/Services/SelfUpdater.php`）。

---

## 一、五分钟速查

```bash
# 1) 改版本号（config/version.php）
#    'version' => 'v1.3.7',

# 2) 构建（产物：dist/btobcms-v1.3.7.zip）
"E:/dev-tools/php84/php.exe" scripts/build-release.php --composer="E:/dev-tools/composer.phar"

# 3) 校验（完整性 18 项 + 覆盖语义 16 项，全 PASS 才发版）
"E:/dev-tools/php84/php.exe" scripts/verify-release.php dist/btobcms-v1.3.7.zip
"E:/dev-tools/php84/php.exe" scripts/verify-swap.php  dist/btobcms-v1.3.7.zip

# 4) 上传到 GitHub Release（资产名必须保持 btobcms-{tag}.zip）
gh release upload v1.3.7 dist/btobcms-v1.3.7.zip --clobber

# 5) 网站后台 → 插件中心 → 更新中心 → 点「立即更新」
```

---

## 二、改版本号（唯一必改的一处）

版本号只有一处：**`config/version.php`**

```php
return [
    'version' => 'v1.3.7',   // ← 改这里
];
```

规则：

| 规则 | 说明 |
|---|---|
| 必须带 `v` 前缀 | `v1.3.7`，不是 `1.3.7` |
| 必须与 git tag 完全一致 | tag `v1.3.7` ↔ 版本 `v1.3.7` ↔ 资产 `btobcms-v1.3.7.zip` |
| 升级语义 | 只改小版本位：`v1.3.6 → v1.3.7`；大版本按 `v1.4.0` 这样跳 |
| 不要手改 `vendor/` 或任何自动生成的版本文件 | 版本号只有 `config/version.php` 一处 |

> 版本号 **不要** 写进 `UpdateCenter` 的数据库记录——后台「当前版本 vs 最新 Release」的比对读的是
> `config/version.php` 与 GitHub Release tag。

---

## 三、构建

### 命令

```bash
php scripts/build-release.php [--composer="composer"] [--keep-staging] [--out="dist"]
```

| 参数 | 默认 | 说明 |
|---|---|---|
| `--composer=` | `composer` | Composer 可执行。填 `.phar` 路径（`E:/dev-tools/composer.phar`）时脚本用当前 PHP 解释器跑它；也可直接填 `composer`（走 PATH） |
| `--keep-staging` | 关 | 保留 `dist/staging/` 中间目录，便于排查；默认构建完自动删除 |
| `--out=` | `dist` | 输出目录 |

### 脚本做了什么（4 步）

1. **复制代码到 `dist/staging/`**
   - 排除：`.env`、`.git`、`node_modules`、`tests`（含 `Modules/*/tests`）、`storage/` 全部内容、
     `bootstrap/cache/` 全部内容、`*.sqlite`（数据库文件！）、`*.zip`、`*.log`、`*.key`、符号链接。
   - 重建 `storage/` 与 `bootstrap/cache/` 目录骨架（每个目录放一个 `.gitignore` 占位）。
   - **不复制 `vendor/`**——下一步由 Composer 干净重建。
2. **在 staging 里 `composer install --no-dev --optimize-autoloader --no-scripts`**
   - `--no-dev`：包内不含 phpunit / mockery / faker / pint 等开发依赖。
   - `--no-scripts`：**必须**。`post-autoload-dump` 会跑 `artisan package:discover` 和 `artisan filament:upgrade`，
     staging 里没有 `.env`、也没有数据库，必然失败；而 `bootstrap/cache` 本来就不该进包，服务器首次请求会自行重建。
3. **打成 `dist/btobcms-{version}.zip`**（文件顺序稳定，可复现）。
4. **打印体积报告 + 上传命令**，然后清理 staging。

### 依赖

- PHP **8.3+**（开发机用 8.4），CLI 需 `zip` 扩展。
- Composer 2.x + 首次构建需能访问 packagist（或本地有 Composer 缓存）。
- 磁盘：staging 需要约 80 MB。

### 体积参考（v1.3.6 实测）

| 分区 | 压缩后 | 原始 | 说明 |
|---|---|---|---|
| `vendor/` | 15.5 MB | 58.4 MB | 生产依赖，占大头（78%） |
| `public/` | 3.7 MB | 13.3 MB | 主要是 `public/vendor/livewire`（Livewire 运行期 JS，已入 git，必须随包） |
| `Modules/` + `app/` + `lang/` + `resources/` 等 | ~0.6 MB | ~1.6 MB | 业务代码 |
| **zip 文件总大小** | **22.7 MB** | — | 15459 个条目（zip 头/目录项占约 3 MB） |

超过 40 MB 才需要考虑瘦身（见第六节）。

---

## 四、校验（发版前必做）

```bash
php scripts/verify-release.php dist/btobcms-v1.3.7.zip   # 完整性
php scripts/verify-swap.php  dist/btobcms-v1.3.7.zip   # 覆盖语义
```

**`verify-release.php`（18 项）**——检查包里该有的都有、不该有的都没有：

- 在的：`artisan`、`vendor/autoload.php`、`composer.json/lock`、`config/version.php`（版本号与源码一致）、
  `Modules/` 下 **15 个模块目录**（每个模块的 `app/` 都在）、`resources/views`、`lang/`（9 个语种）、
  `themes/`、`public/index.php`、`routes/`、`database/migrations`、`app/Support/helpers.php`、
  `storage/` 与 `bootstrap/cache/` 目录骨架 + `.gitignore` 占位。
- 不在的：`.env`、`.env.*`（`.env.example` 除外）、`.git`、`node_modules`、`tests`、
  `storage/app` 下的用户数据、`storage/framework|logs` 下的运行时文件、`bootstrap/cache/*.php`、
  `*.sqlite`/`*.db`、APP_KEY、日志、符号链接 `public/storage`、任何 dev 依赖。
- 可启动性：解包目录里直接 `php artisan --version` 能跑出 Laravel 版本（证明 vendor 自洽）。

**`verify-swap.php`（16 项）**——造一个「假旧站点」（旧版本号 + 旧 `.env` + 用户数据 + 旧缓存 + 旧模块启停状态），
用**真实的 `SelfUpdater::swap()`**（反射调用）做一次覆盖，然后断言：

- `.env` 逐字节未变、`storage/` 下数据/上传文件/日志/会话全部未变；
- `bootstrap/cache` 的旧编译缓存被清理（触发 Laravel 重建）；
- 包内同名文件覆盖旧文件；`config/version.php` 更新到新版本；
- `vendor/`、`Modules/` 就位；
- `modules_statuses.json` 合并正确（旧站点手动关闭的模块保持关闭，新包条目补齐）。

两个脚本都会自建临时目录并清理，退出码非 0 即失败，可直接接 CI。

---

## 五、上传到 GitHub Release

### 1. 提交并打 tag

```bash
git add -A
git commit -m "release v1.3.7：<一句话说明本次改动>"
git tag -a v1.3.7 -m "v1.3.7"
git push origin main --tags
```

> tag 必须与 `config/version.php` 的 `version` 完全一致。

### 2. 建 Release 并上传资产

```bash
# 用 CLI
gh release create v1.3.7 --title "v1.3.7" --notes "本次更新说明…" dist/btobcms-v1.3.7.zip
# 或已有 Release 时补传
gh release upload v1.3.7 dist/btobcms-v1.3.7.zip --clobber
```

没有 `gh` 时用网页：GitHub → Releases → Draft a new release → 选 tag `v1.3.7` → 拖入 zip → Publish。

### 3. ⚠️ 资产名必须叫 `btobcms-{tag}.zip`

`SelfUpdater::download()` 是**按名字精确匹配**找资产的：

```php
if (($asset['name'] ?? '') === 'btobcms-'.$tag.'.zip') { ... }
// 找不到就抛错："Release {$tag} 未附带安装包 btobcms-{$tag}.zip"
```

所以：

| tag | 资产名（必须） |
|---|---|
| `v1.3.7` | `btobcms-v1.3.7.zip` |
| `v1.4.0` | `btobcms-v1.4.0.zip` |

改名（如 `btobcms-v1.3.7-final.zip`）、加后缀、转成 `.tar.gz`——**后台点更新都会直接报「未附带安装包」**。

### 4. 后台点更新

网站后台 → 插件中心 → 更新中心 → 「检查更新」/「立即更新」。

SelfUpdater 会在当前 HTTP 请求里同步跑完：下载 → 维护模式 → 解压 → **保留式覆盖** → 迁移 → 重建缓存 → 退出维护模式。
全程落状态文件 `storage/app/update-state.json`，中途断电/超时会出现「继续更新」按钮，可断点恢复。

> 下载环节失败（GitHub API 不可达 / 匿名限流 / CDN 传输截断）时的自救见第七节「两条恢复通道」。

---

## 六、排除清单（包绝对不能带的东西）

包一旦带上这些，线上覆盖时**会直接损坏站点**——所以脚本硬性排除：

| 排除对象 | 为什么必须排除 |
|---|---|
| `.env` / `.env.*` | 覆盖会把线上密钥、数据库配置换成开发机的 |
| `storage/**`（除目录骨架） | 覆盖会删掉上传图片、磁铁文件、索引密钥、用户数据 |
| `bootstrap/cache/*.php` | 旧编译缓存引用旧路径，覆盖后 500；服务器需要自己重建 |
| `*.sqlite` / `*.db` | **数据库本体**！覆盖 = 线上数据被开发机数据替换 |
| `*.key` | APP_KEY，覆盖后加密数据全部解不开 |
| `.git` | 无用且体积大 |
| `tests/`、`node_modules/` | 无用、体积大 |
| dev 依赖 | `--no-dev` 后不存在（phpunit / mockery / faker / pint / collision / pail / pao） |
| 符号链接（如 `public/storage`） | 指向开发机绝对路径，打进包必然失效 |

### 覆盖式更新的已知特性

- **新版本删掉的文件不会从服务器上消失**。例如某模块被废弃后删了 `Modules/Foo/`，服务器上旧文件仍在
  （只是不再被路由到）。彻底清理需要人工登录服务器删。
- `modules_statuses.json` 是**合并**而非覆盖：旧站点手动关掉的模块，更新后仍保持关闭。

---

## 七、两条恢复通道（GitHub 下载拿不到安装包时）

更新流程的下载步骤要从 GitHub 拿 `btobcms-{tag}.zip`。当下载失败（API 不可达、匿名限流耗尽、
CDN 节流反复截断）时，有两条不依赖「服务器从 GitHub 下载成功」的自救通道：

### 通道一：网页后台「本地上传安装包」（推荐）

更新中心 → 「本地上传安装包」→ 选择本地构建好的 `dist/btobcms-{tag}.zip` → 填目标版本号 → 确认。

- **完全不依赖 GitHub**：API 限流、私有仓库没配 Token、CDN 截断都不影响；
- 后台把包直接传到服务器，跳过下载步骤，从解压开始走完覆盖/迁移/重建缓存；
- 支持 23 MB 的安装包：Livewire 临时上传限制已放开到 **200 MB**（v1.4.9 起，见第九节纪要）；
  注意 Nginx `client_max_body_size` 也要大于包体积（部署手册已配 256m，线上还是 20m 时改配置或走通道二）。

### 通道二：文件管理器兜底（手工放包 + 「继续更新」）

后台本地上传也走不通时（如旧版本仍有 12 MB 上传限制），或 GitHub CDN 节流、服务器侧反复下载截断的场景：

1. 把 `btobcms-{tag}.zip` 上传到服务器 **`storage/app/update/`** 目录（宝塔文件管理器 / scp 均可），
   改名为 **`{tag}.zip`**（如 `v1.4.9.zip`）；
2. 后台更新中心点「**继续更新**」。

下载步骤是幂等的：只要 `storage/app/update/{tag}.zip` 是**完整可打开的 zip**，就直接跳过下载、
从解压继续；残缺半截包会被删掉重下，不会死锁。这条通道正适合 GitHub CDN 节流——本地网络
通常比服务器通畅，先下载到本地再放上去。

> 「继续更新」按钮只在存在中断状态时显示（先点过一次「更新到 vX」留下断点）。若还没有断点，
> 放好包后直接点「更新到 vX」即可——下载步骤同样会检测到完整包并跳过。

---

## 八、常见问题

**Q：构建报 `composer install 失败（exit 1）`**
A：多半是 staging 里 `artisan package:discover` 报错。脚本已加 `--no-scripts` 规避；若仍失败，
先在项目根跑 `composer install` 确认 `composer.lock` 与 `composer.json` 同步（`composer validate`），再重试。

**Q：构建报「复制失败：xxx（文件名过长或权限不足？）」**
A：Windows 下路径超过 260 字符。项目路径太深时把项目挪到短路径（如 `E:\btobcms`）再构建。

**Q：构建很慢 / composer 装不上**
A：首次构建要下载约 40 MB 生产依赖。内网/离线机器先在能联网的机器上执行一次 `composer install`，
让 Composer 缓存（`composer config --global cache-dir`）就位，或直接带 `--prefer-dist` 走镜像。

**Q：后台点更新报「Release v1.3.7 未附带安装包 btobcms-v1.3.7.zip」**
A：资产名不对（见第五节第 3 步），或 Release 还没发布（草稿状态的资产 API 拿不到），
或 tag 与 `config/version.php` 的版本不一致。

**Q：后台点更新报「获取 Release 信息失败：HTTP 401/404」**
A：私有仓库要配 GitHub Token（插件设置里），且 Token 要有该仓库 Contents 读权限；
404 一般是仓库名填错或 tag 不存在。

**Q：更新后 500，提示找不到类/路由**
A：多半是 `bootstrap/cache` 旧缓存没清。SelfUpdater 覆盖后会主动删掉
`config.php / services.php / packages.php / providers.php / compiled.php / routes-v7.php / events.php`，
若仍 500，让服务器执行 `php artisan optimize:clear`；同时确认 `bootstrap/cache` 与 `storage` 目录对 web 用户可写。

**Q：更新后前端样式丢失**
A：包里的 `public/` 产物（`public/css/site.css`、`public/js/site.js`、`public/vendor/livewire/**`、
`public/themes/**`）来自仓库里已提交的文件。若改了 `resources/css|js` 并用了 Vite，
需要先 `npm run build` 产出 `public/build/`。
构建脚本是从**当前工作区**打包的，所以本地跑过 `npm run build` 的话产物会进包；
没跑过（`public/build` 在 .gitignore 里、仓库中也没有）则包内没有它，线上前端就会缺样式——
**改了前端就一定要先 build 再打包**。

**Q：包体积超过 40 MB**
A：当前 22.7 MB（vendor 15.5 MB + public 3.7 MB）。再涨先查 `public/vendor/livewire`（Livewire 资源）
与 `public/fonts`；`vendor/` 是生产依赖的硬成本，除非裁剪依赖否则压不动。

**Q：能不能让服务器自己 composer install 省掉 vendor？**
A：不能——旧方案就死在这：服务器要装 git/composer，还要常驻队列 worker，worker 挂了就再也更新不了。
自包含 zip 是刻意的设计：服务器只需要 PHP + zip 扩展 + 可写的站点根目录。

**Q：发了新 Release，后台还是显示「已是最新」**
A：v1.4.6 及以前 releases 缓存有 30 分钟盲区。v1.4.7 起点「检查更新」立即回源；不点也会每 5 分钟
节流自动复查一次（细节见第九节纪要）。旧版本没有「检查更新」按钮，需先升级一次更新器
（自举问题见 `docs/troubleshooting-history.md` 3.4）。

**Q：下载安装包反复报「传输截断 / SHA-256 不匹配」**
A：GitHub CDN 节流。v1.4.8 起下载后三重校验（字节数 + SHA-256 + zip 完整性）并自动重试共 3 次；
仍失败走第七节两条恢复通道（后台本地上传，或文件管理器放 `storage/app/update/{tag}.zip` 后点「继续更新」）。

**Q：本地上传安装包报 `must not be greater than 12288 kilobytes`**
A：旧版本 Livewire 临时上传默认限制 12 MB；v1.4.9 起已放开到 200 MB。旧版本传大包走
第七节通道二（文件管理器放包 + 「继续更新」）。

---

## 九、v1.4.7–v1.4.9 更新器修复纪要

> 三个补丁版本各修掉一个线上实测暴露的真实故障，要点记录在此，排查同类问题时对照。

### v1.4.7 — 发版后 30 分钟「已是最新」盲区

- **故障**：GitHub 发了新 Release，后台更新中心仍显示「已是最新」——releases 结果缓存 30 分钟，
  发版后最长要等半小时才能看到新版本。
- **修复**：
  - 缓存显示「已是最新」时，按 **5 分钟节流**自动回源 GitHub 复查一次；节流用 `cache()->add`
    原子占位，同一窗口内只回源一次，防止并发请求把匿名 API 60 次/小时的限流打爆；
  - 更新中心新增「**检查更新**」按钮：立即清掉 releases 缓存强制回源，不想等节流窗口就点它。

### v1.4.8 — GitHub CDN 传输截断导致校验失败

- **故障**：线上服务器从 GitHub 下载 23 MB 安装包时传输被截断，SHA-256 校验失败，更新卡在下载环节。
- **修复**：下载完成后做**三重校验**——字节数预检（与 Release asset 声明大小比对）→ SHA-256 →
  zip 完整性；任一未通过自动重试，**共 3 次**。残缺包一律删除重下，不会留下半截包死锁。

### v1.4.9 — 本地上传安装包被 12 MB 限制挡住

- **故障**：后台「本地上传安装包」传 23 MB 的包直接被拒，报
  `must not be greater than 12288 kilobytes`（Livewire 临时上传默认限制 12 MB）。
- **修复**：`UpdateCenterServiceProvider` 启动时把 `livewire.temporary_file_upload.rules`
  放开到 `file|max:204800`（200 MB）。

---

## 十、相关文件

| 文件 | 作用 |
|---|---|
| `scripts/build-release.php` | 构建脚本（复制 → composer --no-dev → 打 zip → 体积报告） |
| `scripts/verify-release.php` | 包完整性校验（18 项） |
| `scripts/verify-swap.php` | 覆盖语义校验（16 项，调真实 `SelfUpdater::swap()`） |
| `config/version.php` | 版本号唯一来源 |
| `Modules/UpdateCenter/app/Services/SelfUpdater.php` | 后台更新执行器（下载/解压/覆盖/迁移/清缓存） |
| `docs/update-center.md` | 更新中心使用指南（后台怎么配 GitHub 仓库与 Token） |
| `docs/deployment-bt.md` | 全新站点部署（宝塔） |
| `docs/site-move.md` | 换服务器/换域名迁移清单 |
