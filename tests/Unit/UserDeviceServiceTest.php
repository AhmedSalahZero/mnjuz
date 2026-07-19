<?php

namespace Tests\Unit;

use App\Services\UserDeviceService;
use Illuminate\Http\Request;
use Tests\TestCase;

class UserDeviceServiceTest extends TestCase
{
    private UserDeviceService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new UserDeviceService();
    }

    public function test_api_login_with_device_token_is_mobile_category(): void
    {
        $request = Request::create('/api/v1/login', 'POST', [
            'device_token' => 'fcm-token-123',
            'device_name' => 'iPhone 15',
            'device_type' => 'ios',
        ]);
        $request->headers->set('User-Agent', 'Dart/3.3 (dart:io)');

        $deviceData = $this->service->extractDeviceData($request);

        $this->assertSame('mobile', $this->service->resolveCategory($request, $deviceData));
        $this->assertSame('mobile', $deviceData['device_type']);
    }

    public function test_chrome_browser_is_web_category(): void
    {
        $request = Request::create('/login', 'POST');
        $request->headers->set('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36');

        $deviceData = $this->service->extractDeviceData($request);

        $this->assertSame('web', $this->service->resolveCategory($request, $deviceData));
    }

    public function test_web_and_mobile_categories_are_independent_slots(): void
    {
        $webRequest = Request::create('/login', 'POST');
        $webRequest->headers->set('User-Agent', 'Mozilla/5.0 Chrome/120.0.0.0');

        $mobileRequest = Request::create('/api/v1/login', 'POST', [
            'device_token' => 'abc',
            'device_type' => 'android',
        ]);
        $mobileRequest->headers->set('User-Agent', 'okhttp/4.9.0');

        $webCategory = $this->service->resolveCategory($webRequest, $this->service->extractDeviceData($webRequest));
        $mobileCategory = $this->service->resolveCategory($mobileRequest, $this->service->extractDeviceData($mobileRequest));

        $this->assertSame('web', $webCategory);
        $this->assertSame('mobile', $mobileCategory);
        $this->assertNotSame($webCategory, $mobileCategory);
    }
}
