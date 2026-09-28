<?php

namespace Tests\Feature\Channels;

use Tests\TestCase;

/**
 * تحقّق لوحة ميتا من الويبهوك — قبل وجود أيّ حساب مربوط.
 *
 * هذه هي الحالة التي كانت مكسورة: ميتا تتحقّق مرّة واحدة عند إعداد التطبيق،
 * وذلك يجري قبل أن يربط أيّ عميل صفحته. والتوكن كان يُقرأ من الحساب وحده،
 * فلا يوجد ما يُقارَن به، فيردّ 403 وتقول ميتا:
 *
 *   "The callback URL or verify token couldn't be validated."
 */
class MetaWebhookHandshakeTest extends TestCase
{
    private const TOKEN = 'test-verify-token-123';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.meta.webhook_verify_token' => self::TOKEN]);
    }

    /** @dataProvider metaChannels */
    public function test_meta_verifies_the_webhook_with_no_account_connected(string $channel): void
    {
        $response = $this->get("/webhook/channel/{$channel}?" . http_build_query([
            'hub.mode' => 'subscribe',
            'hub.verify_token' => self::TOKEN,
            'hub.challenge' => 'challenge-987',
        ]));

        $response->assertOk();
        $this->assertSame('challenge-987', $response->getContent());
    }

    /** @dataProvider metaChannels */
    public function test_a_wrong_token_is_refused(string $channel): void
    {
        $this->get("/webhook/channel/{$channel}?" . http_build_query([
            'hub.mode' => 'subscribe',
            'hub.verify_token' => 'not-the-token',
            'hub.challenge' => 'challenge-987',
        ]))->assertStatus(403);
    }

    /** @dataProvider metaChannels */
    public function test_an_empty_configured_token_never_matches(string $channel): void
    {
        // الحارس المهمّ: توكن فارغ في .env لا يجوز أن يُطابق توكناً فارغاً
        // من الطلب — وإلّا فتح خادمٌ غير مضبوط بابه لأي أحد.
        config(['services.meta.webhook_verify_token' => null]);

        $this->get("/webhook/channel/{$channel}?" . http_build_query([
            'hub.mode' => 'subscribe',
            'hub.verify_token' => '',
            'hub.challenge' => 'challenge-987',
        ]))->assertStatus(403);
    }

    public function test_a_plain_browser_visit_is_forbidden(): void
    {
        // زيارة المتصفّح بلا معاملات: 403 مقصودة لا عطب.
        $this->get('/webhook/channel/messenger')->assertStatus(403);
    }

    public function test_whatsapp_is_not_served_by_this_route(): void
    {
        $this->get('/webhook/channel/whatsapp')->assertStatus(404);
    }

    public static function metaChannels(): array
    {
        return ['messenger' => ['messenger'], 'instagram' => ['instagram']];
    }
}
