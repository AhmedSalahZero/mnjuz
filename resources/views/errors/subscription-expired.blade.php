{{--
    اشتراك المنشأة انتهى، والموظّف لا يملك تجديده.

    كان يرى «ليس لديك صلاحية الوصول إلى هذا القسم» — فيظنّ أن حسابه تعطّل أو
    أن أحداً سحب صلاحياته، والسبب شيء آخر لا حيلة له فيه. فنقول له ما وقع
    ومن يستطيع إصلاحه.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ in_array(app()->getLocale(), ['ar', 'fa', 'he', 'ur']) ? 'rtl' : 'ltr' }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Subscription expired') }}</title>
    <meta name="robots" content="noindex, follow">
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
            background: #f8fafc; color: #0f172a; padding: 24px;
            font-family: system-ui, -apple-system, "Segoe UI", Tahoma, Arial, sans-serif;
        }
        .card {
            background: #fff; border: 1px solid #e2e8f0; border-radius: 12px;
            box-shadow: 0 1px 3px rgba(15, 23, 42, .08);
            max-width: 560px; width: 100%; padding: 32px;
        }
        h1 { font-size: 20px; margin: 0 0 12px; }
        p { margin: 0 0 12px; line-height: 1.7; color: #475569; font-size: 15px; }
        ul { list-style: none; margin: 8px 0 0; padding: 0; }
        li {
            border: 1px solid #e2e8f0; border-radius: 8px; padding: 10px 14px;
            margin-bottom: 8px; background: #f8fafc;
        }
        .name { font-weight: 600; color: #0f172a; }
        .role { font-size: 12px; color: #64748b; }
        .email { display: block; font-size: 14px; color: #334155; margin-top: 2px; word-break: break-all; }
        .who { margin-top: 20px; font-weight: 600; font-size: 15px; color: #0f172a; }
        .foot { margin-top: 24px; font-size: 14px; }
        a { color: #2563eb; }
    </style>
</head>

<body>
    <div class="card">
        <h1>{{ __('The organization subscription has expired') }}</h1>

        <p>{{ __('Your account is fine. The subscription for this organization ended, so the workspace is paused until it is renewed.') }}</p>
        <p>{{ __('Renewing is done from the billing page, and your role does not have access to it.') }}</p>

        @if (!empty($renewers))
            <div class="who">{{ __('These team members can renew it:') }}</div>
            <ul>
                @foreach ($renewers as $person)
                    <li>
                        <span class="name">{{ $person['name'] !== '' ? $person['name'] : $person['email'] }}</span>
                        <span class="role">({{ __(ucfirst($person['role'])) }})</span>
                        <span class="email">{{ $person['email'] }}</span>
                    </li>
                @endforeach
            </ul>
        @else
            <p>{{ __('Please contact the account owner to renew the subscription.') }}</p>
        @endif

        <p class="foot"><a href="{{ url('/logout') }}">{{ __('Logout') }}</a></p>
    </div>
</body>

</html>
