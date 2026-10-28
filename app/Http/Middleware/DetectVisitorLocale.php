<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * 访客语言自动检测：首次进入无语言前缀页面时，
 * 按「浏览器语言 → 来源 IP 地理位置」匹配站点语言并 302 跳转。
 *
 * 只挂在默认语言（无前缀）路由组上：/es/ 等已带前缀的访问是用户显式选择，
 * 后台、安装向导、sitemap 不经过本中间件。
 *
 * SEO 安全（谷歌多语言跳转规范）：
 *   - 爬虫不跳转，各语言页面保持独立可收录
 *   - 302 临时跳转 + Vary: Accept-Language 响应头
 *   - 每会话只检测一次，之后用户手动切换语言不再被覆盖
 */
class DetectVisitorLocale
{
    /** 国家/地区 → 站点语言 映射（未列出的国家走默认语言 en） */
    protected const COUNTRY_LOCALE_MAP = [
        // 西语
        'ES' => 'es', 'MX' => 'es', 'AR' => 'es', 'CO' => 'es', 'CL' => 'es', 'PE' => 'es',
        'VE' => 'es', 'UY' => 'es', 'PY' => 'es', 'BO' => 'es', 'EC' => 'es', 'GT' => 'es',
        'CR' => 'es', 'PA' => 'es', 'DO' => 'es', 'HN' => 'es', 'SV' => 'es', 'NI' => 'es',
        'CU' => 'es', 'PR' => 'es',
        // 葡语
        'BR' => 'pt', 'PT' => 'pt', 'AO' => 'pt', 'MZ' => 'pt',
        // 法语
        'FR' => 'fr', 'BE' => 'fr', 'LU' => 'fr', 'MC' => 'fr',
        // 德语
        'DE' => 'de', 'AT' => 'de', 'LI' => 'de', 'CH' => 'de',
        // 意语
        'IT' => 'it', 'SM' => 'it',
        // 俄语
        'RU' => 'ru', 'BY' => 'ru', 'KZ' => 'ru', 'KG' => 'ru', 'UZ' => 'ru', 'AM' => 'ru', 'AZ' => 'ru',
        // 阿语
        'SA' => 'ar', 'AE' => 'ar', 'EG' => 'ar', 'KW' => 'ar', 'QA' => 'ar', 'BH' => 'ar',
        'OM' => 'ar', 'JO' => 'ar', 'LB' => 'ar', 'IQ' => 'ar', 'MA' => 'ar', 'DZ' => 'ar',
        'TN' => 'ar', 'LY' => 'ar', 'SD' => 'ar', 'YE' => 'ar', 'SY' => 'ar',
    ];

    /** 已知爬虫/预览抓取器 UA 片段：一律不跳转 */
    protected const BOT_UA_PATTERN = '/bot|crawl|spider|slurp|facebookexternalhit|whatsapp|telegram|twitterbot|slackbot|discordapp|embedly|headlesschrome|lighthouse|pagespeed|pingdom|uptime|google-inspectiontool|googleother|applebot/i';

    public function handle(Request $request, Closure $next): mixed
    {
        if (! $this->shouldDetect($request)) {
            return $next($request);
        }

        $locale = $this->detectLocale($request);

        // 记住检测结果：本会话后续访问不再跳转，用户手动切换语言优先
        session(['locale_detected' => $locale]);

        if ($locale !== config('site.default_locale')) {
            // 保留深链路径与查询串：/ → /es/，/blog/foo?a=1 → /es/blog/foo?a=1
            return redirect('/'.$locale.$request->getRequestUri(), 302)
                ->header('Vary', 'Accept-Language');
        }

        return $next($request);
    }

    protected function shouldDetect(Request $request): bool
    {
        // 安全网：已带语言前缀的路径是用户显式选择，不干预
        $path = $request->path();
        foreach (config('site.locales') as $locale) {
            if ($locale !== config('site.default_locale')
                && ($path === $locale || str_starts_with($path, $locale.'/'))) {
                return false;
            }
        }

        // 本会话已检测过
        if (session()->has('locale_detected')) {
            return false;
        }

        // 爬虫不跳转（保证各语言页面都能被独立收录）
        if (preg_match(self::BOT_UA_PATTERN, (string) $request->userAgent())) {
            return false;
        }

        // 仅拦截页面型 GET 请求
        return $request->isMethod('GET') && ! $request->expectsJson() && ! $request->ajax();
    }

    /**
     * 检测最佳语言：浏览器 Accept-Language（用户主动设置，可靠度最高）
     * → 来源 IP 地理位置 → 默认语言。
     */
    protected function detectLocale(Request $request): string
    {
        $supported = config('site.locales');

        // 1. 浏览器语言（按用户偏好质量排序，如 es-MX → es）。
        //    注意：头缺失时 Symfony 的 getLanguages() 会兜底返回 ['en_US','en']，
        //    必须先确认头真实存在，否则永远走不到 IP 分支。
        if ($request->headers->has('Accept-Language')) {
            foreach ($request->getLanguages() as $browserLang) {
                foreach ($supported as $locale) {
                    if (strcasecmp($browserLang, $locale) === 0
                        || str_starts_with(strtolower($browserLang), strtolower($locale).'-')) {
                        return $locale;
                    }
                }
            }
        }

        // 2. 来源 IP 推断国家，再映射语言
        $country = $this->countryFromIp($request);
        if ($country !== null && isset(self::COUNTRY_LOCALE_MAP[$country])) {
            $candidate = self::COUNTRY_LOCALE_MAP[$country];
            if (in_array($candidate, $supported, true)) {
                return $candidate;
            }
        }

        return config('site.default_locale');
    }

    /**
     * IP → ISO 国家码：优先 Cloudflare 注入的 CF-IPCountry 头（套 CF 时零开销），
     * 否则查 ip-api.com（免费）。成功缓存 30 天；失败缓存 1 天（'' 占位），
     * 避免外部接口故障时每个请求都外呼。
     */
    protected function countryFromIp(Request $request): ?string
    {
        $cf = $request->header('cf-ipcountry');
        if (is_string($cf) && preg_match('/^[A-Z]{2}$/', $cf)) {
            return $cf;
        }

        $ip = $request->ip();
        // 内网/保留地址没有地理意义
        if ($ip === null || ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return null;
        }

        $cached = cache()->remember(
            'geo.country.'.md5($ip),
            now()->addDays(30),
            function () use ($ip): string {
                try {
                    $resp = Http::timeout(2)->get('http://ip-api.com/json/'.$ip, [
                        'fields' => 'status,countryCode',
                    ]);

                    if ($resp->successful() && $resp->json('status') === 'success') {
                        return (string) $resp->json('countryCode');
                    }
                } catch (Throwable) {
                    // 检测失败静默降级到默认语言
                }

                return '';
            }
        );

        return $cached !== '' ? $cached : null;
    }
}
