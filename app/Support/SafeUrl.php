<?php

namespace App\Support;

/**
 * 出站 URL 安全校验：防止 SSRF 与凭据外泄。
 *
 * 典型场景：后台可填写「AI 接口地址」「SEO 巡检站点地址」，
 * 这些值最终会带着 API Key / 服务端身份去发起请求。
 * 若不校验，一个被改坏的地址就能把密钥送到攻击者手里，
 * 或让服务端去打云元数据地址（169.254.169.254）等内网敏感端点。
 *
 * 校验内容：
 *   - scheme 必须是 http / https（挡掉 file:、gopher:、data: 等）；
 *   - 必须有 host；
 *   - 解析出的 IP 不得落在 loopback / 链路本地 / 未指定 / 组播 等保留段
 *     （169.254.0.0/16 就是各家云厂商的元数据地址）。
 */
class SafeUrl
{
    /**
     * 主机名 → 已解析 IP 的进程内缓存。
     *
     * LLM 调用是高频路径（批量翻译/填充一次几十次），每次都做
     * gethostbynamel + dns_get_record 会白白多花几十毫秒 DNS 往返。
     */
    private static array $dnsCache = [];

    /**
     * @param  bool  $allowPrivateHosts  是否允许内网地址（10/8、172.16/12、192.168/16）。
     *                                   自建 LLM 常见于局域网，需要放行；
     *                                   但链路本地与回环地址在任何情况下都不放行。
     */
    public static function isAllowed(string $url, bool $allowPrivateHosts = false): bool
    {
        return self::reason($url, $allowPrivateHosts) === null;
    }

    /**
     * 返回拒绝原因；null 表示通过。
     */
    public static function reason(string $url, bool $allowPrivateHosts = false): ?string
    {
        $url = trim($url);

        if ($url === '') {
            return '地址为空';
        }

        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['host']) || $parts['host'] === '') {
            return '地址格式非法（缺少主机名）';
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        if (! in_array($scheme, ['http', 'https'], true)) {
            return "协议 {$scheme} 不被允许（只支持 http/https）";
        }

        $host = $parts['host'];

        foreach (self::resolveIps($host) as $ip) {
            if ($reason = self::blockedIpReason($ip, $allowPrivateHosts)) {
                return $reason;
            }
        }

        return null;
    }

    /**
     * 解析主机名对应的全部 IP 字面量（字面量 IP 直接返回）。
     *
     * @return list<string>
     */
    public static function resolveIps(string $host): array
    {
        $host = trim($host, '[]');

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        if (isset(self::$dnsCache[$host])) {
            return self::$dnsCache[$host];
        }

        $ips = [];

        $v4 = @gethostbynamel($host);

        if (is_array($v4)) {
            $ips = $v4;
        }

        $v6 = @dns_get_record($host, DNS_AAAA);

        if (is_array($v6)) {
            foreach ($v6 as $record) {
                if (isset($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        return self::$dnsCache[$host] = array_values(array_unique($ips));
    }

    /**
     * 判断某个 IP 是否属于禁止访问的保留段。
     */
    public static function blockedIpReason(string $ip, bool $allowPrivateHosts = false): ?string
    {
        $packed = @inet_pton($ip);

        if ($packed === false) {
            return "无法解析的 IP：{$ip}";
        }

        // IPv4
        if (strlen($packed) === 4) {
            // unpack('C*') 返回 1 起始的键，array_values 后为 0 起始：[a, b, c, d]
            $octets = array_values(unpack('C*', $packed));
            $a = $octets[0];
            $b = $octets[1];

            if ($a === 127) {
                return "回环地址不可访问：{$ip}";
            }

            if ($a === 0) {
                return "未指定地址不可访问：{$ip}";
            }

            if ($a === 169 && $b === 254) {
                return "链路本地地址不可访问（云元数据端点）：{$ip}";
            }

            if ($a >= 224 && $a <= 239) {
                return "组播地址不可访问：{$ip}";
            }

            if (! $allowPrivateHosts && (
                $a === 10
                || ($a === 172 && $b >= 16 && $b <= 31)
                || ($a === 192 && $b === 168)
            )) {
                return "内网地址不可访问：{$ip}";
            }

            return null;
        }

        // IPv6
        $hex = bin2hex($packed);

        if ($hex === str_repeat('0', 31).'1') {
            return "回环地址不可访问：{$ip}";
        }

        if (str_starts_with($hex, 'fe8') || str_starts_with($hex, 'fe9')
            || str_starts_with($hex, 'fea') || str_starts_with($hex, 'feb')) {
            return "链路本地地址不可访问：{$ip}";
        }

        if (str_starts_with($hex, 'fc') || str_starts_with($hex, 'fd')) {
            if (! $allowPrivateHosts) {
                return "内网地址不可访问：{$ip}";
            }
        }

        if ($hex[0] === 'f' && $hex[1] === 'f') {
            return "组播地址不可访问：{$ip}";
        }

        return null;
    }
}
