<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ __('Reset linked devices email subject', ['app' => $companyName]) }}</title>
</head>
<body style="margin:0;padding:0;background-color:#f6f7f9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color:#f6f7f9;padding:24px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:560px;background-color:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.08);">
                    <tr>
                        <td style="padding:32px 28px 8px;text-align:center;">
                            @if(!empty($logoUrl))
                                <img src="{{ $logoUrl }}" alt="{{ $companyName }}" width="160" style="max-width:180px;height:auto;display:inline-block;border:0;">
                            @else
                                <h1 style="margin:0;font-size:22px;color:#0f172a;">{{ $companyName }}</h1>
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:8px 28px 24px;">
                            <p style="margin:0 0 16px;font-size:16px;line-height:1.6;color:#334155;">
                                {{ __('You requested to reset linked devices for your account.') }}
                            </p>
                            <p style="margin:0 0 24px;font-size:14px;line-height:1.5;color:#64748b;">
                                {{ __('This link expires in 30 minutes. If you did not request this, you can ignore this email.') }}
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:0 28px 32px;text-align:center;">
                            <a href="{{ $resetUrl }}" style="display:inline-block;background-color:#2563eb;color:#ffffff;text-decoration:none;font-size:16px;font-weight:600;padding:14px 28px;border-radius:8px;">
                                {{ __('Reset Devices') }}
                            </a>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:0 28px 28px;">
                            <p style="margin:0;font-size:12px;line-height:1.5;color:#94a3b8;text-align:center;">
                                {{ $companyName }}
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
