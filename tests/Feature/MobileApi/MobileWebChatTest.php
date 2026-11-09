<?php

namespace Tests\Feature\MobileApi;

use App\Events\NewChatEvent;
use App\Events\WebChatVisitorEvent;
use App\Models\Chat;
use App\Models\Contact;
use App\Models\WebChatVisitor;
use App\Services\Messaging\Channel;
use App\Services\WebChat\WebChatWidgetService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

/**
 * الردّ على زائر المحادثة المباشرة من تطبيق الجوال: الزائر بلا رقم واتساب،
 * فالتطبيق يرسل بـ contact_uuid — ومنشأة بلا واتساب أصلاً لا تُحجب.
 */
class MobileWebChatTest extends MobileApiTestCase
{
    private Contact $visitor;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Event::fake([NewChatEvent::class, WebChatVisitorEvent::class]);

        $widget = app(WebChatWidgetService::class)->ensureForOrganization($this->organization->id, 'متجر');
        $visitor = WebChatVisitor::create([
            'organization_id' => $this->organization->id,
            'channel_account_id' => $widget->id,
            'token_hash' => WebChatVisitor::hashToken(WebChatVisitor::newToken()),
            'name' => 'سارة',
            'phone' => '+966501234567',
        ]);
        $this->visitor = Contact::create([
            'organization_id' => $this->organization->id,
            'channel' => Channel::WEBCHAT,
            'channel_identifier' => $visitor->uuid,
            'channel_account_id' => $widget->id,
            'first_name' => 'سارة',
            'formatted_phone' => '+966 50 123 4567',
            'created_by' => 0,
        ])->fresh(); // HasUuid يضع كائن Uuid لا نصّاً حتى يُعاد التحميل.
    }

    protected function planMetadata(): array
    {
        $metadata = parent::planMetadata();
        $metadata['addons']['Web Chat'] = true;

        return $metadata;
    }

    public function test_the_app_replies_to_a_visitor_by_contact_uuid(): void
    {
        $this->postJson('/api/v1/send-msg', [
            'type' => 'text',
            'contact_uuid' => $this->visitor->uuid,
            'message' => 'أهلاً سارة، كيف نخدمك؟',
        ])->assertOk()->assertJsonPath('success', true)->assertJsonPath('data.channel', Channel::WEBCHAT);

        $chat = Chat::where('contact_id', $this->visitor->id)->where('type', 'outbound')->first();
        $this->assertSame('أهلاً سارة، كيف نخدمك؟', json_decode($chat->metadata, true)['text']['body']);
        $this->assertSame((int) $this->owner->id, (int) $chat->user_id, 'الرسالة باسم الموظّف.');
        Event::assertDispatched(WebChatVisitorEvent::class);
    }

    public function test_the_app_sends_a_file_to_a_visitor(): void
    {
        $this->post('/api/v1/send-msg', [
            'contact_uuid' => $this->visitor->uuid,
            'file' => UploadedFile::fake()->image('menu.png', 20, 20),
            'caption' => 'القائمة',
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('success', true);

        $chat = Chat::with('media')->where('contact_id', $this->visitor->id)->where('type', 'outbound')->first();
        $this->assertSame('image', json_decode($chat->metadata, true)['type']);
        $this->assertNotNull($chat->media);
    }

    public function test_a_chunked_upload_reaches_a_visitor(): void
    {
        $this->post('/api/v1/chats/upload/chunk', [
            'upload_id' => 'm-1',
            'index' => 0,
            'total' => 1,
            'chunk' => UploadedFile::fake()->image('big.png', 30, 30),
            'contact_uuid' => $this->visitor->uuid,
            'file_name' => 'big.png',
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.channel', Channel::WEBCHAT);

        $this->assertSame(1, Chat::where('contact_id', $this->visitor->id)->where('type', 'outbound')->count());
    }

    public function test_an_unknown_contact_uuid_is_not_found(): void
    {
        $this->postJson('/api/v1/send-msg', [
            'type' => 'text',
            'contact_uuid' => '00000000-0000-4000-8000-000000000000',
            'message' => 'مرحبا',
        ])->assertNotFound();
    }

    public function test_a_contact_of_another_organization_is_not_reachable(): void
    {
        $other = Contact::create([
            'organization_id' => $this->organization->id + 1000,
            'channel' => Channel::WEBCHAT,
            'channel_identifier' => 'someone-else',
            'first_name' => 'غريب',
            'created_by' => 0,
        ]);

        $this->postJson('/api/v1/send-msg', [
            'type' => 'text',
            'contact_uuid' => $other->uuid,
            'message' => 'مرحبا',
        ])->assertNotFound();
    }
}
