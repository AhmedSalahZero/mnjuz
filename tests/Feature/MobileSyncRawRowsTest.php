<?php

namespace Tests\Feature;

use App\Http\Controllers\ApiController;
use App\Models\Chat;
use App\Models\ChatLog;
use App\Models\ChatMedia;
use App\Models\ChatStatusLog;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

/**
 * مزامنة الجوال تقرأ chat_logs وchats صفوفاً خام لا Models.
 *
 * الدافع أداء: مرحلة التحميل كانت 80% منها بناء Models في PHP لا انتظاراً
 * لقاعدة البيانات. لكن الـModel كان يُخفي عملاً لا يظهر في الصفّ الخام —
 * أهمّه accessor التاريخ في Chat الذي يحوّل created_at إلى توقيت المنشأة،
 * وappends في User. هذه الاختبارات تحرس ذلك العمل المُستعاد يدوياً: أيّ
 * إغفال فيه يصل التطبيق تاريخاً بفارق ساعات أو اسم مُرسِل فارغاً.
 */
class MobileSyncRawRowsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;
    private Contact $contact;
    private User $user;

    /** فرق ثلاث ساعات عن UTC — يكشف إغفال تحويل التوقيت فوراً. */
    private const TIMEZONE = 'Asia/Riyadh';

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'role' => 'user',
            'first_name' => 'أحمد',
            'last_name' => 'صلاح',
        ]);

        $this->organization = Organization::factory()->create([
            'created_by' => $this->user->id,
            'metadata' => json_encode(['timezone' => self::TIMEZONE]),
        ]);

        $this->contact = Contact::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'first_name' => 'ندى',
            'last_name' => 'العتيبي',
            'phone' => '+966500000123',
            'created_by' => $this->user->id,
        ]);
    }

    // ------------------------------------------------------------- أدوات

    private function message(array $metadata, array $attributes = []): Chat
    {
        $chat = Chat::create(array_merge([
            'organization_id' => $this->organization->id,
            'contact_id' => $this->contact->id,
            'wam_id' => 'wamid.' . Str::random(10),
            'type' => 'outbound',
            'status' => 'delivered',
            'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE),
            'created_at' => '2026-08-20 21:30:00',
        ], $attributes));

        ChatLog::create([
            'contact_id' => $this->contact->id,
            'entity_type' => 'chat',
            'entity_id' => $chat->id,
            'created_at' => $chat->getRawOriginal('created_at'),
        ]);

        return $chat;
    }

    /** الخريطة التي تبني منها نقطتا المزامنة رسائلهما. */
    private function syncData(?string $createdAt = null, array $entityTypes = []): array
    {
        $method = new ReflectionMethod(ApiController::class, 'loadChatSyncData');
        $method->setAccessible(true);

        return $method->invoke(
            app(ApiController::class),
            $this->organization->id,
            $createdAt,
            $entityTypes,
            null,
            1
        );
    }

    private function formatted(Chat $chat): array
    {
        $data = $this->syncData();
        $row = $data['chatsMap']->get($chat->id);

        $this->assertNotNull($row, 'الرسالة غابت عن خريطة المزامنة');

        $method = new ReflectionMethod(ApiController::class, 'formatChatValue');
        $method->setAccessible(true);

        return $method->invoke(app(ApiController::class), $row, $this->contact, null, null);
    }

    // ------------------------------------------------- الحقل الأخطر: التاريخ

    /**
     * accessor التاريخ في Chat كان يحوّل created_at إلى توقيت المنشأة. الصفّ
     * الخام يأتي بـUTC، فإن لم يُجرَ التحويل يدوياً وصل التطبيق وقتاً أقدم
     * بثلاث ساعات — خطأ صامت لا يُسقط شيئاً ويُربك ترتيب الفقاعات عند العميل.
     */
    public function test_created_at_is_converted_to_the_organization_timezone(): void
    {
        $chat = $this->message(['type' => 'text', 'text' => ['body' => 'مساء الخير']]);

        $this->assertSame('2026-08-21 00:30:00', $this->formatted($chat)['created_at']);
    }

    /** نفس ما كان يُخرجه الـModel بالضبط — لا اجتهاد في الصياغة. */
    public function test_created_at_matches_what_the_eloquent_accessor_produced(): void
    {
        $chat = $this->message(['type' => 'text', 'text' => ['body' => 'تطابق']]);

        $this->assertSame(
            Chat::find($chat->id)->created_at,
            $this->formatted($chat)['created_at']
        );
    }

    // ------------------------------------------------------------ العلاقات

    public function test_media_reaches_the_app_from_the_raw_row(): void
    {
        $media = ChatMedia::create([
            'name' => 'invoice.pdf',
            'path' => 'https://cdn.example.com/invoice.pdf',
            'location' => 'local',
            'type' => 'application/pdf',
            'size' => '482910',
        ]);

        $chat = $this->message(
            ['type' => 'document', 'document' => ['filename' => 'invoice.pdf']],
            ['media_id' => $media->id]
        );

        $this->assertSame([
            'type' => 'application/pdf',
            'size' => '482910',
            'path' => 'https://cdn.example.com/invoice.pdf',
            'name' => 'invoice.pdf',
        ], $this->formatted($chat)['media']);
    }

    public function test_media_is_null_when_the_message_has_no_file(): void
    {
        $chat = $this->message(['type' => 'text', 'text' => ['body' => 'بلا ملف']]);

        $this->assertNull($this->formatted($chat)['media']);
    }

    /**
     * الـModel كان يُلحق full_name تلقائياً (appends). الصفّ الخام يحمل
     * الاسمين وحدهما، والتنسيق يركّبهما — والنتيجة يجب أن تبقى واحدة.
     */
    public function test_sender_name_survives_without_the_appended_attribute(): void
    {
        $chat = $this->message(
            ['type' => 'text', 'text' => ['body' => 'من الموظّف']],
            ['user_id' => $this->user->id]
        );

        $this->assertSame(['full_name' => 'أحمد صلاح'], $this->formatted($chat)['user']);
    }

    public function test_inbound_message_has_no_sender(): void
    {
        $chat = $this->message(
            ['type' => 'text', 'text' => ['body' => 'من العميل']],
            ['type' => 'inbound', 'user_id' => null]
        );

        $this->assertNull($this->formatted($chat)['user']);
    }

    /**
     * سجلّات الحالة تُقصّ إلى آخر ستّة، فترتيبها يقرّر أيّها يصل. العلاقة
     * كانت تُرجعها بترتيب المعرّف، والاستعلام الخام يجب أن يفعل المثل.
     */
    public function test_status_logs_keep_their_order_and_last_six_window(): void
    {
        $chat = $this->message(['type' => 'text', 'text' => ['body' => 'حالات']]);

        foreach (['accepted', 'sent', 'delivered', 'read', 'failed', 'sent', 'delivered'] as $status) {
            ChatStatusLog::create([
                'chat_id' => $chat->id,
                'metadata' => json_encode(['id' => $chat->wam_id, 'status' => $status]),
                'created_at' => '2026-08-20 21:31:00',
            ]);
        }

        $logs = $this->formatted($chat)['logs'];
        $statuses = array_map(
            static fn ($log) => json_decode($log['metadata'], true)['status'] ?? null,
            $logs
        );

        $this->assertCount(6, $logs, 'يجب أن تصل آخر ستّة سجلات فقط');
        $this->assertSame(['sent', 'delivered', 'read', 'failed', 'sent', 'delivered'], $statuses);
    }

    public function test_logs_are_an_empty_array_when_there_are_none(): void
    {
        $chat = $this->message(['type' => 'text', 'text' => ['body' => 'بلا سجلات']]);

        $this->assertSame([], $this->formatted($chat)['logs']);
    }

    // -------------------------------------------------------- بقيّة الحقول

    public function test_plain_columns_pass_through_unchanged(): void
    {
        $chat = $this->message(
            ['type' => 'text', 'text' => ['body' => 'أعمدة']],
            ['status' => 'read', 'deleted_at' => '2026-08-20 22:00:00']
        );

        $value = $this->formatted($chat);

        $this->assertSame($chat->id, $value['id']);
        // الـModel للتوّ يحمل كائن Uuid، والصفّ من القاعدة نصّاً — والردّ نصّ.
        $this->assertSame((string) $chat->uuid, $value['uuid']);
        $this->assertSame('outbound', $value['type']);
        $this->assertSame($chat->wam_id, $value['wam_id']);
        $this->assertSame('read', $value['status']);
        $this->assertSame('2026-08-20 22:00:00', $value['deleted_at']);
    }

    /** الترشيح المشترك يبقى فعّالاً بعد التحوّل إلى الصفوف الخام. */
    public function test_reactions_are_still_excluded(): void
    {
        $text = $this->message(['type' => 'text', 'text' => ['body' => 'نصّ']]);
        $reaction = $this->message(
            ['type' => 'reaction', 'reaction' => ['message_id' => 'wamid.X', 'emoji' => '👍']],
            ['type' => 'inbound']
        );

        $map = $this->syncData()['chatsMap'];

        $this->assertNotNull($map->get($text->id));
        $this->assertNull($map->get($reaction->id), 'التفاعل يجب ألّا يصل التطبيق');
    }

    // ------------------------------------------------------ حارس الانحدار

    /**
     * الغرض من التغيير كلّه ألّا تُبنى Models للقراءتين الكبيرتين. عودتها
     * لا تكسر أي اختبار سلوكي — الردّ يبقى صحيحاً — لكنها تُعيد ثلثي الزمن
     * الذي وفّرناه. فنحرسها بالنوع صراحةً.
     */
    public function test_the_two_big_reads_do_not_build_eloquent_models(): void
    {
        $chat = $this->message(['type' => 'text', 'text' => ['body' => 'حارس']]);
        $data = $this->syncData();

        $this->assertIsArray(
            $data['chatsMap']->get($chat->id),
            'خريطة الرسائل يجب أن تكون صفوفاً خاماً لا Models'
        );

        $log = $data['logsByContact']->get($this->contact->id)->first();
        $this->assertNotInstanceOf(
            ChatLog::class,
            $log,
            'سجلّات المحادثة يجب أن تكون صفوفاً خاماً لا Models'
        );
    }

    // ------------------------------------------------- تكافؤ v1 و v2

    /**
     * المسارَان يبنيان من نفس الصفّ. اختلافهما في حقول جهة الاتصال وحدها،
     * أمّا كتلة الرسالة فيجب أن تتطابق حرفياً.
     */
    public function test_v1_and_v2_produce_the_same_message_block(): void
    {
        $media = ChatMedia::create([
            'name' => 'photo.jpg',
            'path' => 'https://cdn.example.com/photo.jpg',
            'location' => 'local',
            'type' => 'image/jpeg',
            'size' => '19843',
        ]);

        $chat = $this->message(
            ['type' => 'image', 'image' => ['caption' => 'الفاتورة']],
            ['media_id' => $media->id, 'user_id' => $this->user->id]
        );

        $row = $this->syncData()['chatsMap']->get($chat->id);

        $v2Method = new ReflectionMethod(ApiController::class, 'formatChatMessageV2');
        $v2Method->setAccessible(true);
        $v2 = $v2Method->invoke(app(ApiController::class), $row);

        $v1 = $this->formatted($chat);

        foreach (array_keys($v2) as $key) {
            $this->assertSame($v2[$key], $v1[$key], "الحقل {$key} اختلف بين v1 و v2");
        }
    }
}
