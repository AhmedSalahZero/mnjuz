<?php

namespace Tests\Feature;

use App\Http\Resources\ContactListResource;
use App\Models\Chat;
use App\Models\ChatLog;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * معاينة آخر رسالة في قائمة المحادثات.
 *
 * واتساب ترسل التفاعل بالإيموجي رسالةً مستقلّة، فكان يصير «آخر رسالة» في
 * 2,022 محادثة ويُظهر سطر المعاينة فارغاً — لا فرع يعرضه ولا نصّ فيه.
 * المعاينة تتخطّاه الآن إلى آخر رسالة حقيقية، والترتيب يبقى كما كان فلا تفقد
 * المحادثة موضعها حين يتفاعل العميل.
 */
class LastChatPreviewTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;
    private Contact $contact;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['role' => 'user']);
        $this->organization = Organization::factory()->create(['created_by' => $this->user->id]);
        $this->contact = Contact::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'first_name' => 'Maitha',
            'phone' => '+966500000001',
            'created_by' => $this->user->id,
        ]);
    }

    /**
     * رسالة كاملة: صفّ في chats ومدخل في chat_logs.
     *
     * المدخل شرطٌ في العلاقة (whereHas('chatLog'))، فرسالة بلا مدخل لا تُعدّ
     * آخر رسالة أصلاً.
     */
    private function message(array $metadata, string $minutesAgo = '10', string $direction = 'outbound'): Chat
    {
        $chat = Chat::create([
            'organization_id' => $this->organization->id,
            'contact_id' => $this->contact->id,
            'wam_id' => 'wamid.' . Str::random(10),
            'type' => $direction,
            'status' => 'delivered',
            'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE),
            'created_at' => now()->subMinutes((int) $minutesAgo),
        ]);

        ChatLog::create([
            'contact_id' => $this->contact->id,
            'entity_type' => 'chat',
            'entity_id' => $chat->id,
            'created_at' => $chat->getRawOriginal('created_at'),
        ]);

        return $chat;
    }

    private function reaction(string $emoji = '❤️', string $minutesAgo = '1'): Chat
    {
        return $this->message(
            ['type' => 'reaction', 'reaction' => ['message_id' => 'wamid.PARENT', 'emoji' => $emoji]],
            $minutesAgo,
            'inbound'
        );
    }

    private function previewMetadata(): ?array
    {
        $contact = Contact::with('lastChat')->find($this->contact->id);

        return $contact->lastChat ? json_decode($contact->lastChat->metadata, true) : null;
    }

    // ------------------------------------------------- جوهر الإصلاح

    /** حالة العميلة MaithaFaris: أُرسل «نورتينا» فتفاعلت عليها بقلب. */
    public function test_a_trailing_reaction_is_skipped_for_the_preview(): void
    {
        $this->message(['type' => 'text', 'text' => ['body' => 'نورتينا']], '10');
        $this->reaction('❤️', '1');

        $preview = $this->previewMetadata();

        $this->assertSame('text', $preview['type'], 'التفاعل لا يصلح معاينةً');
        $this->assertSame('نورتينا', $preview['text']['body']);
    }

    /** عدّة تفاعلات متتالية تُتخطّى كلّها لا آخرها فقط. */
    public function test_consecutive_reactions_are_all_skipped(): void
    {
        $this->message(['type' => 'text', 'text' => ['body' => 'شكراً لك']], '30');
        $this->reaction('❤️', '3');
        $this->reaction('👍', '2');
        $this->reaction('🙏', '1');

        $this->assertSame('شكراً لك', $this->previewMetadata()['text']['body']);
    }

    /** إزالة تفاعل (إيموجي فارغ) رسالةُ تفاعلٍ أيضاً، فتُتخطّى. */
    public function test_removing_a_reaction_is_skipped_too(): void
    {
        $this->message(['type' => 'text', 'text' => ['body' => 'تمام']], '20');
        $this->reaction('', '1');

        $this->assertSame('تمام', $this->previewMetadata()['text']['body']);
    }

    /** الوسائط تصلح معاينةً وإن لم يكن فيها نصّ — الواجهة تصفها بنوعها. */
    public function test_a_media_message_is_a_valid_preview(): void
    {
        $this->message(['type' => 'image', 'image' => []], '10');
        $this->reaction('❤️', '1');

        $this->assertSame('image', $this->previewMetadata()['type']);
    }

    // ------------------------------------- ألّا ينكسر ما كان يعمل

    public function test_a_normal_last_message_is_untouched(): void
    {
        $this->message(['type' => 'text', 'text' => ['body' => 'الأقدم']], '30');
        $this->message(['type' => 'text', 'text' => ['body' => 'الأحدث']], '1');

        $this->assertSame('الأحدث', $this->previewMetadata()['text']['body']);
    }

    public function test_a_conversation_with_no_messages_has_no_preview(): void
    {
        $this->assertNull($this->previewMetadata());
    }

    /** رسالة بلا مدخل في الشريط الزمني لا تُعدّ آخر رسالة — سلوك قائم. */
    public function test_a_chat_without_a_timeline_entry_is_still_ignored(): void
    {
        $this->message(['type' => 'text', 'text' => ['body' => 'ظاهرة']], '10');

        Chat::create([
            'organization_id' => $this->organization->id,
            'contact_id' => $this->contact->id,
            'wam_id' => 'wamid.ORPHAN',
            'type' => 'outbound',
            'status' => 'delivered',
            'metadata' => json_encode(['type' => 'text', 'text' => ['body' => 'يتيمة']]),
            'created_at' => now(),
        ]);

        $this->assertSame('ظاهرة', $this->previewMetadata()['text']['body']);
    }

    // ------------------------------------------------- الترتيب

    /**
     * التفاعل ما زال يرفع المحادثة إلى أعلى القائمة: الترتيب على
     * latest_chat_created_at لا على المعاينة. نشاط جديد وقع بالفعل، وإخفاؤه
     * من الترتيب كان سيُخفي المحادثة عن الموظّف.
     */
    public function test_a_reaction_still_bumps_the_conversation(): void
    {
        $this->message(['type' => 'text', 'text' => ['body' => 'قديمة']], '60');
        $before = Contact::find($this->contact->id)->latest_chat_created_at;

        $this->reaction('❤️', '0');
        $after = Contact::find($this->contact->id)->latest_chat_created_at;

        $this->assertNotSame($before, $after, 'وقت آخر نشاط يجب أن يتقدّم بالتفاعل');
    }

    // --------------------------------------- ما يصل الواجهة فعلاً

    public function test_the_list_payload_carries_the_real_message(): void
    {
        $this->message(['type' => 'text', 'text' => ['body' => 'نورتينا']], '10');
        $this->reaction('❤️', '1');

        $contact = Contact::with('lastChat')->find($this->contact->id);
        $payload = (new ContactListResource($contact))->toArray(request());

        $metadata = json_decode($payload['last_chat']['metadata'], true);

        $this->assertSame('text', $metadata['type']);
        $this->assertSame('نورتينا', $metadata['text']['body']);
        $this->assertNotNull($payload['latest_chat_created_at'], 'وقت آخر نشاط يبقى محفوظاً للترتيب');
    }

    /** العزل: معاينة جهة اتصال لا تلتقط رسائل جهة أخرى. */
    public function test_the_preview_never_crosses_contacts(): void
    {
        $this->message(['type' => 'text', 'text' => ['body' => 'لنا']], '10');
        $this->reaction('❤️', '1');

        $other = Contact::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'first_name' => 'Other',
            'phone' => '+966500000002',
            'created_by' => $this->user->id,
        ]);
        $originalContact = $this->contact;
        $this->contact = $other;
        $this->message(['type' => 'text', 'text' => ['body' => 'لغيرنا']], '2');
        $this->contact = $originalContact;

        $this->assertSame('لنا', $this->previewMetadata()['text']['body']);
    }
}
