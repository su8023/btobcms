# 访客语言自动检测

> 发布于 v1.1.8。访客首次访问无语言前缀页面时，自动 302 到对应语言版本。

## 工作方式

**检测顺序**（命中即停）：

1. 已带语言前缀（`/es/...`）→ 用户显式选择，不干预
2. 会话已检测过 → 不再跳转（用户手动切换语言优先）
3. 搜索引擎/社交爬虫（Googlebot、WhatsApp、Telegram 等）→ 不跳转
4. 浏览器 `Accept-Language` 匹配站点语言（`es-MX` → `es`）→ 跳转
5. 浏览器语言不支持（如 ja/zh/ko）→ 查来源 IP 国家 → 映射语言 → 跳转
6. 都不匹配 → 停留在默认语言 en

**跳转保留深链**：`/blog/foo` → `/es/blog/foo`（查询串一并保留）。

## 技术要点

| 项 | 说明 |
|---|---|
| 中间件 | `app/Http/Middleware/DetectVisitorLocale.php`，仅挂在默认语言（无前缀）路由组 |
| SEO 安全 | 302 临时跳转 + `Vary: Accept-Language` 响应头（谷歌多语言规范）；爬虫一律不跳 |
| IP→国家 | 优先读 Cloudflare 的 `CF-IPCountry` 头（套 CF 时零开销）；否则查 ip-api.com 免费接口 |
| 缓存 | 同一 IP 的地理查询结果缓存 30 天；查询失败缓存 1 天空值，避免接口故障时每次请求外呼 |
| 一次性 | 检测结果写入会话 `locale_detected`，每会话只跳一次 |

## 国家→语言映射

- **es**：西班牙 + 全部拉美（MX/AR/CO/CL/PE 等 20 国）
- **pt**：巴西、葡萄牙、安哥拉、莫桑比克
- **fr**：法国、比利时、卢森堡、摩纳哥
- **de**：德国、奥地利、列支敦士登、瑞士
- **it**：意大利、圣马力诺
- **ru**：俄罗斯、白俄、哈萨克、乌兹别克等 7 国
- **ar**：沙特、阿联酋、埃及等 16 个阿拉伯国家
- **en**：其余所有国家（含中国——前台无中文版）

新增映射改 `DetectVisitorLocale::COUNTRY_LOCALE_MAP` 常量即可。

## 已知边界

- 真实浏览器**总是**发送 `Accept-Language`，所以 IP 检测只在「浏览器语言不在站点 8 语言内」或「无头请求（curl 等）」时生效——这是有意设计：浏览器语言代表用户显式偏好，优先级最高。
- 单元测试中 Symfony 客户端默认注入 `Accept-Language: en-us`，测 IP 分支需显式传不支持的值（如 `zz`），见 `tests/Feature/DetectVisitorLocaleTest.php`。
- 若未来在 nginx 层开启整页缓存（fastcgi_cache），需对未登录 GET 请求跳过缓存或排除 302，否则首个访客的重定向会被缓存给所有人。
