<?php

namespace Tests\Feature\WebChat;

use App\Events\NewChatEvent;
use App\Events\WebChatVisitorEvent;
use App\Jobs\ProcessChannelMediaDownloadJob;
use App\Models\ChannelAccount;
use App\Models\Chat;
use App\Models\Contact;
use App\Models\Setting;
use App\Services\Broadcasting\BroadcastProvider;
use App\Services\ChatService;
use App\Services\Messaging\Channel;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * البثّ اللحظي إلى الزائر: ما يُبثّ، وعلى أي قناة، ومن يوقّع اشتراكه.
 */
class RealtimeTest extends WebChatTestCase
{
    protected function tearDown(): void
    {
        BroadcastProvider::forget();

        parent::tearDown();
    }

    // ───────────────────── ما يُبثّ ─────────────────────

    public function test_an_agent_reply_reaches_the_visitors_private_channel(): void
    {
        [$token, $visitor, $contact] = $this->conversation();
        $agent = $this->actAsOwner();

        $this->agentSends($contact, 'أهلاً بك 👋');

        $event = $this->lastVisitorEvent();
        $this->assertSame(
            'private-webchat.ch' . $this->organization->id . '.' . $visitor->uuid,
            $event->broadcastOn()[0]->name
        );
        $this->assertSame('webchat.event', $event->broadcastAs());
        // لا يتسرّب للزائر إلا الصفّ: لا معرّف المنشأة ولا غيره.
        $this->assertSame(['kind', 'message'], array_keys($event->broadcastWith()));
        $this->assertSame('out', $event->payload['message']['direction']);
        $this->assertSame('أهلاً بك 👋', $event->payload['message']['text']);
        $this->assertSame(['kind' => 'agent', 'name' => $agent->first_name], $event->payload['message']['sender']);
    }

    public function test_the_event_goes_through_the_organizations_own_provider(): void
    {
        [, , $contact] = $this->conversation();
        $this->organization->forceFill(['broadcast_provider' => 'reverb'])->save();
        BroadcastProvider::forget();
        $this->actAsOwner();

        $this->agentSends($contact, 'مرحبا');

        $this->assertSame(['provider_reverb'], $this->lastVisitorEvent()->broadcastConnections());
    }

