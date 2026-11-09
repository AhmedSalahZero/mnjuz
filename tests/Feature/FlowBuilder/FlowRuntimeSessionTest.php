<?php

namespace Tests\Feature\FlowBuilder;

use App\Models\Addon;
use App\Models\Chat;
use Illuminate\Support\Facades\Http;
use Modules\FlowBuilder\Models\Flow;
use Modules\FlowBuilder\Services\FlowService;

/**
 * متى يتولّى الـ flow رسالة العميل، ومتى يتركها لبقية الردود.
 *
 * الجلسة القائمة كانت تجعل AutoReplyService يسأل الـ flow وحده: فالرسالة بعد
 * نهاية الـ flow، والنصّ الحرّ أمام الأزرار، يضيعان بلا ردّ — والمساعد الذكي
 * والردود الآلية مغلقة عن العميل ما دامت جلسته (بلا مهلة: إلى الأبد).
 */
class FlowRuntimeSessionTest extends FlowBuilderTestCase
{
    /** start → أزرار [نعم، لا] → نعم: «تم» / لا: «أُلغي». */
    private function yesNoFlow(string $footer = ''): Flow
    {
        return $this->makeFlow([
            $this->startNode('keywords', 'start'),
            $this->buttonsNode('2', 'هل تؤكد الطلب؟', ['نعم', 'لا'], $footer),
            $this->textNode('3', 'تم التأكيد'),
            $this->textNode('4', 'أُلغي الطلب'),
        ], [['1', '2'], ['2', '3', 'a'], ['2', '4', 'b']]);
    }

    // ------------------------------------------------- الرسالة لا تضيع

    public function test_the_message_after_a_flow_ends_reaches_the_basic_replies(): void
    {
        $this->makeFlow([$this->startNode('keywords', 'start'), $this->textNode('2', 'أهلاً بك')], [['1', '2']]);
        $this->basicReply('الشحن', 'الشحن خلال يومين');

        $this->receive('start');
        $this->assertSame(['أهلاً بك'], $this->sentBodies());

        $this->receive('كم مدة الشحن؟');

        $this->assertSame(['أهلاً بك', 'الشحن خلال يومين'], $this->sentBodies());
        $this->assertNull($this->flowSession());
    }

    public function test_an_unmatched_reply_repeats_the_question_once_then_hands_over(): void
    {
        $this->yesNoFlow();
        $this->basicReply('السعر', 'الأسعار في الكتالوج');

        $this->receive('start');
        $this->receive('كم السعر؟');

        $this->assertSame(['هل تؤكد الطلب؟', 'هل تؤكد الطلب؟'], $this->sentBodies());
        $this->assertSame(1, (int) $this->flowSession()->unmatched_replies);

        $this->receive('كم السعر؟');

        $this->assertSame(['هل تؤكد الطلب؟', 'هل تؤكد الطلب؟', 'الأسعار في الكتالوج'], $this->sentBodies());
        $this->assertNull($this->flowSession());
    }

    public function test_a_choice_after_the_repeat_still_works(): void
    {
        $this->yesNoFlow();

        $this->receive('start');
        $this->receive('ممم');
        $this->receiveButton('نعم');

        $this->assertSame(['هل تؤكد الطلب؟', 'هل تؤكد الطلب؟', 'تم التأكيد'], $this->sentBodies());
    }

    /** الإعادة مرّة لكل سؤال: التقدّم يصفّر العدّاد. */
    public function test_the_repeat_counter_resets_when_the_flow_moves_on(): void
    {
        $this->makeFlow([
            $this->startNode('keywords', 'start'),
            $this->buttonsNode('2', 'السؤال الأول', ['أ', 'ب']),
            $this->buttonsNode('3', 'السؤال الثاني', ['ج', 'د']),
            $this->textNode('4', 'انتهى'),
        ], [['1', '2'], ['2', '3', 'a'], ['3', '4', 'a']]);

        $this->receive('start');
        $this->receive('؟');
        $this->receiveButton('أ');
        $this->receive('؟');

        $this->assertSame(['السؤال الأول', 'السؤال الأول', 'السؤال الثاني', 'السؤال الثاني'], $this->sentBodies());
    }

