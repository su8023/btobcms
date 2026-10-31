<?php

/*
|--------------------------------------------------------------------------
| 可信反向代理（CDN / 负载均衡）
|--------------------------------------------------------------------------
|
| 这里用 Laravel 内置的 trustedproxy 配置，而不是在 bootstrap/app.php 里调
| trustProxies()：
|
|   1. bootstrap/app.php 的 withMiddleware 回调执行时 config 还没加载，
|      在那里读 config() 会直接抛 "Target class [config] does not exist"；
|   2. config:cache 之后 .env 不再被加载，此处 env() 的结果会被烘进配置缓存，
|      行为稳定且可在生产用 config:cache 提速。
|
| TRUSTED_PROXIES 填写 CDN 官方 IP 段（逗号分隔），例如 Cloudflare：
|   TRUSTED_PROXIES=173.245.48.0/20,103.21.244.0/22,103.22.200.0/22, ...
|
| 留空 = 不信任任何代理（默认，最安全）：
|   · 询盘记录的 IP 是真实连接 IP（CDN 场景下是 CDN 节点 IP）
|   · X-Forwarded-For 不会被采信，客户端无法伪造 IP 绕过限流
|
| 填 * = 信任任意代理：客户端可伪造 X-Forwarded-For，限流形同虚设，
| 仅在确实所有流量都经过可信代理时才可用。
|
*/

return [
    'proxies' => env('TRUSTED_PROXIES'),

    // 采信的代理头集合；null = 用 Laravel 默认（X-Forwarded-For / Host / Proto / Port / Prefix）
    'headers' => null,
];
