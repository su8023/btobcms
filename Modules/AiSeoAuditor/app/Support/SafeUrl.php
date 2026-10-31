<?php

namespace Modules\AiSeoAuditor\Support;

/**
 * 巡检抓取白名单（SSRF 防护）。
 *
 * 站点地址来自插件中心的自由文本，sitemap 里的 <loc> 更是外部可控数据，
 * 直接拿去请求会把服务器变成内网 / 云元数据（169.254.169.254）的跳板。
 * 因此每一次真正发出的请求前都必须过这道闸：
 *   1. 协议只允许 http / https（挡掉 javascript:、data:、file: 等伪协议）；
 *   2. 主机名必须与配置的 base_url 完全一致（只放行完全相同的主机名，不放行子域）；
 *   3. 主机名解析出的 IP 不能落在回环 / 内网 / 链路本地 / 组播 / 保留网段
 *      （防止域名指向 169.254.169.254，也防 DNS 重绑定）。
 */
class SafeUrl
{
    /**
     * 任何情况下都不允许访问的 IPv4 网段。
     *
     * 链路本地（169.254/16，云元数据 169.254.169.254 在此段）即便开启
     * allow_private_hosts 也不放行 —— 它会直接泄露云凭据。
     */
    private const ALWAYS_BLOCKED_V4_CIDRS = [
        '0.0.0.0/8',       // 本网络 / 未指定
        '169.254.0.0/16',  // 链路本地（云元数据）
        '192.0.0.0/24',    // IETF 协议保留
        '224.0.0.0/4',     // 组播
        '240.0.0.0/4',     // 保留（含 255.255.255.255）
    ];

    /**
     * 默认不允许、但可用 allow_private_hosts 放行的 IPv4 网段。
     *
     * 含回环：本地开发常用 http://127.0.0.1:8000 巡检，
     * 放行后由于端口也必须与站点地址一致，不会波及本机其它服务
     * （Redis 6379 / MySQL 3306 等端口不匹配，一样被拦）。
     */
    private const PRIVATE_V4_CIDRS = [
        '10.0.0.0/8',      // 私有网段
        '100.64.0.0/10',   // 运营商级 NAT
        '127.0.0.0/8',     // 回环
        '172.16.0.0/12',   // 私有网段
        '192.168.0.0/16',  // 私有网段
        '198.18.0.0/15',   // 基准测试
    ];

    /** 任何情况下都不允许访问的 IPv6 网段 */
    private const ALWAYS_BLOCKED_V6_CIDRS = [
        '::/128',
        '::ffff:0:0/96',
        'fe80::/10',
        'ff00::/8',
    ];

    /** 默认不允许、可配置放行的 IPv6 网段（含回环 ::1） */
    private const PRIVATE_V6_CIDRS = [
        '::1/128',
        'fc00::/7',
    ];

    /** 配置的站点地址 */
    private readonly string $baseUrl;

    /** 站点主机名（小写），懒解析 */
    private ?string $baseHost = null;

    /** 站点端口，懒解析 */
    private ?int $basePort = null;

    /** 最近一次拒绝的原因 */
    private ?string $lastReason = null;

    /** 同一主机名只解析一次，避免每个页面都打一次 DNS */
    private array $resolved = [];

    public function __construct(string $baseUrl)
    {
        $this->baseUrl = trim($baseUrl);
    }

    /**
     * 这个 URL 允许抓取吗？不允许时用 reason() 取具体原因。
     */
    public function isAllowed(string $url): bool
    {
        $this->lastReason = null;

        $url = trim($url);

        if ($url === '') {
            return $this->reject('空地址');
        }

        // 空白与反斜杠在 PHP / Guzzle 的 URL 解析上语义不一致，直接拒掉避免绕过
        if (preg_match('/[\s\\\\]/', $url) === 1) {
            return $this->reject('地址含空白或反斜杠');
        }

        $parts = parse_url($url);

        if (! is_array($parts)) {
            return $this->reject('地址格式非法');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        if (! in_array($scheme, ['http', 'https'], true)) {
            return $this->reject('协议不被允许：'.($scheme !== '' ? $scheme : '（缺失）'));
        }

        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));

        if ($host === '') {
            return $this->reject('缺少主机名');
        }

        $baseHost = $this->baseHost();

        if ($baseHost === '') {
            return $this->reject('站点地址无效，无法确定允许的主机名');
        }

        // 只放行与站点地址完全一致的主机名（不放行子域，避免 *.站点域名 被注册后绕过）
        if ($host !== $baseHost) {
            return $this->reject('主机不在允许列表：'.$host);
        }

        // 端口也必须一致：
        // 只校验主机名的话，同主机的其它端口（本机 Redis 6379 / MySQL 3306…）
        // 会被 sitemap 里的 <loc> 指过去，等于开了一条内网通道。
        if ($this->portOf($parts) !== $this->basePort()) {
            return $this->reject('端口不在允许列表');
        }

        if (! self::allowPrivateHosts()) {
            foreach ($this->resolve($host) as $ip) {
                if (self::isBlockedIp($ip)) {
                    return $this->reject('域名解析到内网地址：'.$ip);
                }
            }
        } else {
            // 放行内网地址时，回环与链路本地（云元数据）依然不放行
            foreach ($this->resolve($host) as $ip) {
                if (self::isAlwaysBlockedIp($ip)) {
                    return $this->reject('域名解析到受限地址：'.$ip);
                }
            }
        }

