<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>We have received your inquiry</title>
</head>
<body style="margin:0;padding:0;background-color:#f3f4f6;font-family:Arial,Helvetica,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f3f4f6;">
    <tr>
        <td align="center" style="padding:24px 12px;">
            <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;background-color:#ffffff;border-radius:6px;">
                <tr>
                    <td style="padding:20px 32px;background-color:#1d4ed8;border-radius:6px 6px 0 0;color:#ffffff;font-size:18px;font-weight:bold;">
                        {{ $siteName }}
                    </td>
                </tr>
                <tr>
                    <td style="padding:32px 32px 8px;font-size:15px;line-height:1.7;color:#111827;">
                        <p style="margin:0 0 16px;">Hi {{ $inquiry->name ?: 'there' }},</p>
                        <p style="margin:0 0 16px;">
                            Thank you for contacting us. We have received your inquiry, and our sales team
                            will get back to you with a quotation within <strong>24 hours</strong> (usually much faster).
                        </p>

                        @if ($products->isNotEmpty())
                            <p style="margin:0 0 8px;">Your inquiry covers the following product(s):</p>
                            <ul style="margin:0 0 16px;padding-left:20px;color:#374151;font-size:14px;">
                                @foreach ($products as $productName)
                                    <li style="margin-bottom:4px;">{{ $productName }}</li>
                                @endforeach
                            </ul>
                        @endif

                        @if ($landingUrl)
                            <p style="margin:0 0 24px;">
                                <a href="{{ $landingUrl }}" style="display:inline-block;padding:10px 20px;background-color:#1d4ed8;color:#ffffff;text-decoration:none;border-radius:4px;font-size:14px;">
                                    Back to the product page
                                </a>
                            </p>
                        @endif

                        <p style="margin:0 0 16px;">If you have any questions in the meantime, feel free to contact us:</p>
                        <p style="margin:0 0 4px;">
                            <strong>Email:</strong>
                            <a href="mailto:{{ $contactEmail }}" style="color:#1d4ed8;">{{ $contactEmail }}</a>
                        </p>
                        @if ($contactPhone)
                            <p style="margin:0 0 16px;"><strong>Phone:</strong> {{ $contactPhone }}</p>
                        @else
                            <p style="margin:0 0 16px;">&nbsp;</p>
                        @endif

                        <p style="margin:0;">Best regards,<br>{{ $siteName }} Team</p>
                    </td>
                </tr>
                <tr>
                    <td style="padding:16px 32px 24px;font-size:12px;line-height:1.6;color:#6b7280;">
                        This is an automated confirmation for the inquiry you submitted on {{ $siteName }}.
                        You can reply to this email directly — your reply goes straight to our sales team.
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
