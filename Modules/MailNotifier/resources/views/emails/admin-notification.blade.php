<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>新询盘通知</title>
</head>
<body style="margin:0;padding:0;background-color:#f3f4f6;font-family:Arial,'Microsoft YaHei',Helvetica,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f3f4f6;">
    <tr>
        <td align="center" style="padding:24px 12px;">
            <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;background-color:#ffffff;border-radius:6px;">
                <tr>
                    <td style="padding:20px 32px;background-color:#b45309;border-radius:6px 6px 0 0;color:#ffffff;font-size:18px;font-weight:bold;">
                        新询盘 #{{ $inquiry->id }}（{{ $siteName }}）
                    </td>
                </tr>
                <tr>
                    <td style="padding:24px 32px 8px;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;font-size:14px;color:#111827;">
                            <tr>
                                <td style="padding:8px 12px;width:110px;background-color:#f9fafb;border:1px solid #e5e7eb;color:#6b7280;white-space:nowrap;">客户</td>
                                <td style="padding:8px 12px;border:1px solid #e5e7eb;">{{ $inquiry->name ?: '—' }}</td>
                            </tr>
                            <tr>
                                <td style="padding:8px 12px;background-color:#f9fafb;border:1px solid #e5e7eb;color:#6b7280;white-space:nowrap;">邮箱</td>
                                <td style="padding:8px 12px;border:1px solid #e5e7eb;">
                                    @if ($inquiry->email)
                                        <a href="mailto:{{ $inquiry->email }}" style="color:#1d4ed8;">{{ $inquiry->email }}</a>
                                    @else
                                        —
                                    @endif
                                </td>
                            </tr>
                            <tr>
                                <td style="padding:8px 12px;background-color:#f9fafb;border:1px solid #e5e7eb;color:#6b7280;white-space:nowrap;">电话</td>
                                <td style="padding:8px 12px;border:1px solid #e5e7eb;">{{ $inquiry->phone ?: '—' }}</td>
                            </tr>
                            <tr>
                                <td style="padding:8px 12px;background-color:#f9fafb;border:1px solid #e5e7eb;color:#6b7280;white-space:nowrap;">公司</td>
                                <td style="padding:8px 12px;border:1px solid #e5e7eb;">{{ $inquiry->company ?: '—' }}</td>
                            </tr>
                            <tr>
                                <td style="padding:8px 12px;background-color:#f9fafb;border:1px solid #e5e7eb;color:#6b7280;white-space:nowrap;">国家</td>
                                <td style="padding:8px 12px;border:1px solid #e5e7eb;">{{ $inquiry->country ?: '—' }}</td>
                            </tr>
                            <tr>
                                <td style="padding:8px 12px;background-color:#f9fafb;border:1px solid #e5e7eb;color:#6b7280;white-space:nowrap;">UTM 来源</td>
                                <td style="padding:8px 12px;border:1px solid #e5e7eb;">
                                    @php
                                        $utmText = collect($utm)->map(function ($value, $key) {
                                            return $key.'='.(is_scalar($value) ? (string) $value : json_encode($value));
                                        })->implode(', ');
                                    @endphp
                                    {{ $utmText !== '' ? $utmText : '—' }}
                                </td>
                            </tr>
                            <tr>
                                <td style="padding:8px 12px;background-color:#f9fafb;border:1px solid #e5e7eb;color:#6b7280;white-space:nowrap;">落地页</td>
                                <td style="padding:8px 12px;border:1px solid #e5e7eb;">
                                    @if ($inquiry->landing_url)
                                        <a href="{{ $inquiry->landing_url }}" style="color:#1d4ed8;word-break:break-all;">{{ $inquiry->landing_url }}</a>
                                    @else
                                        —
                                    @endif
                                </td>
                            </tr>
                        </table>

                        @if ($products->isNotEmpty())
                            <p style="margin:20px 0 6px;font-size:14px;color:#111827;"><strong>关联产品</strong></p>
                            <ul style="margin:0;padding-left:20px;font-size:14px;color:#374151;line-height:1.7;">
                                @foreach ($products as $productName)
                                    <li>{{ $productName }}</li>
                                @endforeach
                            </ul>
                        @endif

                        <p style="margin:20px 0 6px;font-size:14px;color:#111827;"><strong>留言全文</strong></p>
                        <div style="padding:12px 16px;background-color:#f9fafb;border:1px solid #e5e7eb;border-radius:4px;font-size:14px;line-height:1.7;color:#111827;">
                            {!! nl2br(e($inquiry->message ?? '')) !!}
                        </div>
                    </td>
                </tr>
                <tr>
                    <td style="padding:16px 32px 24px;font-size:12px;line-height:1.6;color:#6b7280;">
                        本邮件由 MailNotifier 插件在收到 InquiryCreated 事件时自动发送，请及时到后台跟进处理。
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
