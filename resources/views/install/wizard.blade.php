<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>BtoBcms 安装向导</title>
    <style>
        :root { --brand:#0b2545; --accent:#25549b; --gold:#f0a714; --ok:#16803c; --bad:#c0392b; --ink:#16233b; --muted:#5b6b84; --line:#dde5f0; --bg:#f4f7fb; }
        * { box-sizing:border-box; margin:0; padding:0; }
        body { font-family:-apple-system,"Segoe UI","Microsoft YaHei",sans-serif; background:var(--bg); color:var(--ink); line-height:1.65; }
        .top { background:var(--brand); color:#fff; padding:22px 0; }
        .top-inner { max-width:860px; margin:0 auto; padding:0 20px; display:flex; align-items:center; gap:12px; }
        .mark { background:var(--gold); color:var(--brand); font-weight:800; padding:4px 10px; border-radius:8px; }
        main { max-width:860px; margin:32px auto 60px; padding:0 20px; }
        .card { background:#fff; border:1px solid var(--line); border-radius:14px; padding:28px; box-shadow:0 8px 28px rgba(11,37,69,.07); }
        h1 { font-size:22px; margin-bottom:6px; }
        .sub { color:var(--muted); margin-bottom:22px; font-size:14px; }
        .steps { display:flex; gap:8px; margin-bottom:24px; }
        .steps span { flex:1; text-align:center; font-size:13px; padding:7px 4px; border-radius:8px; background:#eef2f8; color:var(--muted); }
        .steps span.on { background:var(--accent); color:#fff; font-weight:600; }
        .steps span.ok { background:#e2f4e8; color:var(--ok); }
        .check-grid { display:grid; grid-template-columns:1fr 1fr; gap:8px 22px; }
        .check { display:flex; justify-content:space-between; gap:10px; font-size:14px; padding:7px 10px; border-radius:8px; background:#f8fafd; }
        .check small { color:var(--muted); font-size:12px; }
        .pass { color:var(--ok); font-weight:700; }
        .fail { color:var(--bad); font-weight:700; }
        form { margin-top:20px; }
        fieldset { border:1px solid var(--line); border-radius:10px; padding:16px 18px; margin-bottom:18px; }
        legend { font-weight:700; padding:0 8px; font-size:14px; }
        .row { display:grid; grid-template-columns:1fr 1fr; gap:12px 16px; }
        label { display:block; font-size:13px; font-weight:600; margin:10px 0 4px; }
        label em { color:var(--bad); font-style:normal; }
        input[type=text], input[type=password], input[type=email], input[type=number] { width:100%; padding:9px 11px; border:1px solid var(--line); border-radius:8px; font-size:14px; }
        input:focus { outline:2px solid rgba(37,84,155,.25); border-color:var(--accent); }
        .hint { font-size:12px; color:var(--muted); margin-top:2px; }
        .checkline { display:flex; gap:8px; align-items:center; margin-top:14px; font-size:14px; }
        .btn { display:inline-block; margin-top:20px; background:var(--accent); color:#fff; border:0; border-radius:9px; padding:12px 30px; font-size:15px; font-weight:700; cursor:pointer; }
        .btn:hover { background:#1c4480; }
        .btn[disabled] { background:#9db1cc; cursor:not-allowed; }
        .err { background:#fdecea; border:1px solid #f5c6c0; color:var(--bad); border-radius:9px; padding:10px 14px; margin-bottom:16px; font-size:14px; }
        ul.done-list { margin:14px 0 0 18px; color:var(--muted); font-size:14px; }
        ul.done-list li { margin:4px 0; }
        code { background:#eef2f8; padding:1px 6px; border-radius:5px; font-size:13px; }
        .notice { background:#fff8e6; border:1px solid #f0dfa8; padding:10px 14px; border-radius:9px; font-size:13px; margin-top:16px; }

        /* 安装进行中动画 */
        .btn[disabled] { opacity:.8; cursor:wait; }
        .btn .btn-spinner {
            display:inline-block; width:14px; height:14px; margin-right:8px; vertical-align:-2px;
            border:2px solid rgba(255,255,255,.35); border-top-color:#fff; border-radius:50%;
            animation:icw-spin .8s linear infinite;
        }
        @keyframes icw-spin { to { transform:rotate(360deg); } }
        .installing-panel { text-align:center; padding:22px 10px 8px; color:var(--muted); font-size:14px; }
        .installing-panel .spinner {
            width:36px; height:36px; margin:0 auto 12px; border-radius:50%;
            border:3px solid #dbe6f4; border-top-color:var(--accent,#25549b);
            animation:icw-spin .9s linear infinite;
        }
        .installing-panel .bar {
            height:6px; margin-top:14px; border-radius:99px; overflow:hidden;
            background:#e8eef7;
        }
        .installing-panel .bar i {
            display:block; height:100%; width:40%; border-radius:99px;
            background:linear-gradient(90deg,#25549b,#6f9bea);
            animation:icw-slide 1.4s ease-in-out infinite;
        }
        @keyframes icw-slide { 0% { margin-left:-40%; } 100% { margin-left:100%; } }
        @media (max-width:640px){ .check-grid,.row{ grid-template-columns:1fr; } }
    </style>
</head>
<body>
<div class="top"><div class="top-inner"><span class="mark">B2B</span><strong>BtoBcms 安装向导</strong></div></div>
<main>

@if ($step === 'check')
    @php($failed = collect($checks)->where('pass', false)->count())
    <div class="card">
        <h1>第 1 步 · 环境检测</h1>
        <p class="sub">自动检查服务器环境与目录权限，全部通过即可进入下一步。</p>
        <div class="steps"><span class="on">① 环境检测</span><span>② 配置安装</span><span>③ 完成</span></div>

        @if (session('error'))<div class="err">{{ session('error') }}</div>@endif

        <div class="check-grid">
            @foreach ($checks as $c)
                <div class="check">
                    <span>{{ $c['label'] }}<br><small>{{ $c['hint'] }}</small></span>
                    <span class="{{ $c['pass'] ? 'pass' : 'fail' }}">{{ $c['pass'] ? '✔ 通过' : '✘ 未通过' }}</span>
                </div>
            @endforeach
        </div>

        @if ($failed === 0)
            <form method="get" action="{{ url('/install') }}#config">
                <button class="btn" type="submit">下一步 · 填写配置 →</button>
            </form>
        @else
            <p class="err" style="margin-top:18px">有 {{ $failed }} 项未通过：请按各项提示处理后刷新本页。宝塔操作见项目文档 docs/deployment-bt.md。</p>
            <a class="btn" style="background:#9db1cc; pointer-events:none; text-decoration:none">请先处理未通过项</a>
        @endif

        {{-- 通过后直接内联第 2 步表单，减少跳转 --}}
        @if ($failed === 0)
            <form method="post" action="{{ url('/install') }}" id="config" style="margin-top:34px">
                @csrf
                <h1 style="font-size:18px">第 2 步 · 配置安装</h1>

                @if ($errors->any())
                    <div class="err" style="margin-top:14px">
                        <strong>安装未完成：</strong>
                        <ul style="margin:6px 0 0 18px">
                            @foreach ($errors->all() as $err)
                                <li>{{ $err }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
                <p class="sub">数据库信息在宝塔「网站 → 数据库」可查；管理员账号用于登录 /admin 后台。</p>

                <fieldset>
                    <legend>数据库</legend>
                    <div class="row">
                        <div>
                            <label>类型</label>
                            <select name="db_connection" style="width:100%;padding:9px 11px;border:1px solid var(--line);border-radius:8px;font-size:14px">
                                <option value="mysql" selected>MySQL（推荐）</option>
                                <option value="sqlite">SQLite</option>
                            </select>
                        </div>
                        <div><label>数据库名 <em>*</em></label><input type="text" name="db_database" value="" required></div>
                        <div><label>主机</label><input type="text" name="db_host" value="127.0.0.1"></div>
                        <div><label>端口</label><input type="number" name="db_port" value="3306"></div>
                        <div><label>用户名</label><input type="text" name="db_username" value=""></div>
                        <div><label>密码</label><input type="password" name="db_password" value=""></div>
                    </div>
                    <p class="hint">仅支持安装到空数据库；已有 users 表的库会被拒绝，防止误覆盖。</p>
                </fieldset>

                <fieldset>
                    <legend>站点与管理员</legend>
                    <div class="row">
                        <div><label>站点名称 <em>*</em></label><input type="text" name="app_name" value="" required placeholder="例：Shenzhen Industrial Supply Co."></div>
                        <div><label>管理员姓名 <em>*</em></label><input type="text" name="admin_name" value="Admin" required></div>
                        <div><label>管理员邮箱 <em>*</em></label><input type="email" name="admin_email" value="" required></div>
                        <div><label>管理员密码 <em>*</em></label><input type="password" name="admin_password" required minlength="8"></div>
                    </div>
                    <label class="checkline"><input type="checkbox" name="seed_demo" value="1"> 导入演示分类/产品数据（正式站建议不勾）</label>
                </fieldset>

                <fieldset>
                    <legend>反向代理 / CDN（可选）</legend>
                    <label>可信代理 IP 段</label>
                    <input type="text" name="trusted_proxies" value="" placeholder="例：173.245.48.0/20,103.21.244.0/22（Cloudflare）或 *">
                    <p class="hint">站点套了 Cloudflare / nginx 反代时必填，否则询盘记录与访客语言检测拿到的是代理 IP。填 CDN 官方 IP 段（逗号分隔，见 cloudflare.com/ips-v4）；直连部署留空最安全（可防伪造 X-Forwarded-For 绕过限流）。</p>
                </fieldset>

                <button class="btn" type="submit" id="install-btn">开始安装（约 1 分钟）</button>
                <p class="hint">安装会写入 .env、创建数据表与管理员账号，并自动加锁防重复安装。</p>

                {{-- 提交后的安装中动画：禁用按钮防重复提交 --}}
                <div class="installing-panel" id="installing-panel" hidden>
                    <div class="spinner" aria-hidden="true"></div>
                    <p><strong>正在安装…</strong><br>创建数据表 · 写入配置 · 创建管理员，请勿关闭或刷新页面</p>
                    <div class="bar"><i></i></div>
                </div>

                <script>
                (function () {
                    var form = document.getElementById('config');
                    if (!form) return;
                    form.addEventListener('submit', function () {
                        var btn = document.getElementById('install-btn');
                        var panel = document.getElementById('installing-panel');
                        btn.disabled = true;
                        btn.innerHTML = '<span class="btn-spinner"></span> 正在安装，请稍候…';
                        panel.hidden = false;
                    });
                })();
                </script>
            </form>
        @endif
    </div>
@elseif ($step === 'done')
    <div class="card">
        <h1 style="color:var(--ok)">✔ 安装完成</h1>
        <p class="sub">BtoBcms 已就绪。</p>
        <div class="steps"><span class="ok">✔ 环境检测</span><span class="ok">✔ 配置安装</span><span class="ok">✔ 完成</span></div>
        <ul class="done-list">
            <li>后台登录：<code>{{ url('/admin') }}</code>（用刚创建的管理员邮箱 + 密码，支持中英文切换）</li>
            <li>插件中心：启用并配置 飞书通知 / 邮件通知 / AI 巡检 / WhatsApp</li>
            <li>全站设置：站点名、联系方式、社交链接（Organization sameAs）</li>
            <li>别忘了：宝塔「进程守护管理器」跑队列 worker + 计划任务每分钟 schedule:run（通知与每日巡检靠它们）</li>
            <li>申请 SSL 证书并开启强制 HTTPS</li>
            @if ($optimize === 'ok')
                <li>✔ 性能缓存已在安装时自动生成（config / event / view / route），无需任何命令行操作</li>
            @else
                <li>性能优化（可选，SSH 执行）：<code>cd 站点目录 && php artisan optimize</code></li>
            @endif
        </ul>
        <div class="notice">安装锁已写入 <code>storage/app/installed.lock</code>，<code>/install</code> 入口自动关闭。如需重装，删除该锁文件并清空数据库。日后修改 .env 后，SSH 执行 <code>php artisan config:cache</code> 刷新配置缓存。</div>
        <a class="btn" href="{{ url('/') }}" style="text-decoration:none">访问网站首页 →</a>
    </div>
@endif

</main>
</body>
</html>
