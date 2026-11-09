<?php

namespace Tests\Feature\Channels;

use App\Events\NewChatEvent;
use App\Jobs\ProcessChannelMediaDownloadJob;
use App\Models\ChannelAccount;
use App\Models\Chat;
use App\Models\ChatLog;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\User;
use App\Services\Messaging\Channel;
use App\Services\Messaging\ChannelManager;
use App\Services\Messaging\ContactResolver;
use App\Services\Messaging\InboundMedia;
use App\Services\Messaging\InboundMessage;
use App\Services\Messaging\InboundMessageRecorder;
use App\Services\Messaging\InboundStatus;
use App\Services\Messaging\StatusRecorder;
use App\Services\Messaging\Support\MessageId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * طبقة القنوات نفسها: تسجيل الوارد، وتحديث الحالات، وهوية جهة الاتصال.
 *
 * ما يُثبَّت هنا هو العقد الذي يجعل بقيّة النظام يعمل بلا تعديل: نفس صفوف
 * chats و chat_logs، ونفس حدث البثّ، ونفس سلّم الحالات.
 */
class ChannelCoreTest extends TestCase
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
            'channel' => Channel::TELEGRAM,
            'external_id' => 'bot-1',
            'credentials' => ['bot_token' => 'x'],
            'status' => ChannelAccount::STATUS_ACTIVE,
            'created_by' => 0,
        ]);
    }

    // ───────────────────── هوية جهة الاتصال ─────────────────────

    /**
     * قرار صاحب المنتج: من يراسلنا من واتساب ومن تيليجرام شخصان
     * منفصلان لا شخص واحد.
     */
    public function test_the_same_person_on_two_channels_is_two_contacts(): void
    {
        $whatsapp = Contact::create([
            'organization_id' => $this->organization->id,
            'phone' => '+966500000001',
            'first_name' => 'أحمد',
            'created_by' => 0,
        ]);

        [$telegram] = app(ContactResolver::class)->resolve($this->account, $this->message('m1', 'tg-555', 'أحمد'));

        $this->assertNotSame($whatsapp->id, $telegram->id);
        $this->assertSame(Channel::WHATSAPP, $whatsapp->channel);
        $this->assertSame(Channel::TELEGRAM, $telegram->channel);
        $this->assertNull($telegram->phone);
        $this->assertSame('tg-555', $telegram->channel_identifier);
    }

    /** الرسالة الثانية من المُرسِل نفسه لا تُنشئ جهة اتصال ثانية. */
    public function test_a_second_message_reuses_the_same_contact(): void
    {
        $resolver = app(ContactResolver::class);

        [$first, $isNewFirst] = $resolver->resolve($this->account, $this->message('m1', 'tg-555', 'أحمد'));
        [$second, $isNewSecond] = $resolver->resolve($this->account, $this->message('m2', 'tg-555', 'أحمد'));

        $this->assertTrue($isNewFirst);
        $this->assertFalse($isNewSecond);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Contact::where('channel', Channel::TELEGRAM)->count());
    }

    /** ومُرسِلان مختلفان على القناة نفسها جهتا اتصال مختلفتان. */
    public function test_two_senders_on_one_channel_are_two_contacts(): void
    {
        $resolver = app(ContactResolver::class);

        $resolver->resolve($this->account, $this->message('m1', 'tg-111', 'أحمد'));
        $resolver->resolve($this->account, $this->message('m2', 'tg-222', 'سارة'));

        $this->assertSame(2, Contact::where('channel', Channel::TELEGRAM)->count());
    }

    /**
     * الاسم يُملأ إن كان غائباً، ولا يُكتب فوقه إن كان موجوداً — قد يكون
     * الموظّف عدّله يدوياً.
     */
    public function test_an_existing_name_is_never_overwritten(): void
    {
        $resolver = app(ContactResolver::class);

        [$contact] = $resolver->resolve($this->account, $this->message('m1', 'tg-555', 'أحمد سالم'));
        $this->assertSame('أحمد', $contact->first_name);
        $this->assertSame('سالم', $contact->last_name);

        $contact->update(['first_name' => 'العميل المهمّ', 'last_name' => null]);

        $resolver->resolve($this->account, $this->message('m2', 'tg-555', 'أحمد سالم'));

        $this->assertSame('العميل المهمّ', $contact->fresh()->first_name);
    }

    /** المُرسِل بلا معرّف لا يُنشئ جهة اتصال — ولا يرمي. */
    public function test_a_message_without_a_sender_creates_nothing(): void
    {
        [$contact, $isNew] = app(ContactResolver::class)->resolve($this->account, $this->message('m1', '   '));

        $this->assertNull($contact);
        $this->assertFalse($isNew);
        $this->assertSame(0, Contact::count());
    }

    // ───────────────────── تسجيل الوارد ─────────────────────

    /**
     * الرسالة الواردة تُنتج نفس الصفوف التي ينتجها مسار واتساب:
     * صفّ chats، وسطر chat_logs، وتحديث أوقات جهة الاتصال.
     */
    public function test_an_inbound_message_produces_the_same_rows_as_whatsapp(): void
    {
        Event::fake([NewChatEvent::class]);

        $chat = app(InboundMessageRecorder::class)->record(
            $this->account,
            $this->message('m1', 'tg-555', 'أحمد')
        );

        $this->assertNotNull($chat);
        $this->assertSame('inbound', $chat->type);
        $this->assertSame('delivered', $chat->status);
        $this->assertSame(0, (int) $chat->is_read);
        $this->assertSame(Channel::TELEGRAM, $chat->channel);
        $this->assertSame((int) $this->organization->id, (int) $chat->organization_id);

        // سطر chat_logs هو ما تبني عليه الواجهة الخيط الزمني.
        $this->assertSame(1, ChatLog::where('entity_type', 'chat')->where('entity_id', $chat->id)->count());

        // خطّاف Chat::created يحدّث الوقتين — وعليهما تقوم نافذة الردّ
        // وترتيب قائمة المحادثات.
        $contact = Contact::find($chat->contact_id);
        $this->assertNotNull($contact->latest_chat_created_at);
        $this->assertNotNull($contact->last_inbound_chat_created_at);

        Event::assertDispatched(NewChatEvent::class);
    }

    /** الرسالة المكرّرة لا تُسجَّل مرّتين. */
    public function test_a_duplicate_message_is_recorded_only_once(): void
    {
        Event::fake([NewChatEvent::class]);

        $recorder = app(InboundMessageRecorder::class);

        $first = $recorder->record($this->account, $this->message('m1', 'tg-555'));
        $second = $recorder->record($this->account, $this->message('m1', 'tg-555'));

        $this->assertNotNull($first);
        $this->assertNull($second);
        $this->assertSame(1, Chat::count());
    }

    /**
     * رسالة الوسائط تؤجّل البثّ حتى ينتهي التنزيل — وإلّا ظهرت فقاعة
     * بلا ملف ثم قفزت.
     */
    public function test_a_media_message_defers_its_broadcast_to_the_download_job(): void
    {
        Bus::fake();
        Event::fake([NewChatEvent::class]);

        $message = new InboundMessage(
            providerMessageId: MessageId::namespaced(Channel::TELEGRAM, 'bot-1', '9', 'tg-555'),
            senderId: 'tg-555',
            metadata: ['type' => 'image', 'image' => ['caption' => 'فاتورة']],
            media: new InboundMedia(type: 'image', providerId: 'file-1', mimeType: 'image/jpeg'),
        );

        $chat = app(InboundMessageRecorder::class)->record($this->account, $message);

        $this->assertNotNull($chat);
        Bus::assertDispatched(ProcessChannelMediaDownloadJob::class);
        Event::assertNotDispatched(NewChatEvent::class);
    }

    /** الصدى يُسجَّل صادراً بلا موظّف ومقروءاً. */
    public function test_an_echo_is_recorded_as_an_outbound_read_message(): void
    {
        Event::fake([NewChatEvent::class]);

        $message = new InboundMessage(
            providerMessageId: MessageId::namespaced(Channel::TELEGRAM, 'bot-1', '10', 'tg-555'),
            senderId: 'tg-555',
            metadata: ['type' => 'text', 'text' => ['body' => 'ردّ من التطبيق']],
            isEcho: true,
        );

        $chat = app(InboundMessageRecorder::class)->record($this->account, $message);

        $this->assertNotNull($chat);
        $this->assertSame('outbound', $chat->type);
        $this->assertSame(1, (int) $chat->is_read);
        $this->assertNull($chat->user_id);
    }

    /** وصدى رسالة أرسلناها نحن لا يُسجَّل مرّتين. */
    public function test_an_echo_of_our_own_message_is_ignored(): void
    {
        Event::fake([NewChatEvent::class]);

        $contact = Contact::create([
            'organization_id' => $this->organization->id,
            'channel' => Channel::TELEGRAM,
            'channel_identifier' => 'tg-555',
            'phone' => null,
            'created_by' => 0,
        ]);

        $providerId = MessageId::namespaced(Channel::TELEGRAM, 'bot-1', '11', 'tg-555');

        Chat::create([
            'organization_id' => $this->organization->id,
            'wam_id' => $providerId,
            'contact_id' => $contact->id,
            'type' => 'outbound',
            'metadata' => '{"type":"text","text":{"body":"أرسلناها"}}',
            'status' => 'sent',
            'channel' => Channel::TELEGRAM,
            'created_at' => now(),
        ]);

        $echo = new InboundMessage(
            providerMessageId: $providerId,
            senderId: 'tg-555',
            metadata: ['type' => 'text', 'text' => ['body' => 'أرسلناها']],
            isEcho: true,
        );

        $this->assertNull(app(InboundMessageRecorder::class)->record($this->account, $echo));
        $this->assertSame(1, Chat::count());
    }

    // ───────────────────── سلّم الحالات ─────────────────────

    /** الحالة تتقدّم للأعلى. */
    public function test_a_status_advances_up_the_ladder(): void
    {
        $chat = $this->outboundChat('sent');

        app(StatusRecorder::class)->record($this->account, new InboundStatus(
            status: 'delivered',
            providerMessageIds: [$chat->wam_id],
        ));

        $this->assertSame('delivered', $chat->fresh()->status);
    }

    /**
     * ولا تتراجع. حدث تسليم متأخّر يصل بعد حدث القراءة لا يُعيد الرسالة
     * «مُسلَّمة» — وهو ما يراه الموظّف علامةً تنقص أمام عينه.
     */
    public function test_a_late_event_never_moves_a_status_backwards(): void
    {
        $chat = $this->outboundChat('read');

        app(StatusRecorder::class)->record($this->account, new InboundStatus(
            status: 'delivered',
            providerMessageIds: [$chat->wam_id],
        ));

        $this->assertSame('read', $chat->fresh()->status);
    }

    /** والعلامة المائية تُطبَّق على كل ما أُرسل قبلها. */
    public function test_a_watermark_updates_everything_sent_before_it(): void
    {
        $contact = Contact::create([
            'organization_id' => $this->organization->id,
            'channel' => Channel::TELEGRAM,
            'channel_identifier' => 'tg-555',
            'phone' => null,
            'created_by' => 0,
        ]);

        foreach ([1, 2, 3] as $i) {
            Chat::create([
                'organization_id' => $this->organization->id,
                'wam_id' => 'tg:bot-1:tg-555:' . $i,
                'contact_id' => $contact->id,
                'type' => 'outbound',
                'metadata' => '{"type":"text","text":{"body":"x"}}',
                'status' => 'sent',
                'channel' => Channel::TELEGRAM,
                'created_at' => now()->subMinutes(10),
            ]);
        }

        // ورسالة أحدث من العلامة — يجب ألّا تتأثّر.
        Chat::create([
            'organization_id' => $this->organization->id,
            'wam_id' => 'tg:bot-1:tg-555:4',
            'contact_id' => $contact->id,
            'type' => 'outbound',
            'metadata' => '{"type":"text","text":{"body":"x"}}',
            'status' => 'sent',
            'channel' => Channel::TELEGRAM,
            'created_at' => now()->addMinutes(10),
        ]);

        $updated = app(StatusRecorder::class)->record($this->account, new InboundStatus(
            status: 'read',
            watermark: now(),
            senderId: 'tg-555',
        ));

        $this->assertSame(3, $updated);
        $this->assertSame('read', Chat::where('wam_id', 'tg:bot-1:tg-555:1')->value('status'));
        $this->assertSame('sent', Chat::where('wam_id', 'tg:bot-1:tg-555:4')->value('status'));
    }

    // ───────────────────── المدير ─────────────────────

    /** المدير يُعطي سائق كل قناة، ويرفض المجهول. */
    public function test_the_manager_resolves_every_channel(): void
    {
        $manager = app(ChannelManager::class);

        foreach (Channel::all() as $channel) {
            $this->assertSame($channel, $manager->driver($channel)->channel());
        }

        $this->expectException(\App\Services\Messaging\Exceptions\ChannelException::class);
        $manager->driver('myspace');
    }

    /** وسائق جهة الاتصال يتبع عمودها. */
    public function test_the_driver_follows_the_contact_channel(): void
    {
        $manager = app(ChannelManager::class);

        $whatsapp = new Contact(['organization_id' => $this->organization->id, 'channel' => Channel::WHATSAPP]);
        $telegram = new Contact(['organization_id' => $this->organization->id, 'channel' => Channel::TELEGRAM]);
        $legacy = new Contact(['organization_id' => $this->organization->id]);

        $this->assertSame(Channel::WHATSAPP, $manager->forContact($whatsapp)->channel());
        $this->assertSame(Channel::TELEGRAM, $manager->forContact($telegram)->channel());
        // جهة اتصال قديمة بلا قيمة صريحة تبقى واتساب.
        $this->assertSame(Channel::WHATSAPP, $manager->forContact($legacy)->channel());
    }

    /** القنوات المربوطة تُحسب من الجدول ومن metadata معاً. */
    public function test_connected_channels_include_both_sources(): void
    {
        $manager = app(ChannelManager::class);

        $channels = $manager->connectedChannels((int) $this->organization->id);
        $this->assertContains(Channel::TELEGRAM, $channels);
        // واتساب غير مربوطة في هذه المنشأة (لا metadata).
        $this->assertNotContains(Channel::WHATSAPP, $channels);
    }

    /** ورسالة الخطأ تُميّز «غير مربوطة» عن «تحتاج إعادة ربط». */
    public function test_the_connection_error_distinguishes_the_two_failures(): void
    {
        $manager = app(ChannelManager::class);
        $organizationId = (int) $this->organization->id;

        $this->assertNull($manager->connectionError($organizationId, Channel::TELEGRAM));

        $this->assertNotNull($manager->connectionError($organizationId, Channel::MESSENGER));

        $this->account->markReauthorizationRequired('انتهى التوكن');
        $error = $manager->connectionError($organizationId, Channel::TELEGRAM);
        $this->assertNotNull($error);
    }

    // ───────────────────── تنميق المعرّفات ─────────────────────

    /** المعرّف لا يتجاوز سعة العمود مهما طال. */
    public function test_a_very_long_id_is_hashed_to_fit_the_column(): void
    {
        $long = str_repeat('a', 400);

        $id = MessageId::namespaced(Channel::MESSENGER, '1010101010', $long);

        $this->assertLessThanOrEqual(MessageId::MAX_LENGTH, strlen($id));
        $this->assertTrue(MessageId::isNamespaced($id));
    }

    /** ومعرّفان مختلفان يبقيان مختلفين بعد التقصير. */
    public function test_two_long_ids_stay_distinct_after_hashing(): void
    {
        $a = MessageId::namespaced(Channel::MESSENGER, '1010101010', str_repeat('a', 400));
        $b = MessageId::namespaced(Channel::MESSENGER, '1010101010', str_repeat('b', 400));

        $this->assertNotSame($a, $b);
    }

    /** والقناة تُستنتَج من المعرّف المخزَّن. */
    public function test_the_channel_can_be_read_back_from_a_stored_id(): void
    {
        $this->assertSame(Channel::TELEGRAM, MessageId::channelOf('tg:1:2:3'));
        $this->assertSame(Channel::MESSENGER, MessageId::channelOf('fb:1:m_x'));
        $this->assertSame(Channel::INSTAGRAM, MessageId::channelOf('ig:1:m_x'));
        $this->assertSame(Channel::TIKTOK, MessageId::channelOf('tt:1:msg'));
        $this->assertSame(Channel::WHATSAPP, MessageId::channelOf('wamid.ABC'));
    }

    // ───────────────────── حساب المنصّة ─────────────────────

    /** بيانات الاعتماد مشفّرة في قاعدة البيانات لا نصّاً صريحاً. */
    public function test_credentials_are_encrypted_at_rest(): void
    {
        $raw = \Illuminate\Support\Facades\DB::table('channel_accounts')
            ->where('id', $this->account->id)
            ->value('credentials');

        $this->assertStringNotContainsString('bot_token', $raw);
        $this->assertSame('x', $this->account->fresh()->credential('bot_token'));
    }

    /** ولا تخرج إلى الواجهة إطلاقاً. */
    public function test_credentials_never_reach_the_frontend(): void
    {
        $array = $this->account->toArray();
        $this->assertArrayNotHasKey('credentials', $array);

        $settings = $this->account->toSettingsArray();
        $this->assertArrayNotHasKey('credentials', $settings);
        $this->assertSame(Channel::TELEGRAM, $settings['channel']);
    }

    /**
     * ولا يُربط الحساب الواحد بمنشأتين: القيد يمنع العطب الصامت الذي
     * يجعل الثانية لا تستقبل شيئاً أبداً.
     */
    public function test_one_account_cannot_be_connected_to_two_organizations(): void
    {
        $other = Organization::factory()->create([
            'created_by' => User::factory()->create(['role' => 'user'])->id,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        ChannelAccount::create([
            'organization_id' => $other->id,
            'channel' => Channel::TELEGRAM,
            'external_id' => 'bot-1',
            'credentials' => ['bot_token' => 'y'],
            'status' => ChannelAccount::STATUS_ACTIVE,
            'created_by' => 0,
        ]);
    }

    // ───────────────────── مساعدات ─────────────────────

    private function message(string $id, string $senderId, ?string $name = null): InboundMessage
    {
        return new InboundMessage(
            providerMessageId: MessageId::namespaced(Channel::TELEGRAM, 'bot-1', $id, $senderId),
            senderId: $senderId,
            metadata: ['type' => 'text', 'text' => ['body' => 'مرحبا']],
            senderName: $name,
        );
    }

    private function outboundChat(string $status): Chat
    {
        $contact = Contact::create([
            'organization_id' => $this->organization->id,
            'channel' => Channel::TELEGRAM,
            'channel_identifier' => 'tg-555',
            'phone' => null,
            'created_by' => 0,
        ]);

        return Chat::create([
            'organization_id' => $this->organization->id,
            'wam_id' => 'tg:bot-1:tg-555:99',
            'contact_id' => $contact->id,
            'type' => 'outbound',
            'metadata' => '{"type":"text","text":{"body":"x"}}',
            'status' => $status,
            'channel' => Channel::TELEGRAM,
            'created_at' => now(),
        ]);
    }
}
