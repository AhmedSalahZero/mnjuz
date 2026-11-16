<?php

namespace Tests\Feature\ChatImport;

use App\Helpers\MessagingWindowHelper;
use App\Models\Contact;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

/**
 * نافذة الأربع والعشرين ساعة لجهة اتصال جاءت بالاستيراد.
 *
 * سجلّ الإنتاج (2026-11-10):
 *   Undefined array key "organization_id" at app/Models/Chat.php:56
 *   ← MessagingWindowHelper::resolveLastInboundAtUtc ← ContactResource
 *
 * الاستيراد يترك last_inbound_chat_created_at فارغاً عمداً، فيلجأ المساعد
 * إلى استعلام احتياطي. كان ذلك الاستعلام يقرأ الرسائل المستوردة، ويمرّ
 * created_at بمحوّل Chat الذي يطلب organization_id غير المجلوب فيسقط. ولأن
 * المساعد يُنادى قبل كل إرسال، كانت جهة الاتصال المستوردة تُسقط صفحة جهات
 * الاتصال وكل إرسال إليها.
 */
class ChatImportMessagingWindowTest extends ChatImportTestCase
{
    public function test_an_imported_contact_has_a_closed_window_instead_of_a_crash(): void
    {
        $this->runImport($this->makeImport($this->csv([
            ['966501110003', 'Ali', now()->subHour()->toIso8601String(), 'in', 'رسالة قبل ساعة'],
        ])));

        $contact = $this->contactByPhone('+966501110003');
        $this->assertNull($contact->last_inbound_chat_created_at, 'الاستيراد لا يملأ العمود');
        $this->assertSame(1, DB::table('chats')->where('contact_id', $contact->id)->whereNotNull('chat_import_id')->count());

        $payload = MessagingWindowHelper::payloadForContact($contact);

        $this->assertFalse($payload['is_messaging_window_open'], 'رسالة مستوردة لا تفتح النافذة');
        $this->assertNull($payload['last_inbound_chat_created_at']);
        $this->assertNull($payload['last_inbound_chat']);
        $this->assertFalse(MessagingWindowHelper::isMessagingWindowOpen($contact));
    }

    /** الصفحة التي سقطت في الإنتاج نفسها. */
    public function test_the_contacts_page_lists_an_imported_contact(): void
    {
        $this->runImport($this->makeImport($this->csv([
            ['966501110003', 'Ali', now()->subHour()->toIso8601String(), 'in', 'رسالة قبل ساعة'],
        ])));

        session(['current_organization' => $this->organization->id]);

        $this->actingAs($this->user)
            ->get('/contacts')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('User/Contact/Index')
                ->where('rows.data.0.phone', '+966501110003')
                ->where('rows.data.0.is_messaging_window_open', false)
                ->where('rows.data.0.last_inbound_chat_created_at', null)
            );
    }

    /**
     * عمودٌ فارغ لرسالة حيّة (بيانات أقدم من العمود): الاحتياطي يجدها، ويُرجع
     * القيمة بتوقيت UTC كما في القاعدة. المنشأة على توقيت الرياض، فلو مرّت
     * القيمة بمحوّل Chat لزادت ثلاث ساعات وطالت النافذة بقدرها.
     */
    public function test_a_live_inbound_message_still_opens_the_window_in_utc(): void
    {
        $contact = $this->makeContact('+966501110004');
        $at = now('UTC')->subHours(2)->format('Y-m-d H:i:s');
        $this->insertChat($contact, 'inbound', $at);

        $this->assertNull($contact->fresh()->last_inbound_chat_created_at);

        $this->assertSame($at, MessagingWindowHelper::resolveLastInboundAtUtc($contact->fresh())?->toDateTimeString());
        $this->assertTrue(MessagingWindowHelper::isMessagingWindowOpen($contact->fresh()));
    }

    /** رسالة مستوردة أحدث من الحيّة لا تحلّ محلّها. */
    public function test_an_imported_message_does_not_override_an_older_live_one(): void
    {
        $contact = $this->makeContact('+966501110005');
        $live = now('UTC')->subHours(30)->format('Y-m-d H:i:s');
        $this->insertChat($contact, 'inbound', $live);
        $this->insertChat($contact, 'inbound', now('UTC')->subHour()->format('Y-m-d H:i:s'), $this->makeImport('x')->id);

        $this->assertSame($live, MessagingWindowHelper::resolveLastInboundAtUtc($contact)?->toDateTimeString());
        $this->assertFalse(MessagingWindowHelper::isMessagingWindowOpen($contact), 'آخر رسالة حيّة قبل ٣٠ ساعة');
    }

    /** toBase() يُبقي قيد العلاقة: رسالة جهة أخرى لا تفتح نافذة هذه. */
    public function test_another_contacts_message_does_not_leak_into_the_window(): void
    {
        $contact = $this->makeContact('+966501110007');
        $other = $this->makeContact('+966501110008');
        $this->insertChat($other, 'inbound', now('UTC')->subMinutes(5)->format('Y-m-d H:i:s'));

        $this->assertNull(MessagingWindowHelper::resolveLastInboundAtUtc($contact));
        $this->assertFalse(MessagingWindowHelper::isMessagingWindowOpen($contact));
    }

    /** العمود المملوء يبقى المصدر كما كان، ولا يُنظر في الرسائل. */
    public function test_the_stored_column_is_still_used_when_present(): void
    {
        $contact = $this->makeContact('+966501110006');
        $stored = now('UTC')->subHours(3)->format('Y-m-d H:i:s');
        $this->insertChat($contact, 'inbound', now('UTC')->subHours(40)->format('Y-m-d H:i:s'));
        DB::table('contacts')->where('id', $contact->id)->update(['last_inbound_chat_created_at' => $stored]);

        $contact = $contact->fresh();

        $this->assertSame($stored, MessagingWindowHelper::resolveLastInboundAtUtc($contact)?->toDateTimeString());
        $this->assertTrue(MessagingWindowHelper::isMessagingWindowOpen($contact));
    }

    private function makeContact(string $phone): Contact
    {
        return Contact::create([
            'organization_id' => $this->organization->id,
            'phone' => $phone,
            'created_by' => $this->user->id,
        ]);
    }

    /** بـ DB::table كي لا يملأ Chat::created العمود — الحالة التي يمرّ فيها الاحتياطي. */
    private function insertChat(Contact $contact, string $type, string $at, ?int $importId = null): void
    {
        DB::table('chats')->insert([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'contact_id' => $contact->id,
            'type' => $type,
            'metadata' => json_encode(['type' => 'text', 'text' => ['body' => 'x']]),
            'status' => 'delivered',
            'is_read' => 1,
            'created_at' => $at,
            'chat_import_id' => $importId,
        ]);
    }
}
