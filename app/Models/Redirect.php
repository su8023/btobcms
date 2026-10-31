<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Redirect extends Model
{
    protected $fillable = ['site_id', 'from_path', 'to_path', 'code'];

    protected function casts(): array
    {
        return [
            'code' => 'integer',
        ];
    }

    /**
     * 301 规则的缓存键。
     *
     * 用 sha1 而不是原始路径：路径长度不可控（扫描器会构造超长 URL），
     * 而 database 缓存驱动的 key 列只有 255 字符，超长键会直接报错。
     */
    public static function cacheKey(string $path): string
    {
        return 'redirect:'.sha1($path);
    }
}
