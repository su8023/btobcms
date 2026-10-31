<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Spatie\Translatable\HasTranslations;

class Attribute extends Model
{
    use HasTranslations;

    /** 允许的 code 形态：小写字母 / 数字 / 下划线 */
    public const CODE_PATTERN = '/^[a-z0-9_]+$/';

    protected $fillable = [
        'attr_set_id', 'code', 'name', 'type', 'unit', 'options',
        'is_filterable', 'is_required', 'sort_order',
    ];

    public array $translatable = ['name'];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'is_filterable' => 'boolean',
            'is_required' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // code 会作为 JSON 路径参与查询拼接，任何非白名单形态一律拒绝落库
        static::saving(function (self $attribute): void {
            $code = is_string($attribute->code) ? strtolower(trim($attribute->code)) : '';

            $validator = Validator::make(
                ['code' => $code],
                ['code' => ['required', 'string', 'max:64', 'regex:'.self::CODE_PATTERN]],
                ['code.regex' => '属性 code 只能包含小写字母、数字和下划线。'],
            );

            if ($validator->fails()) {
                throw new \Illuminate\Validation\ValidationException($validator);
            }

            $attribute->code = $code;
        });
    }

    public function attrSet()
    {
        return $this->belongsTo(AttrSet::class);
    }
}
