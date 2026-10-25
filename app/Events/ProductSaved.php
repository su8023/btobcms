<?php

namespace App\Events;

use App\Models\Product;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * 产品创建/更新（后台保存后触发）。
 * 插件接入示例：电商平台同步、价格变更通知、搜索索引重建。
 */
class ProductSaved
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Product $product,
        public readonly bool $created,
    ) {
    }
}
