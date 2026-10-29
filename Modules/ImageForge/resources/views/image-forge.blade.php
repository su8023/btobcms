<div>
    <x-filament-panels::page>
        {{-- 统计卡片：图片总数 / 已有 webp 数 / 估算节省字节 --}}
        <div class="fi-section">
            <div class="grid gap-4 md:grid-cols-3">
                <div class="fi-widget p-4 rounded-xl bg-white shadow dark:bg-gray-900">
                    <div class="text-sm text-gray-500">网站图片总数</div>
                    <div class="text-3xl font-bold">{{ number_format($imageCount) }}</div>
                </div>
                <div class="fi-widget p-4 rounded-xl bg-white shadow dark:bg-gray-900">
                    <div class="text-sm text-gray-500">已优化（生成 WebP）</div>
                    <div class="text-3xl font-bold">{{ number_format($webpCount) }}</div>
                </div>
                <div class="fi-widget p-4 rounded-xl bg-white shadow dark:bg-gray-900">
                    <div class="text-sm text-gray-500">已为网站节省的图片体积</div>
                    <div class="text-3xl font-bold">{{ $savedBytes >= 1048576 ? number_format($savedBytes / 1048576, 1).' MB' : number_format($savedBytes / 1024, 1).' KB' }}</div>
                </div>
            </div>
        </div>

        <x-filament::section class="mt-6">
            <x-slot name="heading">批量转换</x-slot>
            @if ($imageCount === 0)
                {{-- 空态提示：public 盘尚无图片 --}}
                <p class="text-sm text-gray-500">
                    public 盘上还没有可转换的图片（jpg/jpeg/png）。上传附件后会自动生成 WebP 副本。
                </p>
            @else
                <p class="text-sm text-gray-500">
                    点击右上角「全量转换」按队列分批处理所有图片附件；已有 WebP 副本且未过期的会自动跳过。
                </p>
            @endif
        </x-filament::section>
    </x-filament-panels::page>
</div>
