<?php

namespace Modules\AiContentWriter\Support;

/**
 * 从大模型回复里提取 JSON 对象。
 *
 * 旧实现用贪婪正则 '/\{.*\}/s'：从第一个 { 一直吃到最后一个 }。
 * 模型只要在 JSON 前后多说一句话（例如 "Sure — {plan} here: {...}"），
 * 抽出来的就不是合法 JSON，json_decode 得到 null，
 * 用户只看到"AI 返回的方案无法解析"，且原始回复也没被留存便于排查。
 *
 * 这里改成按括号配对扫描（跳过字符串字面量里的括号），并优先剥离 ```json 围栏。
 */
class JsonExtractor
{
    /**
     * 提取第一个完整的 JSON 对象；找不到时返回 null。
     */
    public static function extract(string $raw): ?string
    {
        $raw = self::stripFence($raw);

        $length = strlen($raw);

        for ($i = 0; $i < $length; $i++) {
            if ($raw[$i] !== '{') {
                continue;
            }

            $json = self::balanced($raw, $i);

            if ($json !== null) {
                return $json;
            }
        }

        return null;
    }

    /**
     * 剥离 markdown 代码围栏（```json ... ```），保留围栏内的内容。
     */
    protected static function stripFence(string $raw): string
    {
        if (preg_match('/```[a-zA-Z]*\s*(.*?)\s*```/s', $raw, $m) === 1) {
            return trim($m[1]);
        }

        return trim($raw);
    }

    /**
     * 从 $start（必须指向 '{'）开始按配对扫描，返回完整对象；括号不配对返回 null。
     */
    protected static function balanced(string $raw, int $start): ?string
    {
        $length = strlen($raw);
        $depth = 0;
        $inString = false;
        $escaped = false;

        for ($i = $start; $i < $length; $i++) {
            $char = $raw[$i];

            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($char === '"') {
                $inString = true;

                continue;
            }

            if ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;

                if ($depth === 0) {
                    return substr($raw, $start, $i - $start + 1);
                }
            }
        }

        return null;
    }
}
