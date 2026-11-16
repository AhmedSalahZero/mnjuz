<?php

namespace Tests\Feature\MobileApi;

use App\Events\NewChatEvent;
use App\Events\WebChatVisitorEvent;
use App\Models\Chat;
use App\Models\Contact;
use App\Models\Template;
use App\Models\WebChatVisitor;
use App\Services\ContactService;
use App\Services\Messaging\Channel;
use App\Services\WebChat\WebChatWidgetService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * القنوات في دورة المحادثة على التطبيق: المزامنة وجهات الاتصال والتذاكر،
 * والإرسال بـ contact_uuid، وما لا تدعمه كل قناة.
 *
 * عبر HTTP لا بنداء الدوال الخاصّة: عطل قناة v2 بقي مخفيّاً لأن الاختبار
 * القديم يمرّر الصفّ كاملاً للمنسّق بنفسه.
 */
class MobileChatChannelsTest extends MobileChannelsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Event::fake([NewChatEvent::class, WebChatVisitorEvent::class]);
    }

    protected function planMetadata(): array
    {
        $metadata = parent::planMetadata();
        $metadata['addons']['Web Chat'] = true;

        return $metadata;
    }

    // ------------------------------------------------------------ المزامنة

    public function test_sync_v2_reports_the_channel_of_each_message(): void
    {
        $contact = $this->channelContact(Channel::TELEGRAM);
        $this->message($contact);

        $response = $this->getJson('/api/v1/list-messages-from-uuid-to-end-v2')->assertOk();

        $this->assertSame(Channel::TELEGRAM, $response->json('data.0.messages.0.value.channel'));
    }

    public function test_sync_v2_contact_carries_its_channel_fields(): void
    {
        $contact = $this->channelContact(Channel::TELEGRAM, ['channel_identifier' => 'tg-42']);
        $this->message($contact);

        $data = $this->getJson('/api/v1/list-messages-from-uuid-to-end-v2')->assertOk()->json('data.0.contact');

        $this->assertSame(Channel::TELEGRAM, $data['channel']);
        $this->assertSame('tg-42', $data['channel_identifier']);
        $this->assertSame('khaled_tg', $data['channel_username']);
        $this->assertNull($data['messaging_window_hours'], 'تيليجرام بلا نافذة');
        $this->assertNull($data['phone']);
    }

    public function test_sync_keeps_every_whatsapp_value_and_adds_the_new_keys(): void
    {
        $contact = $this->whatsappContact(['phone' => '+966500000123']);
        $this->message($contact);

        $v2 = $this->getJson('/api/v1/list-messages-from-uuid-to-end-v2')->assertOk()->json('data.0');
        $this->assertSame('+966500000123', $v2['contact']['phone']);
        $this->assertSame(Channel::WHATSAPP, $v2['contact']['channel']);
        $this->assertSame(24, $v2['contact']['messaging_window_hours']);
        $this->assertSame(Channel::WHATSAPP, $v2['messages'][0]['value']['channel']);

        $v1 = $this->getJson('/api/v1/list-messages-from-uuid-to-end')->assertOk()->json('data.0');
        $this->assertSame(Channel::WHATSAPP, $v1['channel']);
        $this->assertSame('+966500000123', $v1['messages'][0]['value']['phone']);
        $this->assertSame(Channel::WHATSAPP, $v1['messages'][0]['value']['channel']);
    }

    public function test_sync_filters_by_channel_and_ignores_an_unknown_one(): void
    {
        $this->message($this->whatsappContact());
        $this->message($this->channelContact(Channel::TELEGRAM));

        foreach (['/api/v1/list-messages-from-uuid-to-end', '/api/v1/list-messages-from-uuid-to-end-v2'] as $url) {
            $this->assertCount(1, $this->getJson($url . '?channel=telegram')->assertOk()->json('data'), $url);
            $this->assertCount(2, $this->getJson($url . '?channel=fax')->assertOk()->json('data'), $url);
            $this->assertCount(2, $this->getJson($url)->assertOk()->json('data'), $url);
        }
    }

    public function test_sync_backfills_a_single_conversation_by_contact_uuid(): void
    {
        $wanted = $this->channelContact(Channel::TELEGRAM);
        $this->message($wanted);
        $this->message($this->whatsappContact());

        $data = $this->getJson('/api/v1/list-messages-from-uuid-to-end-v2?contact_uuid=' . $wanted->uuid)
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $data);
        $this->assertSame((string) $wanted->uuid, $data[0]['contact']['contact_uuid']);
        $this->getJson('/api/v1/list-messages-from-uuid-to-end-v2?contact_uuid=not-a-uuid')->assertStatus(400);
    }

    public function test_sync_reports_the_latest_ticket_not_the_oldest(): void
    {
        $contact = $this->whatsappContact();
        $this->message($contact);
        $agent = $this->agent();
        $this->ticket($contact, ['status' => 'closed', 'assigned_to' => null, 'is_latest' => 0]);
        $this->ticket($contact, ['status' => 'open', 'assigned_to' => $agent->id, 'is_latest' => 1]);

        $v2 = $this->getJson('/api/v1/list-messages-from-uuid-to-end-v2')->assertOk()->json('data.0.contact');
        $this->assertSame('open', $v2['ticket_status']);
        $this->assertSame($agent->id, (int) $v2['ticket_assigned_to']);

        $v1 = $this->getJson('/api/v1/list-messages-from-uuid-to-end')->assertOk()->json('data.0');
        $this->assertSame('open', $v1['ticket_status']);
    }

    public function test_sync_reports_assigned_only_false_without_restrictions(): void
    {
        $this->message($this->whatsappContact());

        $this->getJson('/api/v1/list-messages-from-uuid-to-end-v2')->assertOk()->assertJsonPath('assigned_only', false);
        $this->getJson('/api/v1/list-messages-from-uuid-to-end')->assertOk()->assertJsonPath('assigned_only', false);
    }

    // ------------------------------------------------------- جهات الاتصال

    public function test_contacts_filter_by_channel_and_carry_channel_fields(): void
    {
        $this->whatsappContact();
        $telegram = $this->channelContact(Channel::TELEGRAM, ['channel_identifier' => 'tg-7']);

        $items = $this->getJson('/api/v1/contacts?channel=telegram')->assertOk()->json('data');

        $this->assertCount(1, $items);
        $this->assertSame((string) $telegram->uuid, $items[0]['uuid']);
        $this->assertSame('tg-7', $items[0]['channel_identifier']);
        $this->assertSame('khaled_tg', $items[0]['channel_username']);
        $this->assertArrayHasKey('messaging_window_hours', $items[0]);
        $this->assertNull($items[0]['messaging_window_hours']);
        $this->assertCount(2, $this->getJson('/api/v1/contacts?channel=fax')->assertOk()->json('data'));
    }

    public function test_contacts_list_carries_the_web_chat_visitor_card(): void
    {
        $visitor = $this->visitorContact();

        $items = collect($this->getJson('/api/v1/contacts?channel=webchat')->assertOk()->json('data'));
        $card = $items->firstWhere('uuid', (string) $visitor->uuid)['webchat_visitor'] ?? null;

        $this->assertIsArray($card);
        $this->assertSame('https://shop.example.com/cart', $card['last_page_url']);
        $this->assertArrayNotHasKey('token_hash', $card);
    }

    public function test_contact_detail_carries_channel_fields_without_the_raw_visitor_row(): void
    {
        $visitor = $this->visitorContact();

        $data = $this->getJson('/api/v1/contacts/' . $visitor->uuid)->assertOk()->json('data');

        $this->assertFalse($data['channel_capabilities']['templates']);
        $this->assertNull($data['messaging_window_hours']);
        $this->assertTrue($data['is_messaging_window_open']);
        $this->assertSame('https://shop.example.com/cart', $data['webchat_visitor']['last_page_url']);
        $this->assertStringNotContainsString('token_hash', json_encode($data));
    }

    public function test_contact_detail_reports_the_latest_ticket(): void
    {
        $contact = $this->whatsappContact();
        $agent = $this->agent();
        $this->ticket($contact, ['status' => 'closed', 'assigned_to' => null, 'is_latest' => 0]);
        $this->ticket($contact, ['status' => 'open', 'assigned_to' => $agent->id, 'is_latest' => 1]);

        $ticket = $this->getJson('/api/v1/contacts/' . $contact->uuid)->assertOk()->json('data.chat_ticket');

        $this->assertSame('open', $ticket['status']);
        $this->assertSame($agent->id, (int) $ticket['assigned_to']);
    }

    public function test_updating_metadata_keeps_the_platform_username(): void
    {
        $contact = $this->channelContact(Channel::TELEGRAM);

        (new ContactService($this->organization->id))->store(
            Request::create('/', 'POST', ['first_name' => 'خالد', 'metadata' => ['City' => 'جدة']]),
            (string) $contact->uuid
        );

        $metadata = json_decode((string) $contact->fresh()->getRawOriginal('metadata'), true);
        $this->assertSame('جدة', $metadata['City']);
        $this->assertSame('khaled_tg', $metadata['channel_username']);
        $this->assertSame('Khaled', $metadata['channel_display_name']);
    }

    // ------------------------------------------------------------- التذاكر

    public function test_tickets_carry_the_channel_and_filter_by_it(): void
    {
        $visitor = $this->visitorContact();
        $this->ticket($visitor);
        $this->ticket($this->whatsappContact());

        $items = $this->getJson('/api/v1/tickets?channel=webchat')->assertOk()->json('data.items');

        $this->assertCount(1, $items);
        $this->assertSame(Channel::WEBCHAT, $items[0]['channel']);
        $this->assertSame('+966 50 123 4567', $items[0]['formatted_phone_number']);
        $this->assertNull($items[0]['phone']);
        $this->assertCount(2, $this->getJson('/api/v1/tickets')->assertOk()->json('data.items'));
        $this->assertSame(1, $this->getJson('/api/v1/tickets/summary?channel=webchat')->assertOk()->json('data.open'));
    }

    public function test_ticket_search_finds_a_visitor_by_the_display_number(): void
    {
        $this->ticket($this->visitorContact());

        $this->assertCount(1, $this->getJson('/api/v1/tickets?search=' . urlencode('50 123'))->assertOk()->json('data.items'));
    }

    // ------------------------------------------------------------- القوالب

    public function test_a_template_to_a_telegram_contact_is_refused(): void
    {
        $contact = $this->channelContact(Channel::TELEGRAM);
        $template = $this->template();

        $this->postJson('/api/v1/send-template', ['contact_uuid' => $contact->uuid, 'template_uuid' => $template->uuid])
            ->assertStatus(422)
            ->assertJsonPath('statusCode', 422)
            ->assertJsonPath('message', __(':feature is not available on :channel.', ['feature' => __('Templates'), 'channel' => 'Telegram']));

        $this->assertSame(1, Contact::where('organization_id', $this->organization->id)->count(), 'لا جهة واتساب جديدة');
    }

    public function test_a_template_to_a_web_chat_visitor_never_creates_a_whatsapp_contact(): void
    {
        $visitor = $this->visitorContact();
        $template = $this->template();
        $before = Contact::count();

        $this->postJson('/api/v1/send-template', ['contact_uuid' => $visitor->uuid, 'template_uuid' => $template->uuid])
            ->assertStatus(422);
        $this->postJson('/api/v1/send-auth-template', ['contact_uuid' => $visitor->uuid])
            ->assertStatus(422);

        $this->assertSame($before, Contact::count());
    }

    public function test_a_template_to_a_whatsapp_contact_by_uuid_goes_to_its_number(): void
    {
        Bus::fake();
        $this->connectWhatsapp();
        $contact = $this->whatsappContact(['phone' => '+966502486051']);
        $template = $this->template();

        $response = $this->postJson('/api/v1/send-template', ['contact_uuid' => $contact->uuid, 'template_uuid' => $template->uuid]);

        $response->assertOk()->assertJsonPath('success', true);
        $this->assertSame(1, Contact::where('organization_id', $this->organization->id)->count(), 'الجهة نفسها لا جهة جديدة');
    }

    public function test_an_unknown_contact_uuid_is_not_found_for_templates(): void
    {
        $this->postJson('/api/v1/send-template', ['contact_uuid' => (string) Str::uuid(), 'template_uuid' => $this->template()->uuid])
            ->assertStatus(404);
    }

    public function test_a_template_without_phone_or_contact_uuid_keeps_the_old_error(): void
    {
        $this->postJson('/api/v1/send-template', ['template_uuid' => $this->template()->uuid])
            ->assertStatus(400)
            ->assertJsonValidationErrors('phone', 'errors');
    }

    // ------------------------------------------------- النافذة والقدرات

    public function test_a_closed_window_file_on_messenger_gets_messenger_wording(): void
    {
        $contact = $this->channelContact(Channel::MESSENGER);

        $this->post('/api/v1/send-msg', [
            'contact_uuid' => $contact->uuid,
            'file' => UploadedFile::fake()->image('menu.jpg'),
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('statusCode', 422)
            ->assertJsonPath('message', __(':channel does not allow sending messages :hours hours after the customer last messaged you. Wait for them to write again.', [
                'channel' => 'Messenger',
                'hours' => 24,
            ]));
    }

    public function test_a_location_request_on_messenger_says_it_is_unavailable(): void
    {
        $contact = $this->channelContact(Channel::MESSENGER);

        $this->postJson('/api/v1/request-location', ['uuid' => $contact->uuid, 'body' => 'أرسل موقعك'])
            ->assertStatus(422)
            ->assertJsonPath('statusCode', 422)
            ->assertJsonPath('message', __(':feature is not available on :channel.', ['feature' => __('Location requests'), 'channel' => 'Messenger']));

        $this->postJson('/api/v1/send-location', ['uuid' => $contact->uuid, 'latitude' => 21.48, 'longitude' => 39.19])
            ->assertStatus(422)
            ->assertJsonPath('statusCode', 422)
            ->assertJsonPath('message', __(':feature is not available on :channel.', ['feature' => __('Sending a location'), 'channel' => 'Messenger']));
    }

    public function test_a_chunk_for_a_disconnected_telegram_contact_is_refused_before_storing(): void
    {
        $contact = $this->channelContact(Channel::TELEGRAM);

        $this->post('/api/v1/chats/upload/chunk', [
            'contact_uuid' => $contact->uuid,
            'upload_id' => 'up-1',
            'index' => 0,
            'total' => 2,
            'chunk' => UploadedFile::fake()->create('part0', 10),
            'file_name' => 'video.mp4',
        ], ['Accept' => 'application/json'])
            ->assertStatus(403)
            ->assertJsonPath('success', false);

        $this->assertSame([], Storage::disk('local')->allFiles(), 'لا قطعة مخزّنة');
    }

    public function test_a_text_by_contact_uuid_keeps_the_app_message_uuid(): void
    {
        $visitor = $this->visitorContact();
        $clientUuid = (string) Str::uuid();

        $this->postJson('/api/v1/send-msg', [
            'type' => 'text',
            'contact_uuid' => $visitor->uuid,
            'message' => 'أهلاً',
            'msg_uuid' => $clientUuid,
        ])->assertOk();

        $this->assertTrue(Chat::where('contact_id', $visitor->id)->where('uuid', $clientUuid)->exists());
    }

    // ------------------------------------------------------------ القنوات

    public function test_the_channel_catalog_is_readable_by_an_agent(): void
    {
        $this->telegramAccount();
        $this->actAs($this->agent());

        $data = $this->getJson('/api/v1/channels')->assertOk()->json('data');

        $this->assertSame([Channel::TELEGRAM], $data['connected']);
        $channels = collect($data['channels'])->keyBy('channel');
        $this->assertSame(Channel::all(), $channels->keys()->all());
        $this->assertTrue($channels[Channel::TELEGRAM]['connected']);
        $this->assertFalse($channels[Channel::TELEGRAM]['capabilities']['templates']);
        $this->assertArrayNotHasKey(Channel::EMAIL, $channels->all());
    }

    // ------------------------------------------------------------- أدوات

    private function visitorContact(): Contact
    {
        $widget = app(WebChatWidgetService::class)->ensureForOrganization($this->organization->id, 'متجر');
        $visitor = WebChatVisitor::create([
            'organization_id' => $this->organization->id,
            'channel_account_id' => $widget->id,
            'token_hash' => WebChatVisitor::hashToken(WebChatVisitor::newToken()),
            'name' => 'سارة',
            'phone' => '+966501234567',
            'last_page_url' => 'https://shop.example.com/cart',
        ]);

        return Contact::create([
            'organization_id' => $this->organization->id,
            'channel' => Channel::WEBCHAT,
            'channel_identifier' => $visitor->uuid,
            'channel_account_id' => $widget->id,
            'first_name' => 'سارة',
            'formatted_phone' => '+966 50 123 4567',
            'created_by' => 0,
        ])->fresh();
    }

    private function template(): Template
    {
        return Template::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'meta_id' => (string) random_int(100000, 999999),
            'name' => 'welcome',
            'category' => 'MARKETING',
            'language' => 'ar',
            'status' => 'APPROVED',
            'metadata' => json_encode(['components' => [['type' => 'BODY', 'text' => 'أهلاً']]]),
            'created_by' => $this->owner->id,
        ])->fresh();
    }
}
