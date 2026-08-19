<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Http\Request;

class UserDeviceService
{
    /**
     * Basic UA parsing without extra dependency.
     */
    public function extractDeviceData(Request $request): array
    {
        $userAgent = (string) $request->userAgent();
        $ua = strtolower($userAgent);

        $platform = 'Unknown';
        if (str_contains($ua, 'windows')) {
            $platform = 'Windows';
        } elseif (str_contains($ua, 'mac os x') || str_contains($ua, 'macintosh')) {
            $platform = 'macOS';
        } elseif (str_contains($ua, 'android')) {
            $platform = 'Android';
        } elseif (str_contains($ua, 'iphone') || str_contains($ua, 'ipad') || str_contains($ua, 'ios')) {
            $platform = 'iOS';
        } elseif (str_contains($ua, 'linux')) {
            $platform = 'Linux';
        }

        $browser = 'Unknown';
        if (str_contains($ua, 'edg/')) {
            $browser = 'Edge';
        } elseif (str_contains($ua, 'opr/') || str_contains($ua, 'opera')) {
            $browser = 'Opera';
        } elseif (str_contains($ua, 'firefox/')) {
            $browser = 'Firefox';
        } elseif (str_contains($ua, 'chrome/') && !str_contains($ua, 'edg/')) {
            $browser = 'Chrome';
        } elseif (str_contains($ua, 'safari/') && !str_contains($ua, 'chrome/')) {
            $browser = 'Safari';
        } elseif ($this->isMobileAppRequest($request)) {
            $browser = 'Mobile App';
        }

        $deviceType = 'desktop';
        if (str_contains($ua, 'ipad') || str_contains($ua, 'tablet')) {
            $deviceType = 'tablet';
        } elseif (
            str_contains($ua, 'iphone') ||
            str_contains($ua, 'android') ||
            str_contains($ua, 'mobile') ||
            $this->isMobileAppRequest($request)
        ) {
            $deviceType = 'mobile';
        }

        $deviceName = match ($deviceType) {
            'mobile' => $request->input('device_name') ?: "{$platform} Phone",
            'tablet' => "{$platform} Tablet",
            default => "{$platform} PC",
        };

        return [
            'device_name' => $deviceName,
            'device_type' => $deviceType,
            'browser' => $browser,
            'platform' => $platform,
            'user_agent' => $userAgent,
            'ip_address' => $request->ip(),
            'last_used_at' => now(),
        ];
    }

    /**
     * Web and mobile each get one linked device slot (see migration user_devices_user_category_unique).
     */
    public function resolveCategory(Request $request, array $deviceData): string
    {
        $explicit = strtolower(trim((string) $request->input('device_category', '')));
        if (in_array($explicit, ['web', 'mobile'], true)) {
            return $explicit;
        }

        if ($this->isMobileAppRequest($request)) {
            return 'mobile';
        }

        return $this->getDeviceCategory($deviceData);
    }

    /**
     * Native mobile apps often send Dart/okhttp UA without "mobile" — treat API login as mobile.
     */
    public function isMobileAppRequest(Request $request): bool
    {
        if (!$request->is('api/*') && !$request->expectsJson()) {
            return false;
        }

        if ($request->filled('device_token')) {
            return true;
        }

        $deviceType = strtolower(trim((string) $request->input('device_type', '')));
        if (in_array($deviceType, ['ios', 'android', 'mobile'], true)) {
            return true;
        }

        $ua = strtolower((string) $request->userAgent());
        if ($ua === '') {
            return false;
        }

        $mobileAppMarkers = ['dart/', 'okhttp', 'cfnetwork', 'mnjzchat', 'flutter', 'reactnative'];
        foreach ($mobileAppMarkers as $marker) {
            if (str_contains($ua, $marker)) {
                return true;
            }
        }

        return false;
    }

    public function getDeviceCategory(array $deviceData): string
    {
        return ($deviceData['device_type'] ?? '') === 'mobile' ? 'mobile' : 'web';
    }

    public function matches(UserDevice $stored, array $current): bool
    {
        return $this->normalized($stored->browser) === $this->normalized($current['browser'] ?? null)
            && $this->normalized($stored->platform) === $this->normalized($current['platform'] ?? null)
            && $this->normalized($stored->device_type) === $this->normalized($current['device_type'] ?? null);
    }

    public function registerOrTouch(User $user, array $deviceData, string $category = null): UserDevice
    {
        $category = $category ?? $this->getDeviceCategory($deviceData);
        $deviceData['device_category'] = $category;
        $deviceData['device_identifier'] = (string) \Illuminate\Support\Str::uuid();

        $device = $user->deviceForCategory($category);

        if (!$device) {
            $device = UserDevice::create([
                'user_id' => $user->id,
                ...$deviceData,
            ]);
        } else {
            $device->fill($deviceData);
            $device->save();
        }

        $this->evictOtherCategory($user, $category);

        return $device;
    }

    /**
     * جهاز واحد لكل حساب — عبر الويب والجوال معاً.
     *
     * كان لكل فئة خانة مستقلّة، فيبقى المتصفّح والتطبيق مسجَّلَين في آنٍ واحد.
     * الآن أيّ دخول جديد يطرد الآخر، وطريقة الطرد تختلف باختلاف ما يحمل الجلسة:
     *
     * • التطبيق يُطرد بإبطال رموز Sanctum مع تسجيل السبب — كلّها للجوال، فإبطالها
     *   يقطع وصوله فوراً، والسبب المحفوظ يجعل الـ401 التالي مفهوماً لا غامضاً.
     * • المتصفّح يُطرد بتدوير معرّف جهاز الويب: الجلسة تحمل المعرّف القديم،
     *   وEnsureDeviceIsCurrent يقارنهما في كل طلب فيُخرجها عند أول طلب تالٍ.
     *
     * لا نحذف صفّ جهاز الويب لأن الميدلوير لا يُخرج أحداً حين لا يجد صفّاً —
     * الحذف كان سيُبقي الجلسة حيّة بدل أن ينهيها.
     */
    private function evictOtherCategory(User $user, string $keepCategory): void
    {
        if ($keepCategory === 'mobile') {
            $user->devices()
                ->where('device_category', 'web')
                ->update([
                    'device_identifier' => (string) \Illuminate\Support\Str::uuid(),
                    'updated_at' => now(),
                ]);

            return;
        }

        \App\Support\TokenRevocation::revokeAll($user);
    }

    private function normalized(?string $value): string
    {
        return strtolower(trim((string) $value));
    }
}
