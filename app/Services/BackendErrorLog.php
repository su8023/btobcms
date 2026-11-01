<?php

namespace App\Services;

/**
 * 后台错误诊断（v1.4.29）：把「后台请求失败」的异常从日志尾部读出来，
 * 让管理员在后台页面直接看到原因，不必 SSH 去看 laravel.log。
 *
 * WHY 需要：Filament 把组件异常统一显示成「页面加载出错，请稍后重试」，
 * 通用文案让排障只能靠猜（本轮内链词典报错即如此：本地不复现、线上必现）。
 * 本类提供只读查询入口，配合 bootstrap/app.php 的 reportable 钩子留痕。
 *
 * WHY 一律按「字节」处理：Windows 下 laravel.log 可能是 GBK 字节，
 * 用 UTF-8 正则 / json_decode 解析会整行失配，诊断记录被静默漏掉。
 * 因此：标记按字节匹配、字段按字节截取，只在交给视图之前才转成合法 UTF-8。
 *
 * v1 初版踩过的三个坑（都已修）：
 *   1) 标记后面是 " GET /admin/xxx"，中间有一个空格；旧正则
 *      `^(?<method>[A-Z]+)\s+` 顶着一个前导空格匹配 → 整行匹配失败 → 一条都读不出来。
 *   2) JSON 上下文紧跟 URL，URL 必须按「空白或 '{' 即止」截取，否则会把
 *      {"exception":...} 一起吞进 URL。
 *   3) Laravel 的 LineFormatter 开了 allowInlineLineBreaks：JSON 上下文里的 \n
 *      会被还原成真实换行，一条记录被拆成多行。只按物理行解析会丢掉后半段字段，
 *      因此这里会把未闭合的记录往下补行再解析。
 */
class BackendErrorLog
{
    /** 读取日志文件（storage/logs/laravel.log） */
    private const LOG_FILE = 'laravel.log';

    /**
     * 诊断标记：日志里的这一行是我们埋的。
     * 刻意用纯 ASCII：Windows 下日志可能是 GBK 字节，中文字面标记会因编码不同而匹配不上，
     * 导致诊断记录被静默漏掉（这是本类自身踩过的坑）。
     */
    private const MARKER = '[backend-request-failed]';

    /**
     * 旧版中文标记（ASCII 标记之前用的）：仍要能读出来、也能被清掉，
     * 否则历史日志里的失败记录既看不到也清不掉。
     * 以 UTF-8 / GB18030 两种字节形态参与匹配。
     */
    private const LEGACY_MARKER = '[后台请求失败]';

    /** 最多读取的日志字节数（避免大文件全量读进内存） */
    private const MAX_BYTES = 512 * 1024;

    /** 一条记录最多向下补多少行（异常消息里带换行时记录会被拆行） */
    private const MAX_CONTINUATION_LINES = 200;

    /**
     * 最近的后台失败记录（倒序，最新在前）。
     *
     * @return list<array{time: string, method: string, url: string, exception: string, message: string, status: int|null, at: string}>
     */
    public static function recent(int $limit = 20): array
    {
        if ($limit <= 0) {
            return [];
        }

        $lines = self::tailLines(self::logPath());

        if ($lines === []) {
            return [];
        }

        $entries = [];
        $total = count($lines);

        for ($i = 0; $i < $total; $i++) {
            if (self::findMarker($lines[$i]) === null) {
                continue;
            }

            $record = $lines[$i];
            $parts = self::splitRecord($record);

            if ($parts === null) {
                continue;
            }

            // 记录可能被拆成多行（见类注释里的第 3 个坑）：
            // JSON 上下文没闭合就往下补行，直到闭合为止。
            $last = $i;

            while (self::jsonUnclosed($parts['context']) && ($last - $i) < self::MAX_CONTINUATION_LINES && $last + 1 < $total) {
                $next = $lines[$last + 1];

                // 下一行自带标记 → 那是新的一条记录，不能并进来
                if (self::findMarker($next) !== null) {
                    break;
                }

                $last++;
                $record .= "\n".$next;
                $parts = self::splitRecord($record);

                if ($parts === null) {
                    break;
                }
            }

            $entry = self::buildEntry($parts);

            if ($entry !== null) {
                $entries[] = $entry;
            }

            $i = $last;
        }

        // 日志尾部即最新 → 反转成「最新在前」
        $entries = array_reverse($entries);

        return array_slice($entries, 0, $limit);
    }