    /** خيارٌ واحد موصول لا يصير جواباً لكل ردّ. */
    public function test_a_single_connected_button_is_not_a_wildcard(): void
    {
        $this->makeFlow([
            $this->startNode('keywords', 'start'),
            $this->buttonsNode('2', 'ماذا تريد؟', ['القائمة', 'إلغاء']),
            $this->textNode('3', 'القائمة الرئيسية'),
        ], [['1', '2'], ['2', '3', 'a']]);

        $this->receive('start');
        $this->receiveButton('إلغاء');

        $this->assertNotContains('القائمة الرئيسية', $this->sentBodies());

        $this->receiveButton('القائمة');
        $this->assertContains('القائمة الرئيسية', $this->sentBodies());
    }

    /** إجراءٌ في آخر فرعٍ كان يُنفَّذ مرّتين بالرجوع إلى الأزرار نفسها. */
    public function test_an_action_at_the_end_of_a_branch_runs_once(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $this->makeFlow([
            $this->startNode('keywords', 'start'),
            $this->buttonsNode('2', 'تأكيد؟', ['تأكيد', 'إلغاء']),
            $this->actionNode('3', 'webhook', ['url' => 'https://93.184.216.34/hook', 'method' => 'POST']),
            $this->textNode('4', 'أُلغي'),
        ], [['1', '2'], ['2', '3', 'a'], ['2', '4', 'b']]);

        $this->receive('start');
        $this->receiveButton('تأكيد');

        Http::assertSentCount(1);
        $this->assertNull($this->flowSession());
    }

    /** إجراءٌ نُفّذ يكفي ردّاً: لا يُجيب المساعد الذكي أو الردود الآلية فوقه. */
    public function test_an_executed_action_counts_as_handling_the_message(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $this->basicReply('تأكيد', 'ردّ آلي');

        $this->makeFlow([
            $this->startNode('keywords', 'start'),
            $this->buttonsNode('2', 'تأكيد؟', ['تأكيد', 'إلغاء']),
            $this->actionNode('3', 'webhook', ['url' => 'https://93.184.216.34/hook', 'method' => 'POST']),
        ], [['1', '2'], ['2', '3', 'a']]);

        $this->receive('start');
        $this->receiveButton('تأكيد');

        $this->assertNotContains('ردّ آلي', $this->sentBodies());
    }

    // ------------------------------------------------- رسائل تقبلها Meta

    public function test_a_buttons_message_without_footer_omits_the_footer(): void
    {
        $this->yesNoFlow('');

        $this->receive('start');

        $interactive = $this->sentMessages()[0]['interactive'];
        $this->assertArrayNotHasKey('footer', $interactive);
        $this->assertSame('هل تؤكد الطلب؟', $interactive['body']['text']);
    }

    public function test_a_footer_is_still_sent_when_present(): void
    {
        $this->yesNoFlow('متجرنا');

        $this->receive('start');

        $this->assertSame(['text' => 'متجرنا'], $this->sentMessages()[0]['interactive']['footer']);
    }

    // ------------------------------------------------- الكلمات المفتاحية

    /** رقمٌ جديد يكتب «menu» من إعلان: لا flow ترحيب، فالكلمة تعمل من أوّل رسالة. */
    public function test_a_new_contacts_first_message_can_trigger_a_keyword_flow(): void
    {
        $this->makeFlow([$this->startNode('keywords', 'menu'), $this->textNode('2', 'القائمة')], [['1', '2']]);

        $this->receive('menu', true);

        $this->assertSame(['القائمة'], $this->sentBodies());
    }

