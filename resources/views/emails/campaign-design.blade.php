{{-- رسالة حملة بريد من تصميم الـ builder. كل النصوص مهرَّبة؛ والتذييل (إلغاء الاشتراك) خارج العناصر دائماً. --}}
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $direction }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $subject }}</title>
    <style>
        @media only screen and (max-width: 620px) {
            .mnjz-stack { display: block !important; width: 100% !important; }
            .mnjz-stack img { width: 100% !important; height: auto !important; }
        }
    </style>
</head>
<body style="margin:0;padding:0;background:{{ $pageBg }};">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:{{ $pageBg }};padding:24px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" dir="{{ $direction }}" style="max-width:600px;background:{{ $contentBg }};border-radius:8px;font-family:{{ $font }};color:{{ $textColor }};">
                    @foreach ($blocks as $block)
                        @include('emails.blocks.' . $block['type'], ['block' => $block])
                    @endforeach
                    <tr>
                        <td dir="{{ $direction }}" style="padding:16px 24px;border-top:1px solid #e5e7eb;font-family:{{ $font }};font-size:12px;line-height:1.6;color:#6b7280;text-align:center;">
                            {{ __('You received this email from :name.', ['name' => $fromName]) }}
                            <a href="{{ $unsubscribeUrl }}" style="color:#6b7280;">{{ __('Unsubscribe') }}</a>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
