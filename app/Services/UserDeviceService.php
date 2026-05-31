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
        }

        $deviceType = 'desktop';
        if (str_contains($ua, 'ipad') || str_contains($ua, 'tablet')) {
            $deviceType = 'tablet';
        } elseif (
            str_contains($ua, 'iphone') ||
            str_contains($ua, 'android') ||
            str_contains($ua, 'mobile')
        ) {
            $deviceType = 'mobile';
        }

        $deviceName = match ($deviceType) {
            'mobile' => "{$platform} Phone",
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

        $device = $user->deviceForCategory($category);

        if (!$device) {
            return UserDevice::create([
                'user_id' => $user->id,
                ...$deviceData,
            ]);
        }

        $device->fill($deviceData);
        $device->save();

        return $device;
    }

    private function normalized(?string $value): string
    {
        return strtolower(trim((string) $value));
    }
}
