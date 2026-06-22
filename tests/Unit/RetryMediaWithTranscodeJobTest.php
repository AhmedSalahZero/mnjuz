<?php

namespace Tests\Unit;

use App\Jobs\RetryMediaWithTranscodeJob;
use App\Models\Chat;
use PHPUnit\Framework\TestCase;

class RetryMediaWithTranscodeJobTest extends TestCase
{
    public function test_should_retry_for_outbound_video_with_retryable_error(): void
    {
        $chat = new Chat([
            'type' => 'outbound',
            'media_id' => 10,
            'metadata' => json_encode(['type' => 'video']),
        ]);

        $this->assertTrue(RetryMediaWithTranscodeJob::shouldRetryForChat($chat, [
            ['code' => 131053, 'title' => 'Media upload error'],
        ]));
    }

    public function test_should_not_retry_for_non_video(): void
    {
        $chat = new Chat([
            'type' => 'outbound',
            'media_id' => 10,
            'metadata' => json_encode(['type' => 'image']),
        ]);

        $this->assertFalse(RetryMediaWithTranscodeJob::shouldRetryForChat($chat, [
            ['code' => 131053],
        ]));
    }

    public function test_should_not_retry_after_previous_attempt(): void
    {
        $chat = new Chat([
            'type' => 'outbound',
            'media_id' => 10,
            'metadata' => json_encode([
                'type' => 'video',
                'transcode_retry_count' => 1,
            ]),
        ]);

        $this->assertFalse(RetryMediaWithTranscodeJob::shouldRetryForChat($chat, [
            ['code' => 131053],
        ]));
    }

    public function test_should_not_retry_for_unrelated_error_code(): void
    {
        $chat = new Chat([
            'type' => 'outbound',
            'media_id' => 10,
            'metadata' => json_encode(['type' => 'video']),
        ]);

        $this->assertFalse(RetryMediaWithTranscodeJob::shouldRetryForChat($chat, [
            ['code' => 100],
        ]));
    }
}
