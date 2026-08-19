<?php

namespace Tests\Feature;

use App\Models\Chat;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ربط التفاعل برسالته.
 *
 * التفاعل يصل صفّاً مستقلاً بلا أي أثر لما تفاعل معه، فكان يظهر «تفاعل مع
 * رسالة» بلا ذكر أيّها — ومع رسالتين متتاليتين لا يعرف الموظّف على أيّهما وقع.
 * الرابط الوحيد هو reaction.message_id = wam_id للرسالة الأصل.
 *
 * الربط في النموذج لا في الواجهة عمداً: 44% من التفاعلات في الإنتاج تبعد عن
 * رسالتها أكثر من خمسين صفّاً، أي خارج الصفحة المحمّلة.
 */
class ReactionContextTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;
    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create(['role' => 'user']);
        $this->organization = Organization::factory()->create(['created_by' => $user->id]);
        $this->contact = Contact::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'first_name' => 'Ahmed',
            'phone' => '+201025894984',
            'created_by' => $user->id,
        ]);
    }

    private function message(string $wamId, array $metadata, string $direction = 'outbound'): Chat
    {
        return Chat::create([
            'organization_id' => $this->organization->id,
            'contact_id' => $this->contact->id,
            'wam_id' => $wamId,
            'type' => $direction,
            'status' => 'delivered',
            'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
        ]);
    }

    private function reaction(string $parentWamId, string $emoji = '❤️'): Chat
    {
        return $this->message(
            'wamid.R' . Str::random(8),
            ['type' => 'reaction', 'reaction' => ['message_id' => $parentWamId, 'emoji' => $emoji]],
            'inbound'
        );
    }

    // ------------------------------------------------------------- الربط

    public function test_a_reaction_resolves_the_message_it_belongs_to(): void
    {
        $parent = $this->message('wamid.PARENT', ['type' => 'text', 'text' => ['body' => 'على خير باذن الله']]);
        $reaction = $this->reaction('wamid.PARENT');

        $context = $reaction->reaction_context;

        $this->assertNotNull($context);
        $this->assertSame($parent->id, $context['id']);
        $this->assertSame('على خير باذن الله', $context['preview']);
        $this->assertSame('outbound', $context['direction']);
        $this->assertSame('text', $context['preview_type']);
    }

    /**
     * جوهر شكوى المستخدم: رسالتان متتاليتان وتفاعل على الأولى. لولا الربط
     * لكان التفاعل معلّقاً بينهما بلا دلالة.
     */
    public function test_the_right_message_is_picked_among_several(): void
    {
        $first = $this->message('wamid.FIRST', ['type' => 'text', 'text' => ['body' => 'الرسالة الأولى']]);
        $this->message('wamid.SECOND', ['type' => 'text', 'text' => ['body' => 'الرسالة الثانية']]);

        $context = $this->reaction('wamid.FIRST')->reaction_context;

        $this->assertSame($first->id, $context['id']);
        $this->assertSame('الرسالة الأولى', $context['preview']);
    }

    public function test_direction_distinguishes_our_message_from_the_customers(): void
    {
        $this->message('wamid.OURS', ['type' => 'text', 'text' => ['body' => 'منّا']], 'outbound');
        $this->message('wamid.THEIRS', ['type' => 'text', 'text' => ['body' => 'منه']], 'inbound');

        $this->assertSame('outbound', $this->reaction('wamid.OURS')->reaction_context['direction']);
        $this->assertSame('inbound', $this->reaction('wamid.THEIRS')->reaction_context['direction']);
    }

    /** 4% من التفاعلات لا تجد رسالتها — حُذفت أو خرجت عن مدّة الحفظ. */
    public function test_a_reaction_whose_message_is_gone_resolves_to_null(): void
    {
        $this->assertNull($this->reaction('wamid.DOES_NOT_EXIST')->reaction_context);
    }

    public function test_removing_a_reaction_still_resolves_its_message(): void
    {
        $parent = $this->message('wamid.P', ['type' => 'text', 'text' => ['body' => 'أهلاً']]);

        $context = $this->reaction('wamid.P', '')->reaction_context;

        $this->assertSame($parent->id, $context['id'], 'إزالة التفاعل تحتاج سياقها كما يحتاجه إضافته');
    }

    // -------------------------------------------------- نصّ المعاينة

    /**
     * @dataProvider previewCases
     */
    public function test_preview_text_is_derived_from_the_parent_type(array $metadata, string $expected): void
    {
        $wamId = 'wamid.T' . Str::random(8);
        $this->message($wamId, $metadata);

        $this->assertSame($expected, $this->reaction($wamId)->reaction_context['preview']);
    }

    public static function previewCases(): array
    {
        return [
            'نصّ' => [['type' => 'text', 'text' => ['body' => 'مرحباً']], 'مرحباً'],
            'صورة بتعليق' => [['type' => 'image', 'image' => ['caption' => 'الفاتورة']], 'الفاتورة'],
            'صورة بلا تعليق' => [['type' => 'image', 'image' => []], ''],
            'فيديو بتعليق' => [['type' => 'video', 'video' => ['caption' => 'شرح']], 'شرح'],
            'ملف بتعليق' => [['type' => 'document', 'document' => ['caption' => 'العقد']], 'العقد'],
            'موقع مسمّى' => [['type' => 'location', 'location' => ['name' => 'الفرع']], 'الفرع'],
            'زرّ' => [['type' => 'button', 'button' => ['text' => 'نعم']], 'نعم'],
            'ردّ زرّ تفاعلي' => [
                ['type' => 'interactive', 'interactive' => ['button_reply' => ['title' => 'أوافق']]],
                'أوافق',
            ],
            'ملصق بلا نصّ' => [['type' => 'sticker', 'sticker' => []], ''],
        ];
    }

    /** الأسطر الجديدة والمسافات المتتالية تُفسد سطر الاقتباس المفرد. */
    public function test_preview_collapses_whitespace_into_one_line(): void
    {
        $this->message('wamid.W', ['type' => 'text', 'text' => ['body' => "سطر\n\nآخر   بمسافات"]]);

        $this->assertSame('سطر آخر بمسافات', $this->reaction('wamid.W')->reaction_context['preview']);
    }

    /** القصّ بالمحارف لا بالبايتات: الحرف العربي بايتان فالقصّ الخام يشطره. */
    public function test_a_long_arabic_preview_is_truncated_without_breaking_characters(): void
    {
        $this->message('wamid.L', ['type' => 'text', 'text' => ['body' => str_repeat('م', 400)]]);

        $preview = $this->reaction('wamid.L')->reaction_context['preview'];

        $this->assertSame(91, mb_strlen($preview), '90 محرفاً وعلامة القصّ');
        $this->assertStringEndsWith('…', $preview);
        $this->assertTrue(mb_check_encoding($preview, 'UTF-8'));
    }

    // ------------------------------------------- التسلسل إلى الواجهة

    /** الواجهة تقرأ content.reaction_context، فيجب أن يخرج مع كل تسلسل. */
    public function test_the_context_is_serialized_with_the_message(): void
    {
        $this->message('wamid.S', ['type' => 'text', 'text' => ['body' => 'أهلاً']]);

        $array = $this->reaction('wamid.S')->toArray();

        $this->assertArrayHasKey('reaction_context', $array);
        $this->assertSame('أهلاً', $array['reaction_context']['preview']);
    }

    public function test_non_reaction_messages_carry_a_null_context(): void
    {
        $array = $this->message('wamid.N', ['type' => 'text', 'text' => ['body' => 'عادية']])->toArray();

        $this->assertArrayHasKey('reaction_context', $array);
        $this->assertNull($array['reaction_context']);
    }

    // ------------------------------------------------------- الأداء

    /**
     * كود إنتاجي على جدول من 3.2 مليون صفّ: المُلحَق يعمل على كل رسالة تُسلسَل،
     * فلا يجوز أن يستعلم لغير التفاعلات.
     */
    public function test_plain_messages_never_trigger_a_lookup(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->message('wamid.PLAIN' . $i, ['type' => 'text', 'text' => ['body' => "رسالة {$i}"]]);
        }
        $messages = Chat::where('contact_id', $this->contact->id)->get();

        DB::enableQueryLog();
        DB::flushQueryLog();
        $messages->toArray();

        $lookups = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'wam_id'))->count();
        $this->assertSame(0, $lookups, 'الرسائل العادية يجب ألّا تُطلق استعلاماً واحداً');
    }

    /**
     * استعلام واحد لكل تفاعل — بلا ذاكرة مؤقّتة ساكنة.
     *
     * كانت هنا ذاكرة ساكنة توفّر الاستعلام المكرّر، فكشف هذا الاختبار أنها
     * تبقى حيّةً بين الطلبات في عمّال الطوابير طويلة العمر: تنمو بلا حدّ،
     * وتُعيد نصّ رسالةٍ عُدِّلت بعد تخزينها. الصحّة قبل توفير استعلام مفهرس.
     */
    public function test_each_reaction_costs_exactly_one_indexed_lookup(): void
    {
        $this->message('wamid.HOT', ['type' => 'text', 'text' => ['body' => 'رسالة رائجة']]);
        foreach (['❤️', '👍', '🙏'] as $emoji) {
            $this->reaction('wamid.HOT', $emoji);
        }
        $reactions = Chat::where('contact_id', $this->contact->id)
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.type')) = 'reaction'")
            ->get();

        DB::enableQueryLog();
        DB::flushQueryLog();
        $reactions->toArray();

        $lookups = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'wam_id'))->count();
        $this->assertSame(3, $lookups, 'ثلاثة تفاعلات = ثلاثة استعلامات، لا نتيجة محفوظة تتقادم');
    }

    /**
     * نصّ الرسالة الأصل يُقرأ لحظة العرض لا من نسخة محفوظة: لو حُدِّثت الرسالة
     * يجب أن يعكس الاقتباس الجديد.
     */
    public function test_the_quote_reflects_the_message_as_it_is_now(): void
    {
        $parent = $this->message('wamid.FRESH', ['type' => 'text', 'text' => ['body' => 'النصّ الأول']]);
        $reaction = $this->reaction('wamid.FRESH');

        $this->assertSame('النصّ الأول', $reaction->reaction_context['preview']);

        DB::table('chats')->where('id', $parent->id)
            ->update(['metadata' => json_encode(['type' => 'text', 'text' => ['body' => 'النصّ المُحدَّث']], JSON_UNESCAPED_UNICODE)]);

        $this->assertSame('النصّ المُحدَّث', Chat::find($reaction->id)->reaction_context['preview']);
    }

    // ------------------------------------------------ حالات فاسدة

    public function test_a_reaction_without_a_parent_id_does_not_query_or_throw(): void
    {
        $orphan = $this->message('wamid.O', ['type' => 'reaction', 'reaction' => ['emoji' => '❤️']], 'inbound');

        $this->assertNull($orphan->reaction_context);
    }

    public function test_malformed_metadata_does_not_break_serialization(): void
    {
        $chat = $this->message('wamid.BAD', ['type' => 'text']);
        DB::table('chats')->where('id', $chat->id)->update(['metadata' => '{"reaction" تالف']);

        $this->assertNull(Chat::find($chat->id)->reaction_context);
    }

    // -------------------------------- تكافؤ المسارات: جوال وبثّ

    /**
     * حمولتا الجوال والبثّ قائمتان بيضاوان تُبنيان يدوياً، فالمُلحَق لا يصلهما
     * ما لم يُذكر فيهما صراحةً — والداشبورد كان سيعرض الاقتباس والتطبيق لا.
     *
     * @dataProvider whitelistedPayloads
     */
    public function test_reaction_context_reaches_the_mobile_and_broadcast_payloads(string $file): void
    {
        $source = file_get_contents(base_path($file));

        $this->assertStringContainsString(
            "'reaction_context'",
            $source,
            "الحقل مفقود من {$file} — التفاعل سيصل بلا اقتباس"
        );
    }

    public static function whitelistedPayloads(): array
    {
        return [
            'تطبيق الجوال' => ['app/Http/Controllers/ApiController.php'],
            'البثّ اللحظي' => ['app/Services/Chat/ChatBroadcastPayloadBuilder.php'],
        ];
    }

    /** الاقتباس يصل الواجهة عبر البثّ اللحظي كما يصل عبر تحميل الصفحة. */
    public function test_the_broadcast_payload_carries_the_resolved_context(): void
    {
        $this->message('wamid.B', ['type' => 'text', 'text' => ['body' => 'رسالة مبثوثة']]);
        $reaction = $this->reaction('wamid.B');

        $built = (new \App\Services\Chat\ChatBroadcastPayloadBuilder())
            ->buildMinimalValue($reaction, $this->organization->id, false);

        $this->assertArrayHasKey('reaction_context', $built);
        $this->assertSame('رسالة مبثوثة', $built['reaction_context']['preview']);
    }

    /** الترجمات التي يعرضها الاقتباس. */
    public function test_quote_labels_are_translated(): void
    {
        $arabic = json_decode(file_get_contents(base_path('lang/ar.json')), true);

        foreach (['Your message', 'Their message', 'Photo', 'Video', 'File', 'Sticker', 'Location', 'Contact', 'Message'] as $key) {
            $this->assertArrayHasKey($key, $arabic, "ترجمة مفقودة: {$key}");
            $this->assertNotSame('', trim((string) $arabic[$key]));
        }
    }
}