        return true;
    }

    /**
     * 最近一次拒绝的原因（中文，可直接写进日志 / 报告）。
     */
    public function reason(): string
    {
        return $this->lastReason ?? '';
    }

    /**
     * 仅判断协议是不是 http/https——后台报告渲染链接时用（不涉及主机名白名单）。
     */
    public static function isHttpUrl(string $url): bool
    {
        $url = trim($url);

        if ($url === '' || preg_match('/[\s\\\\]/', $url) === 1) {
            return false;
        }

        $parts = parse_url($url);

        if (! is_array($parts)) {
            return false;
        }

        return in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true);
    }

    /**
     * 站点主机名（取不到时返回空串）。
     */
    private function baseHost(): string
    {
        if ($this->baseHost !== null) {
            return $this->baseHost;
        }

        $parts = parse_url($this->baseUrl);

        // 站点地址漏写协议时补一个再解析，只为拿到主机名
        if (! is_array($parts) || ! isset($parts['host'])) {
            $parts = parse_url('https://'.$this->baseUrl);
        }

        $this->baseHost = is_array($parts) ? strtolower(rtrim((string) ($parts['host'] ?? ''), '.')) : '';

        return $this->baseHost;
    }

    /**
     * 站点端口（未显式指定时按协议取默认 80/443）。
     */
    private function basePort(): int
    {
        if ($this->basePort !== null) {
            return $this->basePort;
        }

        $parts = parse_url($this->baseUrl);

        if (! is_array($parts) || ! isset($parts['host'])) {
            $parts = parse_url('https://'.$this->baseUrl);
        }

        $this->basePort = $this->portOf(is_array($parts) ? $parts : []);

        return $this->basePort;
    }

    /**
     * 从解析结果里取端口（未指定时按 scheme 取默认）。
     */
    private function portOf(array $parts): int
    {
        if (isset($parts['port'])) {
            return (int) $parts['port'];
        }

        return strtolower((string) ($parts['scheme'] ?? 'https')) === 'http' ? 80 : 443;
    }

    /**
     * 解析主机名得到的所有 IP（IPv4 + IPv6）；解析不到返回空数组。
     *
     * @return string[]
     */
    private function resolve(string $host): array
    {
        if (isset($this->resolved[$host])) {
            return $this->resolved[$host];
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $this->resolved[$host] = [$host];
        }

        $ips = [];

        $ipv4 = @gethostbynamel($host);

        if (is_array($ipv4)) {
            $ips = array_merge($ips, $ipv4);
        }

        if (function_exists('dns_get_record')) {
            $ipv6 = @dns_get_record($host, DNS_AAAA);

            if (is_array($ipv6)) {
                foreach ($ipv6 as $record) {
                    if (! empty($record['ipv6'])) {
                        $ips[] = $record['ipv6'];
                    }
                }
            }
        }

        return $this->resolved[$host] = array_values(array_unique($ips));
    }

    /**
     * 这个 IP 是否属于禁止访问的网段（按当前 allow_private_hosts 档位）。
     */
    private static function isBlockedIp(string $ip): bool
    {
        if (self::isAlwaysBlockedIp($ip)) {
            return true;
        }

        if (self::allowPrivateHosts()) {
            return false;
        }

        foreach (self::PRIVATE_V4_CIDRS as $cidr) {
            if (self::inCidr($ip, $cidr)) {
                return true;
            }
        }

        foreach (self::PRIVATE_V6_CIDRS as $cidr) {
            if (self::inCidr($ip, $cidr)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 任何情况下都不允许访问的地址（回环 / 链路本地 / 未指定 / 组播 / 保留）。
     */
    private static function isAlwaysBlockedIp(string $ip): bool
    {
        foreach (self::ALWAYS_BLOCKED_V4_CIDRS as $cidr) {
            if (self::inCidr($ip, $cidr)) {
                return true;
            }
        }

        foreach (self::ALWAYS_BLOCKED_V6_CIDRS as $cidr) {
            if (self::inCidr($ip, $cidr)) {
                return true;
            }
        }

        return false;
    }

    /**
     * IP 是否落在 CIDR 网段内（inet_pton 二进制比较，IPv4/IPv6 通用）。
     */
    private static function inCidr(string $ip, string $cidr): bool
    {
        [$network, $bits] = array_pad(explode('/', $cidr, 2), 2, '32');

        $ipBin = @inet_pton($ip);
        $netBin = @inet_pton($network);

        if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) {
            return false;
        }

        $bits = (int) $bits;
        $fullBytes = intdiv($bits, 8);
        $restBits = $bits % 8;

        if ($fullBytes > 0 && strncmp($ipBin, $netBin, $fullBytes) !== 0) {
            return false;
        }

        if ($restBits > 0) {
            $mask = ~((1 << (8 - $restBits)) - 1) & 0xFF;

            if ((ord($ipBin[$fullBytes]) & $mask) !== (ord($netBin[$fullBytes]) & $mask)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 是否允许访问内网地址（仅供站点本身部署在内网/本机的场景，由配置显式开启）。
     */
    private static function allowPrivateHosts(): bool
    {
        return function_exists('config') && (bool) config('aiseoauditor.allow_private_hosts', false);
    }

    private function reject(string $reason): bool
    {
        $this->lastReason = $reason;

        return false;
    }
}
