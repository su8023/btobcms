<?php

namespace Modules\GeoBoost\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Modules\GeoBoost\Models\AiVisit;
use Throwable;

/**
 * AI 引擎来访追踪：referer 命中 AI 引擎清单时记录一次落地浏览。
 * 零外部调用，单条 INSERT；不命中零开销（仅一次 parse_url）。
 *
 * 计数口径（这是本模块唯一的 KPI，必须干净）：
 *   - 爬虫/预览机器人不算引流：只看 referer 会把链接预览抓取器、AI 搜索爬虫
 *     全记成"ChatGPT 引流"；
 *   - 同一访客在去重窗口内只记一次：真人点进来翻 10 个页面不该算 10 次访问。
 */
class TrackAiReferrer
{
    /**
     * 已知爬虫/预览机器人 UA 特征（子串匹配，大小写不敏感）。
     * 覆盖主流 AI 抓取器 + 链接预览 + 通用扫描器。
     *
     * @var list<string>
     */
    protected const BOT_UA_PATTERNS = [
        // AI 抓取器
        'gptbot', 'oai-searchbot', 'chatgpt-user', 'claudebot', 'claude-web',
        'anthropic-ai', 'perplexitybot', 'perplexity-user', 'google-extended',
        'meta-externalagent', 'applebot', 'amazonbot', 'cohere-ai', 'youbot',
        // 搜索引擎 / 链接预览 / 通用爬虫
        'bot', 'crawler', 'spider', 'slurp', 'preview', 'fetcher', 'scraper',
        'facebookexternalhit', 'twitterbot', 'linkedinbot', 'telegrambot',
        'discordbot', 'slackbot', 'skypeuripreview',
        // 命令行 / 脚本工具
        'curl', 'wget', 'python-requests', 'python-urllib', 'go-http-client',
        'okhttp', 'java/', 'libwww-perl', 'headlesschrome', 'phantomjs',
    ];

    /**
     * 同 IP 同引擎的去重窗口（秒）：窗口内只记一次访问。
     *
     * 默认 0 = 【关闭去重】，每次页面访问都记录（保持看板"访问次数"的原始口径）。
     * 需要按访客去重时把 geo-boost.dedup_seconds 设成正数（如 1800）。
     *
     * 为什么默认关闭：站点在 CDN 后面时 $request->ip() 拿到的是 CDN 边缘节点 IP
     * （未配 TRUSTED_PROXIES 时更是如此），同一窗口内所有访客会共用同一个 IP，
     * 开启去重会把真实访客塌缩成一次 —— 看板数据反而失真。
     */
    protected const DEDUP_SECONDS = 0;

    public function handle(Request $request, Closure $next): mixed
    {
        $response = $next($request);

        try {
            $referer = (string) $request->header('referer', '');
            if ($referer === '' || ! $request->isMethod('GET') || $request->expectsJson()) {
                return $response;
            }

            // 爬虫/预览机器人不算引流：只按 referer 判断会把抓取器全记成 AI 引流
            if ($this->isBot((string) $request->userAgent())) {
                return $response;
            }

            $host = strtolower((string) parse_url($referer, PHP_URL_HOST));
            // 去掉 www. 前缀再匹配
            $host = preg_replace('/^www\./', '', $host) ?? $host;

            $engines = (array) config('geo-boost.engines', []);
            $engine = null;
            foreach ($engines as $fragment => $name) {
                if (str_contains($host, (string) $fragment)) {
                    $engine = $name;
                    break;
                }
            }

            // 自家域名上的引用不算引擎引流：严格相等比较（str_contains 会把
            // app.url 为空时的空 needle 当作命中、或短域名误杀相似域名）
            $appHost = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
            $isSelfReferral = $appHost !== '' && strcasecmp($host, $appHost) === 0;

            if ($engine === null || $isSelfReferral) {
                return $response;
            }

            // 同一访客在去重窗口内只记一次：真人翻 10 个页面不该算 10 次访问
            if (! $this->isFirstVisit($request->ip(), (string) $engine, (string) $request->userAgent())) {
                return $response;
            }

            AiVisit::create([
                'engine' => $engine,
                'path' => mb_substr($request->path(), 0, 500),
                'referer' => mb_substr($referer, 0, 500),
                'created_at' => now(),
            ]);
        } catch (Throwable) {
            // 追踪失败绝不影响页面响应
        }

        return $response;
    }

    /**
     * 是否是已知爬虫/预览机器人。
     * UA 为空时不武断判定为机器人（宁可漏记也不要误杀真人）。
     */
    protected function isBot(string $userAgent): bool
    {
        if ($userAgent === '') {
            return false;
        }

        $userAgent = strtolower($userAgent);
        foreach (self::BOT_UA_PATTERNS as $pattern) {
            if (str_contains($userAgent, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 去重标记：同一访客 + 同一引擎在窗口内只算一次访问。
     * 返回 true = 本次是窗口内的首次访问（应当记录）。
     */
    protected function isFirstVisit(?string $ip, string $engine, string $userAgent = ''): bool
    {
        $seconds = $this->dedupSeconds();

        // 未开启去重：照常记录，保持看板口径不变
        if ($seconds <= 0) {
            return true;
        }

        try {
            // 哈希键里不留明文 IP；带上 UA 是为了在 CDN 场景下区分不同访客
            // （同一 IP 下不同浏览器不应被合并成一次访问）
            $key = 'geoboost:aivisit:'.sha1((string) $ip.'|'.$engine.'|'.$userAgent);

            return Cache::add($key, 1, $seconds);
        } catch (Throwable) {
            // 缓存不可用时退化为不拦截：照常记录，别丢数据
            return true;
        }
    }

    /**
     * 去重窗口（秒）。
     */
    protected function dedupSeconds(): int
    {
        return (int) config('geo-boost.dedup_seconds', self::DEDUP_SECONDS);
    }
}
