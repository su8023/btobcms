{{-- WhatsApp 浮动客服挂件（由 Provider 注入到 floating 插槽，布局 footer 处渲染） --}}
{{-- 渲染时自检：enabled=false 或号码为空则不输出任何内容。
    Provider 在 boot 阶段无条件注册本视图（测试/Octane 中 boot 时数据库尚未就绪，
    可见性判断必须在渲染时进行） --}}
@php
    $waEnabled = plugin_setting('whatsapp-chat', 'enabled', true);
    $waPhone = trim((string) plugin_setting('whatsapp-chat', 'phone', ''));
    $waBubble = trim((string) plugin_setting('whatsapp-chat', 'bubble', ''));
    $waDelay = max(0, (int) plugin_setting('whatsapp-chat', 'delay_seconds', 0));
@endphp
@if($waEnabled && $waPhone !== '')
    @php
        // wa.me 要求纯数字国际号码（如 85291986504）：
        // 去掉 + 号、空格、连字符、括号——带 + 的链接在安卓 WhatsApp 上打不开会话
        $waPhoneDigits = preg_replace('/[^0-9]/', '', $waPhone);
        $waUrl = 'https://wa.me/'.$waPhoneDigits.'?text='.urlencode((string) plugin_setting('whatsapp-chat', 'message', ''));
        $waLabel = $waBubble !== '' ? $waBubble : 'Chat on WhatsApp';
    @endphp
    <style>
        .wa-float {
            position: fixed;
            /* 逻辑属性：RTL 布局下自动停靠到物理左侧 */
            inset-inline-end: 20px;
            bottom: 20px;
            z-index: 40;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 56px;
            height: 56px;
            border-radius: 50%;
            background-color: #25D366;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.25);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            -webkit-tap-highlight-color: transparent;
        }
        .wa-float:hover,
        .wa-float:focus-visible {
            transform: scale(1.06);
            box-shadow: 0 6px 16px rgba(0, 0, 0, 0.3);
        }
        .wa-float__icon {
            display: block;
            width: 30px;
            height: 30px;
            fill: #ffffff;
            pointer-events: none;
        }
        /* 提示气泡：停靠在按钮的逻辑左侧（inset-inline-end: 100% 之外），
           RTL 下自动翻转到正确一侧，不使用物理 left/right */
        .wa-float__bubble {
            position: absolute;
            inset-inline-end: calc(100% + 14px);
            top: 50%;
            transform: translateY(-50%);
            white-space: nowrap;
            padding: 6px 12px;
            border-radius: 999px;
            background-color: #ffffff;
            color: #075E54;
            font-size: 14px;
            font-weight: 600;
            line-height: 1.3;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.18);
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.2s ease, visibility 0.2s ease;
            pointer-events: none;
        }
        /* 气泡小箭头：位于气泡靠近按钮的一侧（inset-inline-start 方向） */
        .wa-float__bubble::after {
            content: "";
            position: absolute;
            inset-inline-start: -4px;
            top: 50%;
            width: 8px;
            height: 8px;
            background-color: #ffffff;
            border-radius: 2px;
            transform: translateY(-50%) rotate(45deg);
        }
        .wa-float:hover .wa-float__bubble,
        .wa-float:focus-visible .wa-float__bubble {
            opacity: 1;
            visibility: visible;
        }
        /* 移动端缩小 */
        @media (max-width: 640px) {
            .wa-float {
                width: 48px;
                height: 48px;
            }
            .wa-float__icon {
                width: 26px;
                height: 26px;
            }
        }
        /* 尊重系统减少动态效果设置 */
        @media (prefers-reduced-motion: reduce) {
            .wa-float,
            .wa-float__bubble {
                transition: none;
            }
            .wa-float:hover,
            .wa-float:focus-visible {
                transform: none;
            }
        }
    </style>
    <a class="wa-float" href="{{ $waUrl }}" target="_blank" rel="noopener" aria-label="{{ $waLabel }}"
        @if($waDelay > 0) style="display: none" data-wa-delay="{{ $waDelay }}" @endif
    >
        <svg class="wa-float__icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">
            <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/>
        </svg>
        <span class="wa-float__bubble">{{ $waBubble }}</span>
    </a>
    <script>
        (function () {
            var btn = document.querySelector(".wa-float");
            if (!btn) return;
            // {page} 占位符：点击时替换为当前页 URL（含询盘来源页面追踪价值）
            btn.addEventListener("click", function () {
                if (btn.href.indexOf("%7Bpage%7D") !== -1) {
                    btn.href = btn.href.replace("%7Bpage%7D", encodeURIComponent(location.href));
                }
            });
            // 延迟显示
            var delay = parseInt(btn.getAttribute("data-wa-delay") || "0", 10);
            if (delay > 0) {
                setTimeout(function () { btn.style.display = "inline-flex"; }, delay * 1000);
            }
        })();
    </script>
@endif