    public function test_a_new_contact_flow_still_wins_for_new_contacts(): void
    {
        $this->makeFlow([$this->startNode('keywords', 'menu'), $this->textNode('2', 'القائمة')], [['1', '2']]);
        $this->makeFlow([$this->startNode('new_contact', null), $this->textNode('2', 'مرحباً بالعميل الجديد')], [['1', '2']]);

        $this->receive('menu', true);

        $this->assertSame(['مرحباً بالعميل الجديد'], $this->sentBodies());
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function keywordSpellings(): array
    {
        return [
            'space after comma' => ['hi, hello', 'hello'],
            'arabic comma' => ['مرحبا، عرض', 'عرض'],
            'one per line' => ["hi\nhello", 'hello'],
            'capitals and punctuation' => ['hi,hello', 'Hello!'],
            'inside a sentence' => ['عرض', 'أريد عرض الأسعار'],
        ];
    }

    /** @dataProvider keywordSpellings */
    public function test_keywords_match_however_they_were_typed(string $keywords, string $message): void
    {
        $this->makeFlow([$this->startNode('keywords', $keywords), $this->textNode('2', 'تفضّل')], [['1', '2']]);

        $this->receive($message);

        $this->assertSame(['تفضّل'], $this->sentBodies());
    }

    public function test_a_keyword_is_not_matched_inside_another_word(): void
    {
        $this->makeFlow([$this->startNode('keywords', 'hi'), $this->textNode('2', 'تفضّل')], [['1', '2']]);

        $this->receive('history');

        $this->assertSame([], $this->sentBodies());
    }

    public function test_saving_stores_keywords_normalized(): void
    {
        $flow = $this->makeFlow([$this->startNode('keywords', 'x')], []);
        $metadata = json_decode($flow->metadata, true);
        $metadata['nodes'][0]['data']['metadata']['fields']['keywords'] = "Hi, hello\nمرحبا، عرض";

        app(FlowService::class)->updateFlow($flow->uuid, ['metadata' => json_encode($metadata)], null);

        $this->assertSame('hi,hello,مرحبا,عرض', $flow->fresh()->keywords);
    }

    /** كلمة flow آخر أمام الأزرار تبدأه، لا يُعاد السؤال. */
    public function test_a_keyword_wins_over_repeating_the_question(): void
    {
        $this->yesNoFlow();
        $this->makeFlow([$this->startNode('keywords', 'menu'), $this->textNode('2', 'القائمة')], [['1', '2']]);

        $this->receive('start');
        $this->receive('menu');

        $this->assertSame(['هل تؤكد الطلب؟', 'القائمة'], $this->sentBodies());
    }

    // ------------------------------------------------- خارج الـ flow

    /** انتهت التجربة: الجلسة القديمة لا تُسكت العميل. */
    public function test_a_disabled_module_does_not_silence_the_contact(): void
    {
        $this->yesNoFlow();
        $this->basicReply('السعر', 'الأسعار في الكتالوج');
        $this->receive('start');

        Addon::where('name', 'Flow builder')->update(['status' => 0, 'is_active' => 0]);
        $this->receive('كم السعر؟');

        $this->assertContains('الأسعار في الكتالوج', $this->sentBodies());
        $this->assertNull($this->flowSession());
    }

    public function test_an_agent_reply_ends_the_contacts_flow_session(): void
    {
        $this->yesNoFlow();
        $this->receive('start');
        $this->assertNotNull($this->flowSession(), 'رسائل الـ flow نفسها (user_id = 0) لا تُنهي الجلسة.');

        Chat::create([
            'organization_id' => $this->organization->id,
            'contact_id' => $this->contact->id,
            'user_id' => $this->owner->id,
            'type' => 'outbound',
            'metadata' => json_encode(['type' => 'text', 'text' => ['body' => 'أهلاً، معك أحمد']]),
            'status' => 'sent',
        ]);

        $this->assertNull($this->flowSession());

        // جواب العميل للموظف لا يُقرأ ردّاً على أزرار الـ flow.
        $this->receive('نعم');
        $this->assertNotContains('تم التأكيد', $this->sentBodies());
    }
}
