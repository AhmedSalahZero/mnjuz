<?php

namespace Tests\Feature\Cycle;

use App\Models\Contact;
use Illuminate\Testing\TestResponse;

/**
 * ما تحمله صفحة المحادثة إلى الواجهة عن جهة الاتصال المفتوحة.
 *
 * الواجهة تبني منه أمرين: عدّاد نافذة الـ24 ساعة بجانب «Mark as closed»،
 * ومفتاح مساعد الذكاء الاصطناعي. وكلاهما لا يصل إلا إن وُضع في قائمة الحقول
 * المسموحة صراحةً — وحقلٌ ناقص لا يُنتج خطأً، بل يصل undefined فتُرسم الواجهة
 * بحالة افتراضية خاطئة (المفتاح مطفأ بعد كل reload).
 */
class ChatPagePayloadTest extends CycleTestCase
{
    /** @return array<string, mixed> الـ props كما يستلمها Vue. */
    private function openChat(Contact $contact): array
    {
        $this->actingAs($this->owner);
        session(['current_organization' => $this->organization->id]);

        $response = $this->get('/chats/' . $contact->uuid);
        $response->assertStatus(200);

        return $this->props($response);
    }

    private function props(TestResponse $response): array
    {
        return $response->viewData('page')['props'];
    }

    private function inboundAt(Contact $contact, \DateTimeInterface $at): void
    {
        $this->chat($contact, ['type' => 'inbound', 'status' => 'delivered', 'created_at' => $at]);
    }

    // ------------------------------------------------- نافذة الـ24 ساعة

    public function test_an_open_window_is_sent_with_the_last_inbound_time(): void
    {
        $contact = $this->contact();
        $this->inboundAt($contact, now()->subHour());

        $payload = $this->openChat($contact)['contact'];

        $this->assertTrue($payload['is_messaging_window_open']);
        $this->assertNotNull($payload['last_inbound_chat_created_at_iso']);

        // الحقل ISO بمنطقة زمنية صريحة: بلا إزاحة يفسّره المتصفّح بتوقيت الجهاز
        // فيُزاح العدّاد ساعات.
        $iso = \Carbon\Carbon::parse($payload['last_inbound_chat_created_at_iso']);
        $this->assertEqualsWithDelta(now()->subHour()->timestamp, $iso->timestamp, 5);
    }

    public function test_a_window_older_than_24_hours_is_sent_closed(): void
    {
        $contact = $this->contact();
        $this->inboundAt($contact, now()->subHours(25));

        $payload = $this->openChat($contact)['contact'];

        $this->assertFalse($payload['is_messaging_window_open']);
        // الوقت يبقى مرسَلاً: العدّاد يعرض «انتهت» من الحساب نفسه لا من العلم.
        $this->assertNotNull($payload['last_inbound_chat_created_at_iso']);
    }

    public function test_a_contact_who_never_wrote_has_no_window(): void
    {
        $contact = $this->contact();
        $this->chat($contact, ['type' => 'outbound']);

        $payload = $this->openChat($contact)['contact'];

        $this->assertFalse($payload['is_messaging_window_open']);
        $this->assertNull($payload['last_inbound_chat_created_at_iso']);
    }

    /** الرسالة الصادرة لا تفتح النافذة ولا تُمدّدها. */
    public function test_an_outbound_message_does_not_extend_the_window(): void
    {
        $contact = $this->contact();
        $this->inboundAt($contact, now()->subHours(30));
        $this->chat($contact, ['type' => 'outbound', 'created_at' => now()->subMinute()]);

        $payload = $this->openChat($contact)['contact'];

        $this->assertFalse($payload['is_messaging_window_open']);
    }

    // ------------------------------------------------- مساعد الذكاء الاصطناعي

    /** العطل نفسه: القيمة المحفوظة تصل الواجهة بعد إعادة تحميل الصفحة. */
    public function test_the_saved_ai_flag_reaches_the_page(): void
    {
        $contact = $this->contact(['ai_assistance_enabled' => 1]);

        $payload = $this->openChat($contact)['contact'];

        $this->assertArrayHasKey('ai_assistance_enabled', $payload, 'الحقل غائب — الواجهة تقرؤه undefined فيظهر المفتاح مطفأً.');
        $this->assertTrue($payload['ai_assistance_enabled']);
    }

    public function test_a_disabled_ai_flag_is_sent_as_false_not_missing(): void
    {
        $contact = $this->contact();

        $payload = $this->openChat($contact)['contact'];

        $this->assertArrayHasKey('ai_assistance_enabled', $payload);
        $this->assertFalse($payload['ai_assistance_enabled']);
    }

    /** المنطقي bool لا 1/0: الواجهة تقارنه وتربطه بصنف CSS مباشرة. */
    public function test_the_ai_flag_is_a_real_boolean(): void
    {
        $contact = $this->contact(['ai_assistance_enabled' => 1]);

        $this->assertSame(true, $this->openChat($contact)['contact']['ai_assistance_enabled']);
    }
}
