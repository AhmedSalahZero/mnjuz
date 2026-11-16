<?php

namespace Tests\Feature\MobileApi;

use App\Events\NewChatEvent;
use App\Models\Contact;
use App\Models\Template;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * عقد نسخ التطبيق القديمة: الإرسال بالرقم.
 *
 * النسخ المنشورة ترسل `phone` لا `contact_uuid`. كل ما أُضيف للقنوات يعمل
 * بـ contact_uuid وحده، فطلب النسخة القديمة يجب أن يمرّ ويُرَدّ عليه كما
 * كان: المفاتيح نفسها في data، والرسائل نفسها. لا endpoint جديد للإرسال —
 * هذا الملف هو الضمان، فإن انكسر هنا انكسر عند من لم يحدّث التطبيق.
 */
class MobileOldClientContractTest extends MobileChannelsTestCase
{
    private const PHONE = '+966500000123';

    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake();
        Event::fake([NewChatEvent::class]);
        Storage::fake('local');

        $this->connectWhatsapp();
        $this->contact = $this->whatsappContact(['phone' => self::PHONE]);
        $this->openWindow($this->contact);
    }

    public function test_a_text_by_phone_keeps_its_old_response(): void
    {
        $response = $this->postJson('/api/v1/send-msg', [
            'phone' => self::PHONE,
            'type' => 'text',
            'message' => 'مرحباً',
        ])->assertOk()
            ->assertJsonPath('statusCode', 200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', __('Message queued for sending.'));

        $this->assertSame(['queued', 'contact_id', 'contact_uuid', 'phone'], array_keys($response->json('data')));
        $this->assertSame(self::PHONE, $response->json('data.phone'));
        $this->assertSame((string) $this->contact->uuid, $response->json('data.contact_uuid'));
    }

    public function test_a_file_by_phone_keeps_its_old_response(): void
    {
        $response = $this->post('/api/v1/send-msg', [
            'phone' => self::PHONE,
            'file' => UploadedFile::fake()->create('invoice.pdf', 20, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('statusCode', 200)
            ->assertJsonPath('success', true);

        $this->assertSame(['queued', 'files', 'contact_id', 'contact_uuid', 'phone'], array_keys($response->json('data')));
        $this->assertSame(self::PHONE, $response->json('data.phone'));
    }

    public function test_the_last_chunk_by_phone_keeps_its_old_response(): void
    {
        $response = $this->post('/api/v1/chats/upload/chunk', [
            'phone' => self::PHONE,
            'upload_id' => 'old-client-1',
            'index' => 0,
            'total' => 1,
            'chunk' => UploadedFile::fake()->create('part0', 20),
            'file_name' => 'invoice.pdf',
        ], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(
            ['completed', 'queued', 'received', 'total', 'contact_id', 'contact_uuid', 'phone'],
            array_keys($response->json('data'))
        );
        $this->assertSame(self::PHONE, $response->json('data.phone'));
    }

    public function test_a_closed_window_by_phone_keeps_the_whatsapp_wording(): void
    {
        $closed = $this->whatsappContact(['phone' => '+966500000777']);

        $this->postJson('/api/v1/send-msg', [
            'phone' => '+966500000777',
            'type' => 'text',
            'message' => 'مرحباً',
        ])->assertStatus(422)
            ->assertJsonPath('statusCode', 422)
            ->assertJsonPath('message', __('Whatsapp does not allow sending messages 24 hours after they last messaged you. However, you can send them a template message'));

        $this->assertNotNull($closed->fresh());
    }

    public function test_a_template_by_phone_still_sends(): void
    {
        $template = Template::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'meta_id' => '9001',
            'name' => 'welcome',
            'category' => 'MARKETING',
            'language' => 'ar',
            'status' => 'APPROVED',
            'metadata' => json_encode(['components' => [['type' => 'BODY', 'text' => 'أهلاً']]]),
            'created_by' => $this->owner->id,
        ]);

        $this->postJson('/api/v1/send-template', [
            'phone' => self::PHONE,
            'template_uuid' => (string) $template->uuid,
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', __('Template sent successfully'));

        $this->assertSame(1, Contact::where('organization_id', $this->organization->id)->where('phone', self::PHONE)->count());
    }

    /**
     * حتى بعد تفعيل قيود الموظّف: من يرسل برقمٍ حقيقي يكتبه يمرّ، ويعود
     * إليه الرقم الذي كتبه. الإخفاء يمسّ ما يُقرأ، لا ما يُرسل.
     */
    public function test_with_restrictions_on_a_real_phone_still_sends_unmasked(): void
    {
        config(['chat.mobile_agent_restrictions' => true]);
        $this->ticketSettings(['encrypt_contacts_for_agents' => true]);
        $this->actAs($this->agent());

        $this->postJson('/api/v1/send-msg', [
            'phone' => self::PHONE,
            'type' => 'text',
            'message' => 'مرحباً',
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.phone', self::PHONE);
    }

    private function openWindow(Contact $contact): void
    {
        $this->message($contact, ['created_at' => now()->subMinutes(5)]);
        Contact::where('id', $contact->id)->update(['last_inbound_chat_created_at' => now()->subMinutes(5)]);
    }
}
