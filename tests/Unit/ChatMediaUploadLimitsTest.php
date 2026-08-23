<?php

namespace Tests\Unit;

use App\Helpers\ChatMediaUploadHelper;
use Tests\TestCase;

class ChatMediaUploadLimitsTest extends TestCase
{
    public function test_php_max_upload_bytes_is_positive(): void
    {
        $this->assertGreaterThan(0, ChatMediaUploadHelper::phpMaxUploadBytes());
    }

    public function test_video_and_audio_limits_match_dashboard_sixteen_mb(): void
    {
        $phpLimit = ChatMediaUploadHelper::phpMaxUploadBytes();
        $expected = min(16 * 1024 * 1024, $phpLimit);

        $this->assertSame($expected, ChatMediaUploadHelper::maxUploadBytesForType('video'));
        $this->assertSame($expected, ChatMediaUploadHelper::maxUploadBytesForType('audio'));
    }

    public function test_document_limit_matches_dashboard_one_hundred_mb(): void
    {
        $phpLimit = ChatMediaUploadHelper::phpMaxUploadBytes();
        $expected = min(100 * 1024 * 1024, $phpLimit);

        $this->assertSame($expected, ChatMediaUploadHelper::maxUploadBytesForType('document'));
    }

    public function test_image_uses_php_limit_only_like_dashboard(): void
    {
        $this->assertSame(
            ChatMediaUploadHelper::phpMaxUploadBytes(),
            ChatMediaUploadHelper::maxUploadBytesForType('image')
        );
        $this->assertSame(
            ChatMediaUploadHelper::phpMaxUploadBytes(),
            ChatMediaUploadHelper::maxUploadBytesForType('gif')
        );
    }

    public function test_document_allows_larger_uploads_than_video_on_mobile(): void
    {
        if (ChatMediaUploadHelper::phpMaxUploadBytes() <= 16 * 1024 * 1024) {
            $this->markTestSkipped('PHP upload limit is too low to compare document vs video.');
        }

        $this->assertGreaterThan(
            ChatMediaUploadHelper::maxUploadBytesForType('video'),
            ChatMediaUploadHelper::maxUploadBytesForType('document')
        );
    }
}
