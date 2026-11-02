<?php

namespace Modules\AiContentWriter\Support;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Modules\AiContentWriter\Jobs\TranslateRecordJob;
use Modules\AiContentWriter\Services\CategoryTranslator;
use Modules\AiContentWriter\Services\ProductTranslator;
use Nwidart\Modules\Facades\Module;

/**
 * 记录级 AI 翻译的共享入口（产品 / 分类一键翻译）。
 *
 * 统一「缺几种」口径与「排队派发」逻辑，供表格徽标列与行/批量动作复用：
 *   - 缺失语言 = 主字段（name）在该语言下没有译文的语言集合；
 *     主字段是表单必填列，因此「翻译完成」= 每个语言都有 name，语义直观、幂等可重入。
 *   - 派发按语言逐个入 ai-translate 队列（与文章翻译同通道），并登记 queued 进度。
 *
 * 新增内容类型只需往 $map 里加一行（模型 + 翻译器 + 主字段）。
 */
class RecordTranslation
{
    /**
     * type => [模型, 翻译器, 主字段]
     *
     * @var array<string, array{0: class-string<Model>, 1: class-string, 2: string}>
     */
    protected static array $map = [
        'product' => [Product::class, ProductTranslator::class, 'name'],
        'category' => [Category::class, CategoryTranslator::class, 'name'],
    ];

    /**
     * 实体类型是否可用：AiContentWriter 插件已启用且翻译器类可解析。
     * 门禁检查模块启用状态（isEnabled），只靠 class_exists 会在插件停用后仍放行；
     * 模块被删除时 isEnabled 会抛异常，按不可用处理。
     */
    public static function available(string $type): bool
    {
        $entry = self::$map[$type] ?? null;

        if ($entry === null) {
            return false;
        }

        try {
            if (! Module::isEnabled('AiContentWriter')) {
                return false;
            }
        } catch (\Throwable) {
            return false;
        }

        return class_exists($entry[1]);
    }

    /**
     * 该记录尚未翻译（主字段为空）的语言列表。
     * 一次 getTranslations 读整份 JSON（表格列渲染每次轮询都会调，避免逐 locale 反复解码）。
     *
     * @return list<string>
     */
    public static function missingLocales(Model $record, string $titleField): array
    {
        $translations = $record->getTranslations($titleField);
        $missing = [];

        foreach (site_locales() as $locale) {
            if (trim((string) ($translations[$locale] ?? '')) === '') {
                $missing[] = $locale;
            }
        }

        return $missing;
    }

    /** 源语言：默认语言优先，缺失则取第一个有主字段译文的语言；全无返回 null */
    public static function sourceLocale(Model $record, string $titleField): ?string
    {
        $default = default_locale();

        if (trim((string) $record->getTranslation($titleField, $default, false)) !== '') {
            return $default;
        }

        foreach (site_locales() as $locale) {
            if (trim((string) $record->getTranslation($titleField, $locale, false)) !== '') {
                return $locale;
            }
        }

        return null;
    }

    /**
     * 列表页「翻译」徽标取值（列 + 行动作共用一次口径）。
     * 无缺口 → 完整（绿）；有缺口无任务 → 缺 N 种（琥珀）；有任务在途 → 缺 N 种 ⟳M（蓝）。
     *
     * @return array{label: string, color: string, hasGap: bool}
     */
    public static function badge(Model $record, string $type): array
    {
        $titleField = self::titleField($type) ?? 'name';
        $missing = self::missingLocales($record, $titleField);

        if ($missing === []) {
            return ['label' => __('admin.common.translation_full'), 'color' => 'success', 'hasGap' => false];
        }

        $inFlight = 0;

        foreach (TranslationProgress::forEntity($type, (int) $record->getKey()) as $entry) {
            if (in_array($entry['state'], [TranslationProgress::QUEUED, TranslationProgress::RUNNING], true)) {
                $inFlight++;
            }
        }

        $label = __('admin.common.translation_missing', ['missing' => count($missing)]);

        if ($inFlight > 0) {
            $label .= ' '.__('admin.common.translation_inflight', ['inflight' => $inFlight]);
        }

        return [
            'label' => $label,
            'color' => $inFlight > 0 ? 'info' : 'warning',
            'hasGap' => true,
        ];
    }

    /**
     * 为一条记录排队缺失语言的翻译任务（每语言一个队列任务 + 登记 queued 进度）。
     *
     * @return int 实际入队的语言数（0 表示无缺口或无源语言可译）
     */
    public static function enqueue(Model $record, string $type): int
    {
        $entry = self::$map[$type] ?? null;

        if ($entry === null || ! self::available($type)) {
            return 0;
        }

        [, , $titleField] = $entry;
        $id = (int) $record->getKey();

        // 无源语言可译（主字段在所有语言都空）：跳过，避免每个语言都「缺源」失败
        if (self::sourceLocale($record, $titleField) === null) {
            return 0;
        }

        $missing = self::missingLocales($record, $titleField);

        // 重新排队前清掉旧的终态记录，进度徽标从零开始
        TranslationProgress::clear($id, $type);

        foreach ($missing as $locale) {
            TranslationProgress::mark($id, $locale, TranslationProgress::QUEUED, '', $type);
            TranslateRecordJob::dispatch($id, $locale, $type);
        }

        return count($missing);
    }

    /** 是否有该类型可用的翻译器（表格隐藏动作/徽标用） */
    public static function translatorFor(string $type): ?string
    {
        return self::$map[$type][1] ?? null;
    }

    /** 主字段名 */
    public static function titleField(string $type): ?string
    {
        return self::$map[$type][2] ?? null;
    }

    /**
     * 解析某类型的完整配置：[模型类, 翻译器类, 主字段]；未知类型返回 null。
     *
     * @return array{0: class-string<Model>, 1: class-string, 2: string}|null
     */
    public static function resolve(string $type): ?array
    {
        return self::$map[$type] ?? null;
    }
}
