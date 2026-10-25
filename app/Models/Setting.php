<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    protected $fillable = ['site_id', 'key', 'value'];

    protected function casts(): array
    {
        return [
            'value' => 'array',
        ];
    }

    public static function get(string $key, mixed $default = null, ?int $siteId = null): mixed
    {
        $setting = static::query()
            ->where(function ($q) use ($siteId) {
                $q->whereNull('site_id');
                if ($siteId) {
                    $q->orWhere('site_id', $siteId);
                }
            })
            ->where('key', $key)
            ->orderByDesc('site_id')
            ->first();

        return $setting?->value ?? $default;
    }

    public static function put(string $key, mixed $value, ?int $siteId = null): void
    {
        static::updateOrCreate(
            ['site_id' => $siteId, 'key' => $key],
            ['value' => $value],
        );

        Cache::forget("settings.{$siteId}.{$key}");
    }
}
