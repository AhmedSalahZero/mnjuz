{{-- رسالة حملة بريد: متن التاجر نصّاً مهرَّباً (لا HTML منه)، وتذييل الإلغاء. --}}
<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $subject }}</title>
</head>
<body style="margin:0;padding:0;background:#f8fafc;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc;padding:24px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border:1px solid #e2e8f0;border-radius:8px;">
                    <tr>
                        <td dir="auto" style="padding:24px;font-family:Tahoma,Arial,sans-serif;font-size:15px;line-height:1.7;color:#1e293b;">
                            {!! $bodyHtml !!}
                        </td>
                    </tr>
                    <tr>
                        <td dir="auto" style="padding:16px 24px;border-top:1px solid #e2e8f0;font-family:Tahoma,Arial,sans-serif;font-size:12px;line-height:1.6;color:#64748b;">
                            {{ __('You received this email from :name.', ['name' => $fromName]) }}
                            <a href="{{ $unsubscribeUrl }}" style="color:#64748b;">{{ __('Unsubscribe') }}</a>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
