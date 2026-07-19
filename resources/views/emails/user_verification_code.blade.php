<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ __('Verification Code') }}</title>
</head>
<body style="margin:0;padding:0;background:#f8fafc;font-family:Arial,sans-serif;">
    <table width="100%" cellpadding="0" cellspacing="0" style="padding:24px 0;">
        <tr>
            <td align="center">
                <table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:12px;overflow:hidden;">
                    <tr>
                        <td style="background:#0f766e;padding:20px;text-align:center;">
                            @if(!empty($logoUrl))
                                <img src="{{ $logoUrl }}" alt="{{ $companyName }}" style="max-height:48px;max-width:180px;">
                            @else
                                <h2 style="margin:0;color:#ffffff;">{{ $companyName }}</h2>
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:28px;">
                            <h3 style="margin:0 0 12px 0;color:#0f172a;">{{ __('Verification Required') }}</h3>
                            <p style="margin:0 0 20px 0;color:#475569;line-height:1.5;">
                                {{ __('Use the following code to verify your account and continue to your dashboard.') }}
                            </p>

                            <div style="text-align:center;margin:24px 0;">
                                <span style="display:inline-block;background:#f1f5f9;border:1px solid #cbd5e1;border-radius:10px;padding:12px 20px;font-size:30px;letter-spacing:8px;color:#0f172a;font-weight:700;">
                                    {{ $code }}
                                </span>
                            </div>

                            <p style="margin:0;color:#64748b;line-height:1.5;">
                                {{ __('This code expires in :minutes minutes.', ['minutes' => $expiresInMinutes]) }}
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