    public function test_other_channels_never_reach_a_visitor(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 5]])]);
        $telegram = ChannelAccount::create([
            'organization_id' => $this->organization->id,
            'channel' => Channel::TELEGRAM,
            'external_id' => 'bot-1',
            'credentials' => ['bot_token' => 'tok', 'webhook_secret' => 's'],
            'status' => ChannelAccount::STATUS_ACTIVE,
            'created_by' => 0,
        ]);
        $contact = Contact::create([
            'organization_id' => $this->organization->id,
            'channel' => Channel::TELEGRAM,
            'channel_identifier' => 'tg-1',
            'channel_account_id' => $telegram->id,
            'first_name' => 'علي',
            'created_by' => 0,
        ]);
        $this->actAsOwner();

        $this->agentSends($contact, 'مرحبا');

        Event::assertNotDispatched(WebChatVisitorEvent::class);
    }

    public function test_a_long_arabic_message_becomes_a_refresh(): void
    {
        $short = WebChatVisitorEvent::message(1, 'v', ['seq' => 9, 'text' => 'مرحبا']);
        $long = WebChatVisitorEvent::message(1, 'v', ['seq' => 9, 'text' => str_repeat('م', 2000)]);

        $this->assertSame('message', $short->payload['kind']);
        // ٢٠٠٠ حرف عربي = ١٢٠٠٠ بايت بعد \uXXXX — فوق حدّ المزوّد.
        $this->assertSame(['kind' => 'refresh', 'seq' => 9], $long->broadcastWith());
    }

    public function test_the_visitors_own_message_is_echoed_once_for_their_other_tabs(): void
    {
        [$token] = $this->openSession();
        $clientId = (string) Str::uuid();

        $this->send($token, ['client_message_id' => $clientId, 'text' => 'مرحبا'])->assertStatus(202);
        $this->send($token, ['client_message_id' => $clientId, 'text' => 'مرحبا'])->assertStatus(202);

        Event::assertDispatchedTimes(WebChatVisitorEvent::class, 1);
        $event = $this->lastVisitorEvent();
        $this->assertSame('in', $event->payload['message']['direction']);
        $this->assertSame($clientId, $event->payload['message']['client_id']);
    }

    public function test_an_uploaded_file_reaches_the_visitor_with_its_link(): void
    {
        Storage::fake('local');
        Setting::updateOrCreate(['key' => 'storage_system'], ['value' => 'local']);
        [$token] = $this->openSession();

        $this->post($this->url('uploads'), [
            'client_message_id' => (string) Str::uuid(),
            'file' => UploadedFile::fake()->image('a.png', 10, 10),
        ], ['X-Webchat-Token' => $token, 'Accept' => 'application/json'])->assertStatus(202);

        Event::assertDispatched(WebChatVisitorEvent::class, fn (WebChatVisitorEvent $e) => str_contains(
            (string) ($e->payload['message']['media']['url'] ?? ''),
            '/media/public/'
        ));
    }

    public function test_a_file_that_could_not_be_saved_is_flagged_for_the_visitor(): void
    {
        [, , $contact] = $this->conversation();
        $chat = Chat::create([
            'organization_id' => $this->organization->id,
            'wam_id' => 'wc:test:upload-1',
            'contact_id' => $contact->id,
            'type' => 'inbound',
            'metadata' => json_encode(['type' => 'image', 'image' => ['mime_type' => 'image/png']]),
            'status' => 'delivered',
            'channel' => Channel::WEBCHAT,
        ]);

        (new ProcessChannelMediaDownloadJob($chat->id, $this->widget->id, ['type' => 'image']))
            ->failed(new \RuntimeException('disk full'));

        $this->assertTrue(json_decode($chat->fresh()->metadata, true)['media_failed']);
        Event::assertDispatched(WebChatVisitorEvent::class, fn (WebChatVisitorEvent $e) => ($e->payload['message']['media']['failed'] ?? false) === true);
    }

    public function test_clearing_the_conversation_tells_the_visitor_to_refresh(): void
    {
        [, , $contact] = $this->conversation();
        $this->actAsOwner();

        (new ChatService($this->organization->id))->clearContactChat($contact->uuid);

        $this->assertSame(['kind' => 'refresh', 'seq' => null], $this->lastVisitorEvent()->broadcastWith());
    }

    public function test_a_visitor_is_linked_as_soon_as_their_contact_exists(): void
    {
        [, $visitor] = $this->openSession();

        $contact = Contact::create([
            'organization_id' => $this->organization->id,
            'channel' => Channel::WEBCHAT,
            'channel_identifier' => $visitor->uuid,
            'channel_account_id' => $this->widget->id,
            'created_by' => 0,
        ]);

        $this->assertSame($contact->id, (int) $visitor->fresh()->contact_id);
    }

    // ───────────────────── صحّ القراءة للموظّف ─────────────────────

    public function test_read_marks_reach_the_agent_for_changed_messages_only(): void
    {
        [$token, , $contact] = $this->conversation();
        $this->actAsOwner();
        $this->agentSends($contact, 'الأولى');
        $this->agentSends($contact, 'الثانية');
        $last = Chat::where('contact_id', $contact->id)->max('id');

        $this->postJson($this->url('read'), ['seq' => $last], ['X-Webchat-Token' => $token])->assertOk();
        $this->postJson($this->url('read'), ['seq' => $last], ['X-Webchat-Token' => $token])->assertOk();

        $statusEvents = collect(Event::dispatched(NewChatEvent::class))->map(fn ($args) => $args[0])->filter->statusChanged;
        $this->assertCount(2, $statusEvents);
        $this->assertSame(['read', 'read'], $statusEvents->map(fn ($e) => $e->chat[0]['value']['status'])->values()->all());
    }

    public function test_the_inbox_payload_carries_the_channel_and_display_phone(): void
    {
        $this->conversation();

        $event = collect(Event::dispatched(NewChatEvent::class))->map(fn ($args) => $args[0])->last();

        $this->assertSame(Channel::WEBCHAT, $event->chat[0]['value']['channel']);
        $this->assertSame('+966 50 123 4567', $event->chat[0]['value']['formatted_phone_number']);
    }

    // ───────────────────── توقيع القناة ─────────────────────

    public function test_the_visitor_channel_is_signed_with_the_organizations_pusher_secret(): void
    {
        $this->configureProviders();
        [$token, $visitor] = $this->openSession();
        $channel = 'private-webchat.ch' . $this->organization->id . '.' . $visitor->uuid;

        $auth = $this->authorize($token, $channel)->assertOk()->json('auth');

        $this->assertSame('pusher-key:' . hash_hmac('sha256', '1234.5678:' . $channel, 'pusher-secret'), $auth);
    }

    public function test_a_reverb_organization_signs_with_the_reverb_secret(): void
    {
        $this->configureProviders();
        $this->organization->forceFill(['broadcast_provider' => 'reverb'])->save();
        BroadcastProvider::forget();
        [$token, $visitor] = $this->openSession();
        $channel = 'private-webchat.ch' . $this->organization->id . '.' . $visitor->uuid;

        $auth = $this->authorize($token, $channel)->assertOk()->json('auth');

        $this->assertSame('reverb-key:' . hash_hmac('sha256', '1234.5678:' . $channel, 'reverb-secret'), $auth);
    }

    public function test_only_the_visitors_own_channel_can_be_signed(): void
    {
        $this->configureProviders();
        [$token, $visitor] = $this->openSession();
        [, $other] = $this->openSession(['name' => 'خالد']);
        $own = 'private-webchat.ch' . $this->organization->id . '.' . $visitor->uuid;

        $this->authorize($token, 'private-webchat.ch' . $this->organization->id . '.' . $other->uuid)->assertForbidden();
        $this->authorize($token, 'private-webchat.ch999.' . $visitor->uuid)->assertForbidden();
        $this->authorize($token, 'private-chats.ch' . $this->organization->id . '.1')->assertForbidden();
        $this->authorize($token, $own, 'not-a-socket')->assertForbidden();

        $this->postJson($this->url('broadcasting/auth'), ['socket_id' => '1.2', 'channel_name' => $own])->assertUnauthorized();

        $this->setSettings(['enabled' => false]);
        $this->authorize($token, $own)->assertNotFound();
    }

    public function test_signing_is_rate_limited(): void
    {
        $this->configureProviders();
        [$token, $visitor] = $this->openSession();
        $channel = 'private-webchat.ch' . $this->organization->id . '.' . $visitor->uuid;

        for ($i = 0; $i < 30; $i++) {
            $this->authorize($token, $channel)->assertOk();
        }

        $this->authorize($token, $channel)->assertStatus(429);
    }

    public function test_config_offers_realtime_only_when_broadcasting_is_live(): void
    {
        config(['broadcasting.default' => 'log']);
        $this->getJson($this->url('config'))->assertOk()->assertJsonPath('realtime', null);

        config(['broadcasting.default' => 'pusher']);
        $response = $this->getJson($this->url('config'))->assertOk();

        $this->assertSame('pusher', $response->json('realtime.provider'));
        $this->assertStringContainsString('Origin', (string) $response->headers->get('Vary'));
    }

    // ───────────────────── أدوات ─────────────────────

    /** زائر كتب رسالة، فصارت له جهة اتصال. */
    private function conversation(): array
    {
        [$token, $visitor] = $this->openSession();
        $this->send($token, ['text' => 'مرحبا'])->assertStatus(202);
        $visitor->refresh();

        return [$token, $visitor, Contact::find($visitor->contact_id)];
    }

    private function agentSends(Contact $contact, string $text): void
    {
        $result = (new ChatService($this->organization->id))->sendMessage(
            Request::create('/chats', 'POST', ['uuid' => $contact->uuid, 'message' => $text])
        );

        $this->assertTrue((bool) ($result->success ?? false), 'إرسال الموظّف نجح.');
    }

    private function lastVisitorEvent(): WebChatVisitorEvent
    {
        $event = collect(Event::dispatched(WebChatVisitorEvent::class))->map(fn ($args) => $args[0])->last();
        $this->assertNotNull($event, 'بُثّ حدث للزائر.');

        return $event;
    }

    private function configureProviders(): void
    {
        config(['broadcasting.default' => 'pusher']);

        foreach ([
            'pusher_app_key' => 'pusher-key', 'pusher_app_secret' => 'pusher-secret', 'pusher_app_id' => '101',
            'pusher_app_cluster' => 'mt1',
            'reverb_app_key' => 'reverb-key', 'reverb_app_secret' => 'reverb-secret', 'reverb_app_id' => '202',
            'reverb_host' => 'ws.example.com', 'reverb_port' => '443', 'reverb_scheme' => 'https',
        ] as $key => $value) {
            Setting::updateOrCreate(['key' => $key], ['value' => $value]);
        }

        BroadcastProvider::forget();
    }

    private function authorize(string $token, string $channel, string $socketId = '1234.5678')
    {
        return $this->postJson($this->url('broadcasting/auth'), [
            'socket_id' => $socketId,
            'channel_name' => $channel,
        ], ['X-Webchat-Token' => $token]);
    }
}
