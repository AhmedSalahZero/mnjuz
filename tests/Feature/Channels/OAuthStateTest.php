<?php

namespace Tests\Feature\Channels;

use App\Services\Messaging\Channel;
use App\Services\Messaging\OAuth\OAuthState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

/**
 * حالة OAuth — نقطة الأمان في تدفّق الربط.
 *
 * بدونها يستطيع مهاجم أن يُوقع موظّفاً على رابط callback يحمل `code` يخصّ
 * حساب المهاجم، فيُربط حساب المهاجم بمنشأة الضحية ويقرأ كل رسائلها. هذا
 * هجوم CSRF معروف على تدفّقات OAuth، وكل اختبار هنا يُغلق باباً منه.
 */
class OAuthStateTest extends TestCase
{
    use RefreshDatabase;

    /** الحالة الصحيحة تُفكّ وتُرجع ما وُضع فيها. */
    public function test_a_valid_state_round_trips(): void
    {
        $state = OAuthState::issue(Channel::MESSENGER, 42, 7);

        $payload = OAuthState::consume($state);

        $this->assertNotNull($payload);
        $this->assertSame(Channel::MESSENGER, $payload['channel']);
        $this->assertSame(42, $payload['organization_id']);
        $this->assertSame(7, $payload['user_id']);
    }

    /**
     * ولا تُستعمل مرّتين.
     *
     * إعادة تشغيل نفس الـ callback — من زرّ الرجوع أو من سجلّ مسروق — لا
     * تُعيد الربط.
     */
    public function test_a_state_cannot_be_used_twice(): void
    {
        $state = OAuthState::issue(Channel::TIKTOK, 42, 7);

        $this->assertNotNull(OAuthState::consume($state));
        $this->assertNull(OAuthState::consume($state));
    }

    /** والمنتهية تُرفض ولو كان توقيعها سليماً. */
    public function test_an_expired_state_is_rejected(): void
    {
        $state = OAuthState::issue(Channel::MESSENGER, 42, 7);

        $this->travel(OAuthState::TTL_MINUTES + 1)->minutes();

        $this->assertNull(OAuthState::consume($state));
    }

    /**
     * والتي حُذف nonce من الكاش تُرفض — وهي الحالة التي تقع حين يُعاد
     * تشغيل الخادم أو يُمسح الكاش في الأثناء.
     */
    public function test_a_state_whose_nonce_vanished_is_rejected(): void
    {
        $state = OAuthState::issue(Channel::MESSENGER, 42, 7);

        Cache::flush();

        $this->assertNull(OAuthState::consume($state));
    }

    /** والمُركَّبة بلا مفتاح التطبيق تُرفض. */
    public function test_a_forged_state_is_rejected(): void
    {
        $forged = base64_encode(json_encode([
            'channel' => Channel::MESSENGER,
            'organization_id' => 999,
            'user_id' => 1,
            'nonce' => 'whatever',
            'expires_at' => now()->addHour()->timestamp,
        ]));

        $this->assertNull(OAuthState::consume($forged));
    }

    /**
     * والمشفّرة بمفتاحنا لكن بحمولة ناقصة تُرفض كذلك — لا نثق بأن التشفير
     * وحده يعني حمولة صحيحة.
     */
    public function test_a_well_encrypted_but_incomplete_payload_is_rejected(): void
    {
        $incomplete = Crypt::encryptString(json_encode([
            'channel' => Channel::MESSENGER,
            'organization_id' => 42,
            // user_id و nonce و expires_at ناقصة
        ]));

        $this->assertNull(OAuthState::consume($incomplete));
    }

    /** والنصّ العشوائي أو الفارغ لا يُسقط شيئاً. */
    public function test_garbage_input_is_rejected_without_throwing(): void
    {
        $this->assertNull(OAuthState::consume(null));
        $this->assertNull(OAuthState::consume(''));
        $this->assertNull(OAuthState::consume('not-a-state'));
        $this->assertNull(OAuthState::consume(str_repeat('A', 5000)));
    }

    /** والحمولة المشفّرة التي ليست JSON تُرفض. */
    public function test_encrypted_non_json_is_rejected(): void
    {
        $this->assertNull(OAuthState::consume(Crypt::encryptString('مجرّد نصّ')));
    }

    /**
     * وحالتان متتاليتان مختلفتان — الـ nonce عشوائي فلا تُنتج القيمة نفسها
     * مرّتين، ولا يُلغي استعمالُ إحداهما الأخرى.
     */
    public function test_two_states_are_independent(): void
    {
        $first = OAuthState::issue(Channel::MESSENGER, 42, 7);
        $second = OAuthState::issue(Channel::MESSENGER, 42, 7);

        $this->assertNotSame($first, $second);

        $this->assertNotNull(OAuthState::consume($first));
        $this->assertNotNull(OAuthState::consume($second));
    }

    /** والحالة لا تكشف محتواها لمن يقرأها بالعين. */
    public function test_the_state_does_not_leak_its_contents(): void
    {
        $state = OAuthState::issue(Channel::MESSENGER, 424242, 777);

        $this->assertStringNotContainsString('424242', $state);
        $this->assertStringNotContainsString('777', $state);
        $this->assertStringNotContainsString(Channel::MESSENGER, $state);
    }
}
