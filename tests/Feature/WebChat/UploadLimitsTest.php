<?php

namespace Tests\Feature\WebChat;

use App\Jobs\ProcessAutoReplyJob;
use App\Jobs\ProcessWebChatInboundJob;
use App\Services\WebChat\WebChatUploads;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * حدود ملفات الزائر: ما يُعلَن للـ widget هو ما يقبله الخادم فعلاً، وحتى
 * ثلاثة ملفات في المرّة، وحدٌّ للـ IP فوق حدّ الزائر.
 */
class UploadLimitsTest extends WebChatTestCase
{
    private const MB = 1024 * 1024;

    public function test_the_effective_limit_is_the_smallest_of_type_and_server(): void
    {
        $types = ['image' => 10 * self::MB, 'video' => 25 * self::MB, 'audio' => 16 * self::MB];

        $this->assertSame($types, WebChatUploads::effectiveLimits($types, null));
        $this->assertSame(
            ['image' => 2 * self::MB, 'video' => 2 * self::MB, 'audio' => 2 * self::MB],
            WebChatUploads::effectiveLimits($types, 2 * self::MB)
        );
        $this->assertSame(
            ['image' => 10 * self::MB, 'video' => 20 * self::MB, 'audio' => 16 * self::MB],
            WebChatUploads::effectiveLimits($types, 20 * self::MB)
        );
    }

    public function test_php_ini_sizes_are_read_like_php_does(): void
    {
        $this->assertSame(2 * self::MB, WebChatUploads::iniBytes('2M'));
        $this->assertSame(512 * 1024, WebChatUploads::iniBytes('512K'));
        $this->assertSame(1024 * self::MB, WebChatUploads::iniBytes('1G'));
        $this->assertSame(8388608, WebChatUploads::iniBytes('8388608'));
        $this->assertSame(25 * self::MB, WebChatUploads::iniBytes(' 25m '));

        foreach (['-1', '0', '', 'unlimited'] as $none) {
            $this->assertNull(WebChatUploads::iniBytes($none), "«{$none}» = بلا حدّ");
        }
    }

    public function test_the_configured_cap_lowers_every_limit(): void
    {
        config(['messaging.webchat_upload_max_mb' => 1]);

        $this->assertLessThanOrEqual(self::MB, WebChatUploads::serverMaxBytes());

        foreach (WebChatUploads::limits() as $type => $limit) {
            $this->assertLessThanOrEqual(self::MB, $limit, $type);
        }
    }

    public function test_config_announces_the_real_limits_and_three_files_per_send(): void
    {
        config(['messaging.webchat_upload_max_mb' => 1]);

        $limits = $this->getJson($this->url('config'))->assertOk()->json('limits');

        $this->assertSame(3, $limits['files_per_send']);
        $this->assertSame(WebChatUploads::limits(), $limits['media']);
        $this->assertLessThanOrEqual(self::MB, $limits['media']['image']);
    }

    public function test_a_file_above_the_real_limit_is_refused(): void
    {
        Storage::fake('local');
        config(['messaging.webchat_upload_max_mb' => 1]);
        [$token] = $this->openSession();

        $this->post($this->url('uploads'), [
            'client_message_id' => (string) Str::uuid(),
            'file' => UploadedFile::fake()->image('big.png', 20, 20)->size(1500),
        ], ['X-Webchat-Token' => $token, 'Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');
    }

    public function test_uploads_are_capped_per_ip_even_across_new_sessions(): void
    {
        Storage::fake('local');
        Bus::fake([ProcessAutoReplyJob::class, ProcessWebChatInboundJob::class]);

        $statuses = [];

        // ٤ زوّار من نفس الـ IP، كلٌّ تحت حدّه (٥ في الدقيقة)، ومجموعهم ١٦.
        for ($v = 0; $v < 4; $v++) {
            [$token] = $this->openSession(['name' => 'زائر ' . $v]);

            for ($i = 0; $i < 4; $i++) {
                $statuses[] = $this->post($this->url('uploads'), [
                    'client_message_id' => (string) Str::uuid(),
                    'file' => UploadedFile::fake()->image("p{$v}{$i}.png", 10, 10),
                ], ['X-Webchat-Token' => $token, 'Accept' => 'application/json'])->status();
            }
        }

        $this->assertSame(array_fill(0, 15, 202), array_slice($statuses, 0, 15));
        $this->assertSame(429, $statuses[15], 'الملف السادس عشر من نفس الـ IP في الدقيقة يُرفض.');
    }
}