    /**
     * 清空诊断记录（只清我们埋的这一类，不动其他日志）。
     *
     * WHY 逐行过滤重写（而不是整块正则删）：整块正则一旦碰上多行堆栈，
     * 会把别的日志连着删掉；逐行判断只摘掉「含诊断标记」的那几行。
     *
     * WHY 覆写而不是 rename：Monolog 长期持有日志文件句柄，
     * rename 换 inode 之后它会继续往旧 inode 写，新内容立刻丢失。
     * 这里 truncate + 覆写同一个 inode，句柄继续有效。
     *
     * @return int 被清掉的记录行数
     */
    public static function clear(): int
    {
        $path = self::logPath();

        if (! is_file($path) || ! is_readable($path)) {
            return 0;
        }

        $source = @fopen($path, 'rb');

        if ($source === false) {
            return 0;
        }

        $tempPath = $path.'.clear-'.getmypid().'.tmp';
        $temp = @fopen($tempPath, 'wb');

        if ($temp === false) {
            fclose($source);

            return 0;
        }

        $removed = 0;

        while (($line = fgets($source)) !== false) {
            if (self::findMarker($line) !== null) {
                $removed++;

                continue;
            }

            fwrite($temp, $line);
        }

        fclose($source);
        fclose($temp);

        if ($removed === 0) {
            @unlink($tempPath);

            return 0;
        }

        // 覆写原文件（保持 inode，Monolog 的句柄继续有效）
        $target = @fopen($path, 'r+b');

        if ($target === false) {
            @unlink($tempPath);

            return 0;
        }

        $ok = ftruncate($target, 0) && rewind($target);

        if ($ok) {
            $input = @fopen($tempPath, 'rb');

            if ($input !== false) {
                while (! feof($input)) {
                    $buffer = fread($input, 65536);

                    if ($buffer === false) {
                        break;
                    }

                    if ($buffer !== '') {
                        fwrite($target, $buffer);
                    }
                }

                fclose($input);
            }

            fflush($target);
        }

        fclose($target);
        @unlink($tempPath);

        return $ok ? $removed : 0;
    }

    /**
     * 把可能是 GBK / 其它编码的字节转成合法 UTF-8。
     *
     * 供两处使用：写入前把异常消息洗成合法 UTF-8（否则 json_encode 会失败、
     * 上下文整个丢失），读取后把展示用文本洗成合法 UTF-8（否则 Blade 输出乱码）。
     */
    public static function toUtf8(string $value): string
    {
        if ($value === '' || mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        foreach (['GB18030', 'BIG-5', 'Windows-1252'] as $encoding) {
            $converted = @mb_convert_encoding($value, 'UTF-8', $encoding);

            if (is_string($converted) && $converted !== '' && mb_check_encoding($converted, 'UTF-8')) {
                return $converted;
            }
        }

        // 兜底：逐字节映射，保证输出一定是合法 UTF-8
        return (string) mb_convert_encoding($value, 'UTF-8', 'ISO-8859-1');
    }

    /**
     * 把异常消息压成单行。
     *
     * WHY 需要：Laravel 的 LineFormatter 开了 allowInlineLineBreaks，
     * 上下文里的 \n 会被还原成真实换行 —— 一条日志记录被拆成多行，
     * 逐行清理会漏掉后半段，逐行解析也会丢掉后半段字段。
     * 这里把真实换行换成字面量 "\n"（两个字符），JSON 依然合法、
     * 记录依然是单行，排障时也能看出原来断在哪。
     */
    public static function singleLine(string $value): string
    {
        return str_replace(["\r\n", "\r", "\n"], '\\n', $value);
    }

    /**
     * 只读日志尾部（最多 MAX_BYTES 字节），返回完整行数组（原始字节，不做编码转换）。
     *
     * @return list<string>
     */
    private static function tailLines(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            return [];
        }

        $size = (int) @filesize($path);

        if ($size <= 0) {
            return [];
        }

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return [];
        }

        $readBytes = min($size, self::MAX_BYTES);
        $offset = $size - $readBytes;

        if ($offset > 0 && fseek($handle, $offset) !== 0) {
            fclose($handle);

            return [];
        }

        $chunk = (string) fread($handle, $readBytes);
        fclose($handle);

        if ($chunk === '') {
            return [];
        }

