<?php

namespace Tests\Feature;

use App\Http\Controllers\ApiController;
use App\Models\Chat;
use App\Models\ChatLog;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

/**
 * التفاعل بالإيموجي لا يصل تطبيق الجوال.
 *
 * الداشبورد يُرشّحه في الواجهة، والتطبيق لا نملك تعديله — فالترشيح له يقع في
 * الخادم. بلا ذلك يصل صفّاً بلا فرع عرض فيظهر فقاعةً فارغة، وتظهر معه معاينة
 * فارغة في قائمة المحادثات لأن التطبيق يشتقّ «آخر رسالة» من نفس المصفوفة.
 *
 * ثلاثة مسارات تبني الرسائل (v1 و v2 و getChatMessages)، وكلّها تمرّ بخريطة
 * واحدة — فالاختبارات هنا تحرس المصدر المشترك وتكافؤ المسارات.
 */
class MobileReactionFilterTest extends TestCase
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

    private function message(array $metadata, int $minutesAgo = 10, string $direction = 'outbound'): Chat
    {
        $chat = Chat::create([
            'organization_id' => $this->organization->id,
            'contact_id' => $this->contact->id,
            'wam_id' => 'wamid.' . Str::random(10),
            'type' => $direction,
            'status' => 'delivered',
            'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE),
            'created_at' => now()->subMinutes($minutesAgo),
        ]);

        ChatLog::create([
            'contact_id' => $this->contact->id,
            'entity_type' => 'chat',
            'entity_id' => $chat->id,
            'created_at' => $chat->getRawOriginal('created_at'),
        ]);

        return $chat;
    }

    private function reaction(string $emoji = '❤️', int $minutesAgo = 1): Chat
    {
        return $this->message(
            ['type' => 'reaction', 'reaction' => ['message_id' => 'wamid.PARENT', 'emoji' => $emoji]],
            $minutesAgo,
            'inbound'
        );
    }

    /** الخريطة التي تُبنى منها رسائل كل مسارات الجوال. */
    private function visibleChats(array $ids): \Illuminate\Support\Collection
    {
        $method = new ReflectionMethod(ApiController::class, 'visibleChatsQuery');
        $method->setAccessible(true);

        return $method->invoke(app(ApiController::class))->whereIn('id', $ids)->get();
    }

    // ----------------------------------------------------- الترشيح

    public function test_reactions_are_excluded_from_what_the_app_receives(): void
    {
        $text = $this->message(['type' => 'text', 'text' => ['body' => 'نورتينا']], 10);
        $reaction = $this->reaction('❤️', 1);

        $visible = $this->visibleChats([$text->id, $reaction->id])->pluck('id')->all();

        $this->assertContains($text->id, $visible);
        $this->assertNotContains($reaction->id, $visible, 'التفاعل يجب ألّا يصل التطبيق');
    }

    /** إزالة تفاعل (إيموجي فارغ) رسالةُ تفاعلٍ أيضاً. */
    public function test_removing_a_reaction_is_excluded_too(): void
    {
        $reaction = $this->reaction('', 1);

        $this->assertCount(0, $this->visibleChats([$reaction->id]));
    }

    /**
     * @dataProvider messageTypesThatMustSurvive
     */
    public function test_other_types_still_reach_the_app(array $metadata): void
    {
        $chat = $this->message($metadata, 5);

        $this->assertCount(1, $this->visibleChats([$chat->id]), 'نوع مشروع فُقد في الترشيح');
    }

    public static function messageTypesThatMustSurvive(): array
    {
        return [
            'نصّ' => [['type' => 'text', 'text' => ['body' => 'مرحباً']]],
            'صورة' => [['type' => 'image', 'image' => ['caption' => 'الفاتورة']]],
            'فيديو' => [['type' => 'video', 'video' => []]],
            'صوت' => [['type' => 'audio', 'audio' => []]],
            'ملف' => [['type' => 'document', 'document' => []]],
            'ملصق' => [['type' => 'sticker', 'sticker' => []]],
            'موقع' => [['type' => 'location', 'location' => ['latitude' => 21.4, 'longitude' => 39.1]]],
            'جهة اتصال' => [['type' => 'contacts', 'contacts' => []]],
            'زرّ' => [['type' => 'button', 'button' => ['text' => 'نعم']]],
            'تفاعلي' => [['type' => 'interactive', 'interactive' => ['button_reply' => ['title' => 'أوافق']]]],
            'غير مدعوم' => [['type' => 'unsupported', 'errors' => []]],
            'تغيّر رقم' => [['type' => 'system', 'system' => ['body' => 'changed']]],
            'تعديل' => [['type' => 'edit', 'edit' => ['message' => ['type' => 'text', 'text' => ['body' => 'x']]]]],
            'حذف' => [['type' => 'revoke', 'revoke' => ['original_message_id' => 'wamid.X']]],
        ];
    }

    /**
     * نصّ يذكر الكلمة «reaction» ليس تفاعلاً. المطابقة نصّية على الشكل
     * الكامل "type":"reaction" لا على الكلمة وحدها.
     */
    public function test_a_message_merely_mentioning_the_word_is_not_filtered(): void
    {
        $chat = $this->message(['type' => 'text', 'text' => ['body' => 'ما معنى reaction بالعربية؟']], 5);

        $this->assertCount(1, $this->visibleChats([$chat->id]));
    }

    // ------------------------------------ أثره على «آخر رسالة»

    /**
     * التطبيق يشتقّ معاينة قائمة المحادثات من نفس المصفوفة، فترشيح التفاعل
     * يُصلح السطر الفارغ هناك أيضاً — بلا تغيير في التطبيق نفسه.
     */
    public function test_the_apps_last_message_becomes_the_real_one(): void
    {
        $this->message(['type' => 'text', 'text' => ['body' => 'الأقدم']], 30);
        $expected = $this->message(['type' => 'text', 'text' => ['body' => 'نورتينا']], 10);
        $this->reaction('❤️', 1);

        $ids = Chat::where('contact_id', $this->contact->id)->pluck('id')->all();
        $last = $this->visibleChats($ids)->sortBy(fn ($c) => $c->getRawOriginal('created_at'))->last();

        $this->assertSame($expected->id, $last->id);
        $this->assertSame('نورتينا', json_decode($last->metadata, true)['text']['body']);
    }

    // -------------------------------- تكافؤ المسارات الثلاثة

    /**
     * ثلاثة مواضع تبني رسائل الجوال. أيّ منها يبني خريطته بنفسه بدل المصدر
     * المشترك يُعيد التفاعل إلى التطبيق من ذلك المسار وحده — وهو انحدار
     * يصعب ملاحظته لأن المسارين الآخرين يظلّان سليمين.
     */
    public function test_every_mobile_path_builds_its_map_from_the_shared_source(): void
    {
        $source = $this->activeSource();

        $this->assertSame(
            3,
            substr_count($source, 'visibleChatsQuery'),
            'التعريف ومَوضعا الاستخدام — أيّ نقص يعني مساراً يتجاوز الترشيح'
        );

        $this->assertDoesNotMatchRegularExpression(
            "/Chat::query\(\)->with\('media', 'user', 'logs'\)/",
            $source,
            'بناء خريطة الرسائل مباشرةً يتجاوز الترشيح'
        );

        $this->assertDoesNotMatchRegularExpression(
            "/Chat::with\('media', 'user', 'logs'\)->whereIn\('id', \\\$chatIds\)/",
            $source,
            'بناء خريطة الرسائل مباشرةً يتجاوز الترشيح'
        );
    }

    /**
     * الكود الحيّ دون المعلَّق: المتحكّم يحوي نسخاً قديمة معطّلة داخل تعليقات،
     * وعدّها كوداً حيّاً يُنتج إنذاراً كاذباً.
     */
    private function activeSource(): string
    {
        $lines = file(base_path('app/Http/Controllers/ApiController.php'));
        $active = array_filter($lines, fn ($line) => !preg_match('/^\s*(\/\/|\*|\/\*)/', $line));

        return implode('', $active);
    }

    /**
     * الحلقة الثالثة كانت تمرّر null إلى minimalChatValue حين لا تجد الرسالة
     * في الخريطة — والترشيح جعل ذلك يقع فعلاً لكل تفاعل.
     */
    public function test_the_third_loop_skips_messages_missing_from_the_map(): void
    {
        $source = file_get_contents(base_path('app/Http/Controllers/ApiController.php'));


        $this->assertMatchesRegularExpression(
            '/\\$value = \\$chatsMap->get\\(\\$chatLog->entity_id\\);\s*\n\s*\n\s*\/\/[^\n]*\n\s*\/\/[^\n]*\n\s*if \(!\\$value\) \{/',
            $source,
            'getChatMessages يجب أن تتخطّى ما ليس في الخريطة'
        );
    }
}
