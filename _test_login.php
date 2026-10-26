<?php

/**
 * 模拟 Filament 登录（Livewire 3 update 协议），诊断服务端登录是否可用。
 * 用法：php _test_login.php [email] [password] [host]
 */

$email = $argv[1] ?? 'tbskhk@gmail.com';
$pass = $argv[2] ?? '8789.com';
$host = $argv[3] ?? 'http://www.aples.xyz';

$jar = __DIR__.'/_cookies.txt';
@unlink($jar);

// 1. GET 登录页：拿 cookie + csrf token + livewire snapshot
$ch = curl_init("$host/admin/login");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEJAR => $jar,
    CURLOPT_COOKIEFILE => $jar,
    CURLOPT_TIMEOUT => 20,
]);
$html = (string) curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);
echo "GET /admin/login → $code (", strlen($html), " bytes)\n";

preg_match('/name="csrf-token" content="([^"]+)"/', $html, $t);
$csrf = $t[1] ?? '';
echo "csrf: ", $csrf !== '' ? 'OK' : 'MISSING', "\n";

preg_match('/wire:snapshot="([^"]+)"/', $html, $s);
$snapshot = html_entity_decode($s[1] ?? '', ENT_QUOTES | ENT_HTML5);
if ($snapshot === '') {
    echo "未找到 Livewire snapshot——页面不是 Livewire 组件？\n";
    exit(1);
}
$snap = json_decode($snapshot, true);
echo "component: ", $snap['name'] ?? '?', "\n";

// 2. POST /livewire/update 调用 authenticate
$payload = [
    '_token' => $csrf,
    'components' => [[
        'snapshot' => $snapshot,
        'updates' => [
            'data.email' => $email,
            'data.password' => $pass,
        ],
        'calls' => [
            ['path' => '', 'method' => 'authenticate', 'params' => []],
        ],
    ]],
];

$ch = curl_init("$host/livewire-ab1055d7/update");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEJAR => $jar,
    CURLOPT_COOKIEFILE => $jar,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
]);
$response = (string) curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);
echo "POST /livewire/update → $code\n";

$json = json_decode($response, true);
if ($json === null) {
    echo "响应非 JSON：", mb_substr($response, 0, 300), "\n";
    exit(1);
}

$comp = $json['components'][0] ?? [];
$effects = $comp['effects'] ?? [];
$returns = $comp['returns'] ?? [];

if (isset($effects['redirect'])) {
    echo "登录成功 ✓ 重定向 → ", is_array($effects['redirect']) ? ($effects['redirect']['url'] ?? '?') : $effects['redirect'], "\n";
} elseif (! empty($comp['snapshots'][0]['effects']['errors']) || ! empty($effects['errors'])) {
    $errors = $effects['errors'] ?? $comp['snapshots'][0]['effects']['errors'] ?? [];
    echo "登录被拒 ✗ 错误：", json_encode($errors, JSON_UNESCAPED_UNICODE), "\n";
} else {
    echo "无重定向亦无错误：", mb_substr(json_encode(['effects' => array_keys($effects), 'returns' => $returns], JSON_UNESCAPED_UNICODE), 0, 300), "\n";
}

@unlink($jar);
