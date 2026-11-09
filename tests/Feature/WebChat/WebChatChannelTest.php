<?php

namespace Tests\Feature\WebChat;

use App\Models\Addon;
use App\Models\ChannelAccount;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\User;
use App\Models\WebChatVisitor;
use App\Services\Messaging\Channel;
use App\Services\Messaging\ChannelManager;
use App\Services\Messaging\ContactResolver;
use App\Services\Messaging\Drivers\WebChatDriver;
use App\Services\Messaging\InboundMedia;
use App\Services\Messaging\InboundMessage;
use App\Services\Messaging\OutboundMedia;
use App\Services\Messaging\Support\MessageId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * المرحلة الأولى من المحادثة المباشرة: القناة نفسها، قبل أي واجهة عامّة.
 *
 * الأهمّ هنا ثلاثة أمور:
 *   - الزائر الذي يكتب رقم جهة واتساب قائمة جهةُ اتصال منفصلة، ولا يصطدم
 *     بفهرس الرقم الفريد.
 *   - القناة لا تتسرّب إلى ما لا يخصّها: صفحة ربط القنوات، والـ webhook،
 *     والحملات.
 *   - الإرسال لا يلمس الشبكة.
 */
class WebChatChannelTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;
    private ChannelAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = User::factory()->create(['role' => 'user']);
        $this->organization = Organization::factory()->create(['created_by' => $owner->id]);
        $this->account = ChannelAccount::create([
            'organization_id' => $this->organization->id,
            'channel' => Channel::WEBCHAT,
            'external_id' => 'wc_testwidgetkey0000000000',
            'name' => 'متجر الاختبار',
            'credentials' => [],
            'settings' => ['enabled' => true],
            'status' => ChannelAccount::STATUS_ACTIVE,
            'created_by' => 0,
        ]);
    }

    private function message(string $id, string $visitorUuid, string $name = 'سارة', ?string $phone = '+966500000001'): InboundMessage
    {
        return new InboundMessage(
            providerMessageId: MessageId::namespaced(Channel::WEBCHAT, $this->account->external_id, $id, $visitorUuid),
            senderId: $visitorUuid,
            metadata: ['type' => 'text', 'text' => ['body' => 'مرحبا']],
            senderName: $name,
            senderPhone: $phone,
            senderEmail: 'sara@example.com',
        );
    }

    // ───────────────────── التسجيل ─────────────────────

    public function test_the_channel_is_registered_with_its_driver(): void
    {
        $this->assertTrue(Channel::isValid('webchat'));
        $this->assertContains(Channel::WEBCHAT, Channel::all());
        $this->assertInstanceOf(WebChatDriver::class, app(ChannelManager::class)->driver(Channel::WEBCHAT));
        $this->assertSame('Web Chat', Channel::label(Channel::WEBCHAT));
    }

    public function test_capabilities_have_no_window_no_templates_and_no_campaigns(): void
    {
        $capabilities = app(ChannelManager::class)->capabilities(Channel::WEBCHAT);

        $this->assertTrue($capabilities->hasUnlimitedWindow());
        $this->assertFalse($capabilities->templates);
        $this->assertFalse($capabilities->campaigns);
        $this->assertFalse($capabilities->location);
        $this->assertTrue($capabilities->interactive);
    }

    public function test_message_ids_carry_their_own_prefix(): void
    {
        $id = MessageId::namespaced(Channel::WEBCHAT, 'wc_key', 'c1', 'visitor-uuid');

        $this->assertSame('wc:wc_key:visitor-uuid:c1', $id);
        $this->assertSame(Channel::WEBCHAT, MessageId::channelOf($id));
    }

    // ───────────────────── الهوية والرقم ─────────────────────

    public function test_a_visitor_with_a_whatsapp_contacts_phone_is_a_separate_contact(): void
    {
        $whatsapp = Contact::create([
            'organization_id' => $this->organization->id,
            'phone' => '+966500000001',
            'first_name' => 'سارة',
            'created_by' => 0,
        ]);

        [$visitorContact, $isNew] = app(ContactResolver::class)
            ->resolve($this->account, $this->message('c1', 'visitor-1'));

        $this->assertTrue($isNew);
        $this->assertNotSame($whatsapp->id, $visitorContact->id);
        $this->assertSame(Channel::WEBCHAT, $visitorContact->channel);
        $this->assertSame('visitor-1', $visitorContact->channel_identifier);

        // الرقم للعرض فقط: contacts.phone فارغ فلا تصادم مع فهرس الرقم الفريد،
        // وجهة واتساب لم تتغيّر.
        $this->assertNull($visitorContact->fresh()->phone);
        $this->assertNotEmpty($visitorContact->fresh()->formatted_phone);
        $this->assertSame('sara@example.com', $visitorContact->fresh()->email);
        $this->assertSame('+966500000001', $whatsapp->fresh()->phone);
    }

    public function test_the_visitor_phone_is_searchable_and_displayed(): void
    {
        [$contact] = app(ContactResolver::class)->resolve($this->account, $this->message('c1', 'visitor-1'));

        $this->assertStringContainsString('966', (string) $contact->fresh()->formatted_phone_number);
    }

    public function test_whatsapp_lookups_by_phone_never_return_a_web_visitor(): void
    {
        app(ContactResolver::class)->resolve($this->account, $this->message('c1', 'visitor-1'));

        $service = new \App\Services\ContactService($this->organization->id);

        $this->assertNull($service->findByPhoneInOrganization('+966500000001'));
    }

    public function test_a_returning_visitor_keeps_the_same_contact_and_fills_a_missing_phone(): void
    {
        $resolver = app(ContactResolver::class);
        [$first] = $resolver->resolve($this->account, $this->message('c1', 'visitor-1', 'سارة', null));
        $this->assertEmpty($first->fresh()->formatted_phone);

        [$second, $isNew] = $resolver->resolve($this->account, $this->message('c2', 'visitor-1'));

        $this->assertFalse($isNew);
        $this->assertSame($first->id, $second->id);
        $this->assertNotEmpty($second->fresh()->formatted_phone);
    }

    // ───────────────────── تعديل جهة الاتصال ─────────────────────

    public function test_editing_a_web_contact_never_writes_a_whatsapp_phone(): void
    {
        $this->actingAs(User::find($this->organization->created_by));
        [$contact] = app(ContactResolver::class)->resolve($this->account, $this->message('c1', 'visitor-1'));

        $request = new \Illuminate\Http\Request([
            'first_name' => 'سارة المعدّلة',
            'phone' => '+966500000001',
        ]);

        (new \App\Services\ContactService($this->organization->id))->store($request, $contact->uuid);

        $contact->refresh();
        $this->assertSame('سارة المعدّلة', $contact->first_name);
        $this->assertNull($contact->phone);
    }

    // ───────────────────── الإرسال ─────────────────────

    public function test_sending_never_touches_the_network(): void
    {
        Http::fake();
        $driver = app(ChannelManager::class)->driver(Channel::WEBCHAT);
        [$contact] = app(ContactResolver::class)->resolve($this->account, $this->message('c1', 'visitor-1'));

        $text = $driver->sendText($this->account, $contact, 'أهلاً');
        $media = $driver->sendMedia($this->account, $contact, new OutboundMedia(type: 'image', url: 'https://example.com/a.png'));

        $this->assertTrue($text->success);
        $this->assertTrue($media->success);
        $this->assertStringStartsWith('wc:', $text->providerMessageId);
        $this->assertNotSame($text->providerMessageId, $media->providerMessageId);
        Http::assertNothingSent();
    }

    public function test_media_without_a_link_or_of_an_unsupported_type_is_refused(): void
    {
        $driver = app(ChannelManager::class)->driver(Channel::WEBCHAT);
        [$contact] = app(ContactResolver::class)->resolve($this->account, $this->message('c1', 'visitor-1'));

        $this->assertFalse($driver->sendMedia($this->account, $contact, new OutboundMedia(type: 'image'))->success);
        $this->assertFalse($driver->sendMedia($this->account, $contact, new OutboundMedia(type: 'sticker', url: 'https://example.com/s.webp'))->success);
        $this->assertFalse($driver->sendLocation($this->account, $contact, ['latitude' => 1, 'longitude' => 2])->success);
    }

    // ───────────────────── الملفات المؤقّتة ─────────────────────

    public function test_uploaded_media_is_read_only_from_the_widgets_own_temp_directory(): void
    {
        Storage::fake(WebChatDriver::TEMP_DISK);
        $driver = app(ChannelManager::class)->driver(Channel::WEBCHAT);
        $own = WebChatDriver::tempDirectory($this->account) . '/photo.png';
        Storage::disk(WebChatDriver::TEMP_DISK)->put($own, 'PNGDATA');
        Storage::disk(WebChatDriver::TEMP_DISK)->put('public/secret.txt', 'SECRET');

        $read = $driver->downloadMedia($this->account, new InboundMedia(type: 'image', mimeType: 'image/png', extra: ['temp_path' => $own]));
        $this->assertSame('PNGDATA', $read->contents);

        foreach (['public/secret.txt', $own . '/../../../../public/secret.txt', ''] as $forged) {
            $this->assertNull(
                $driver->downloadMedia($this->account, new InboundMedia(type: 'image', extra: ['temp_path' => $forged])),
                "مسار مصنوع قُرئ: {$forged}"
            );
        }
    }

    // ───────────────────── ما لا يخصّ القناة ─────────────────────

    public function test_web_chat_has_no_webhook_endpoint(): void
    {
        $this->post('/webhook/channel/webchat/' . $this->account->external_id, [])->assertNotFound();
        $this->get('/webhook/channel/webchat')->assertNotFound();
    }

    public function test_web_chat_is_not_offered_on_the_channel_connection_page(): void
    {
        $this->assertNotContains(Channel::WEBCHAT, Channel::connectable());
        $this->assertContains(Channel::TELEGRAM, Channel::connectable());
    }

    public function test_web_chat_is_never_a_campaign_channel(): void
    {
        $manager = app(ChannelManager::class);

        $this->assertContains(Channel::WEBCHAT, $manager->connectedChannels($this->organization->id));
        $this->assertNotContains(Channel::WEBCHAT, $manager->campaignChannels($this->organization->id));
        $this->assertNotContains(Channel::WEBCHAT, $manager->campaignCapableChannels());
        $this->assertContains(Channel::WHATSAPP, $manager->campaignCapableChannels());
    }

    // ───────────────────── الزائر والتنظيف ─────────────────────

    public function test_the_visitor_token_is_stored_only_as_a_hash(): void
    {
        $token = WebChatVisitor::newToken();
        $visitor = WebChatVisitor::create([
            'organization_id' => $this->organization->id,
            'channel_account_id' => $this->account->id,
            'token_hash' => WebChatVisitor::hashToken($token),
            'name' => 'سارة',
            'phone' => '+966500000001',
        ]);

        $this->assertGreaterThanOrEqual(43, strlen($token));
        $this->assertNotSame($token, $visitor->token_hash);
        $this->assertSame($visitor->id, WebChatVisitor::findByToken($this->account, $token)?->id);
        $this->assertNull(WebChatVisitor::findByToken($this->account, 'wrong'));
        $this->assertArrayNotHasKey('token_hash', $visitor->toArray());

        // توكن widget لا يفتح زائراً على widget آخر.
        $other = ChannelAccount::create([
            'organization_id' => $this->organization->id,
            'channel' => Channel::WEBCHAT,
            'external_id' => 'wc_otherwidgetkey000000000',
            'credentials' => [],
            'status' => ChannelAccount::STATUS_ACTIVE,
            'created_by' => 0,
        ]);
        $this->assertNull(WebChatVisitor::findByToken($other, $token));
    }

    public function test_ip_addresses_are_truncated(): void
    {
        $this->assertSame('203.0.113.0', WebChatVisitor::truncateIp('203.0.113.77'));
        $this->assertSame('2001:db8:85a3:8d3::', WebChatVisitor::truncateIp('2001:db8:85a3:8d3:1319:8a2e:370:7348'));
        $this->assertNull(WebChatVisitor::truncateIp('not-an-ip'));
    }

    public function test_prune_removes_silent_visitors_and_stale_temp_files_only(): void
    {
        Storage::fake(WebChatDriver::TEMP_DISK);
        [$contact] = app(ContactResolver::class)->resolve($this->account, $this->message('c1', 'visitor-1'));

        $make = fn (array $extra) => WebChatVisitor::create($extra + [
            'organization_id' => $this->organization->id,
            'channel_account_id' => $this->account->id,
            'token_hash' => hash('sha256', uniqid('', true)),
        ]);

        $silentOld = $make([]);
        $silentOld->forceFill(['created_at' => now()->subDays(40)])->save();
        $silentNew = $make([]);
        $talkedOld = $make(['contact_id' => $contact->id]);
        $talkedOld->forceFill(['created_at' => now()->subDays(40)])->save();

        $dir = WebChatDriver::tempDirectory($this->account);
        Storage::disk(WebChatDriver::TEMP_DISK)->put("{$dir}/old.png", 'x');
        Storage::disk(WebChatDriver::TEMP_DISK)->put("{$dir}/new.png", 'x');
        touch(Storage::disk(WebChatDriver::TEMP_DISK)->path("{$dir}/old.png"), now()->subDays(2)->getTimestamp());

        $this->artisan('webchat:prune')->assertSuccessful();

        $this->assertNull(WebChatVisitor::withTrashed()->find($silentOld->id));
        $this->assertNotNull(WebChatVisitor::find($silentNew->id));
        $this->assertNotNull(WebChatVisitor::find($talkedOld->id));
        Storage::disk(WebChatDriver::TEMP_DISK)->assertMissing("{$dir}/old.png");
        Storage::disk(WebChatDriver::TEMP_DISK)->assertExists("{$dir}/new.png");
    }

    public function test_the_addon_exists_and_is_plan_restricted(): void
    {
        $addon = Addon::where('name', 'Web Chat')->first();

        $this->assertNotNull($addon);
        $this->assertSame(1, (int) $addon->is_plan_restricted);
        $this->assertFileExists(public_path('images/' . $addon->logo));
    }
}