        // 从文件中间开始读时，首行是残缺的（前半截在读取窗口外），丢弃
        if ($offset > 0) {
            $newline = strpos($chunk, "\n");

            if ($newline === false) {
                return [];
            }

            $chunk = substr($chunk, $newline + 1);
        }

        $lines = preg_split('/\r\n|\n|\r/', $chunk);

        return $lines === false ? [] : $lines;
    }

    /**
     * 把一条日志记录拆成 time / method / url / 上下文。
     *
     * 不是我们的记录（或格式不对）返回 null —— 单行失败不中断整批。
     *
     * @return array{time: string, method: string, url: string, context: string}|null
     */
    private static function splitRecord(string $record): ?array
    {
        $marker = self::findMarker($record);

        if ($marker === null) {
            return null;
        }

        [$markerPos, $markerLen] = $marker;
        $head = substr($record, 0, $markerPos);
        $rest = substr($record, $markerPos + $markerLen);

        // 时间：[2026-11-02 10:00:00]
        $time = '';

        if (preg_match('/^\[(?<t>[^\]\r\n]{1,64})\]/', $head, $tm) === 1) {
            $time = trim($tm['t']);
        }

        // METHOD URL：标记与 METHOD 之间有一个空格（消息是 "[标记] GET /admin/xxx"），
        // 旧正则漏掉这个前导空格 → 整行匹配失败 → 一条都读不出来（v1 的主 bug）。
        if (preg_match('/^[ \t]*(?<method>[A-Z]{2,10})[ \t]+(?<url>\S+)/', $rest, $um, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $url = $um['url'][0];
        $urlOffset = $um['url'][1];
        $context = substr($rest, $urlOffset + strlen($url));

        // URL 后面若直接粘着 JSON（{"exception":...}，中间没有空格），
        // 以 '{' 为界切开：'{' 之前算 URL，其余算上下文。
        $brace = strpos($url, '{');

        if ($brace !== false) {
            $url = substr($url, 0, $brace);
            $context = substr($rest, $urlOffset + $brace);
        }

        return [
            'time' => $time,
            'method' => $um['method'][0],
            'url' => $url,
            'context' => $context,
        ];
    }

    /**
     * 由拆好的记录拼出展示用的结构。
     *
     * @param  array{time: string, method: string, url: string, context: string} $parts
     * @return array{time: string, method: string, url: string, exception: string, message: string, status: int|null, at: string}
     */
    private static function buildEntry(array $parts): array
    {
        $fields = self::parseFields($parts['context']);

        return [
            'time' => self::toUtf8($parts['time']),
            'method' => $parts['method'],
            'url' => self::toUtf8($parts['url']),
            'exception' => self::toUtf8((string) ($fields['exception'] ?? '')),
            'message' => self::toUtf8((string) ($fields['message'] ?? '')),
            'status' => isset($fields['status']) && $fields['status'] !== ''
                ? (int) $fields['status']
                : null,
            'at' => self::toUtf8((string) ($fields['at'] ?? '')),
        ];
    }

    /**
     * 取上下文字段：优先标准 JSON（转义正确），失败再退回逐字段正则（不依赖编码）。
     *
     * @return array<string, string>
     */
    private static function parseFields(string $context): array
    {
        $context = trim($context);

        if ($context === '') {
            return [];
        }

        [$json] = self::scanJsonObject($context);

        if ($json !== null) {
            $decoded = json_decode($json, true);

            if (is_array($decoded)) {
                $fields = [];

                foreach ($decoded as $key => $value) {
                    $fields[(string) $key] = is_scalar($value) ? (string) $value : '';
                }

                return $fields;
            }
        }

        // JSON 不可用（GBK 字节 / 被拆行 / 消息里有裸换行）时退回逐字段正则
        return self::parseFieldsLoosely($context);
    }

    /**
     * 扫描片段里最外层 JSON 对象的边界（花括号配对，字符串里的括号不计入）。
     *
     * @return array{0: string|null, 1: bool} [JSON 文本, 是否未闭合]
     */
    private static function scanJsonObject(string $context): array
    {
        $start = strpos($context, '{');

        if ($start === false) {
            return [null, false];
        }

        $depth = 0;
        $inString = false;
        $escaped = false;
        $length = strlen($context);

        for ($i = $start; $i < $length; $i++) {
            $char = $context[$i];

            if ($escaped) {
                $escaped = false;

                continue;
            }

            if ($char === '\\') {
                if ($inString) {
                    $escaped = true;
                }

                continue;
            }

            if ($char === '"') {
                $inString = ! $inString;

                continue;
            }

            if ($inString) {
                continue;
            }

            if ($char === '{') {
                $depth++;

                continue;
            }

            if ($char === '}') {
                $depth--;

                if ($depth === 0) {
                    return [substr($context, $start, $i - $start + 1), false];
                }
            }
        }

        // 没扫到闭合的 '{' → 记录被拆行了
        return [null, $depth > 0];
    }

    /**
     * 上下文里的 JSON 对象是否还没闭合（记录被拆行时为 true）。
     */
    private static function jsonUnclosed(string $context): bool
    {
        [, $unclosed] = self::scanJsonObject($context);

        return $unclosed;
    }

    /**
     * 宽松字段解析：GBK 日志的中文字段会让 json_decode 失败，
     * 这里按 "key":"value" 逐字段取出，不依赖编码。
     *
     * 同名键取「第一次」出现的：外层字段在 JSON 文本里总在消息内容之前，
     * 消息正文里出现的 "status" 之类不能覆盖外层字段。
     *
     * @return array<string, string>
     */
    private static function parseFieldsLoosely(string $context): array
    {
        $fields = [];

        $matched = preg_match_all(
            '/"(?<k>[A-Za-z_][A-Za-z0-9_]*)"\s*:\s*"(?<v>(?:[^"\\\\]|\\\\.)*)"/s',
            $context,
            $matches,
            PREG_SET_ORDER
        );

        if (is_int($matched) && $matched > 0) {
            foreach ($matches as $field) {
                if (! array_key_exists($field['k'], $fields)) {
                    $fields[$field['k']] = self::unescape($field['v']);
                }
            }
        }

        // status 是数字，不带引号
        if (! array_key_exists('status', $fields)
            && preg_match('/"status"\s*:\s*(?<v>-?\d+)/', $context, $status) === 1) {
            $fields['status'] = $status['v'];
        }

        return $fields;
    }

    /**
     * 还原 JSON 字符串里的转义序列（宽松解析路径用）。
     */
    private static function unescape(string $value): string
    {
        if (! str_contains($value, '\\')) {
            return $value;
        }

        return (string) preg_replace_callback(
            '/\\\\(u[0-9a-fA-F]{4}|.)/s',
            static function (array $matches): string {
                $sequence = $matches[1];

                switch ($sequence[0]) {
                    case 'n':
                        return "\n";
                    case 'r':
                        return "\r";
                    case 't':
                        return "\t";
                    case 'b':
                        return "\x08";
                    case 'f':
                        return "\x0C";
                    case 'u':
                        $code = (int) hexdec(substr($sequence, 1));

                        if ($code <= 0x7F) {
                            return chr($code);
                        }

                        if ($code <= 0x7FF) {
                            return chr(0xC0 | ($code >> 6)).chr(0x80 | ($code & 0x3F));
                        }

                        return chr(0xE0 | ($code >> 12))
                            .chr(0x80 | (($code >> 6) & 0x3F))
                            .chr(0x80 | ($code & 0x3F));
                    default:
                        return $sequence;
                }
            },
            $value
        );
    }

    /**
     * 在一行日志里定位诊断标记（按字节匹配，GBK 日志也能命中）。
     *
     * @return array{0: int, 1: int}|null [起始偏移, 标记长度]
     */
    private static function findMarker(string $line): ?array
    {
        $best = null;

        foreach (self::markerPatterns() as $pattern) {
            $position = strpos($line, $pattern);

            if ($position !== false && ($best === null || $position < $best[0])) {
                $best = [$position, strlen($pattern)];
            }
        }

        return $best;
    }

    /**
     * 标记的字节形态：ASCII 主标记 + 旧版中文标记（UTF-8 / GB18030）。
     *
     * @return list<string>
     */
    private static function markerPatterns(): array
    {
        static $patterns = null;

        if ($patterns !== null) {
            return $patterns;
        }

        $patterns = [self::MARKER];

        foreach (['UTF-8', 'GB18030'] as $encoding) {
            $encoded = @mb_convert_encoding(self::LEGACY_MARKER, $encoding, 'UTF-8');

            if (is_string($encoded) && $encoded !== '' && ! in_array($encoded, $patterns, true)) {
                $patterns[] = $encoded;
            }
        }

        return $patterns;
    }

    private static function logPath(): string
    {
        return storage_path('logs/'.self::LOG_FILE);
    }
}
