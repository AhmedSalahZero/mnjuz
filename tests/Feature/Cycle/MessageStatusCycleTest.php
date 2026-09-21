<?php

namespace Tests\Feature\Cycle;

use App\Models\Chat;
use App\Models\ChatStatusLog;

/**
 * دورة بلاغات الحالة: sent ⇐ delivered ⇐ read.
 *
 * الـ webhooks لا تصل مرتّبة والوظائف تُعالَج بالتوازي، فبلاغ delivered
 * متأخّر قد يُكتب فوق read ويُنقص عدّاد «تمت القراءة» في الحملات. ولم يكن
 * على هذه الطبقة اختبار واحد رغم أنها تُغذّي كل تقارير الحملات.
 */
class MessageStatusCycleTest extends CycleTestCase
{
    private function report(string $wamId, string $status): void
    {
        $this->postJson($this->webhookUrl(), $this->payload('messages', [
            'statuses' => [[
                'id' => $wamId,
                'status' => $status,
                'timestamp' => (string) now()->timestamp,
                'recipient_id' => '966502486051',
            ]],
        ]))->assertOk();
    }

    // ------------------------------------------------- السلّم

    public function test_a_status_report_updates_the_message(): void
    {
        $chat = $this->chat($this->contact(), ['status' => 'sent']);

        $this->report($chat->wam_id, 'delivered');

        $this->assertSame('delivered', $chat->fresh()->status);
    }

    public function test_the_full_ladder_runs_forward(): void
    {
        $chat = $this->chat($this->contact(), ['status' => 'accepted']);

        foreach (['sent', 'delivered', 'read'] as $status) {
            $this->report($chat->wam_id, $status);
            $this->assertSame($status, $chat->fresh()->status);
        }
    }

    /**
     * العطل الذي يحرسه السلّم: بلاغ متأخّر لا يتراجع بالحالة.
     *
     * لو كُتب delivered فوق read لنقص عدّاد «تمت القراءة» في تقرير الحملة.
     */
    public function test_a_late_report_does_not_move_the_status_backwards(): void
    {
        $chat = $this->chat($this->contact(), ['status' => 'read']);

        $this->report($chat->wam_id, 'delivered');
        $this->assertSame('read', $chat->fresh()->status);

        $this->report($chat->wam_id, 'sent');
        $this->assertSame('read', $chat->fresh()->status);
    }

    /** وبلاغ فشل متأخّر لا يُلغي وصولاً مؤكَّداً. */
    public function test_a_late_failure_does_not_overwrite_a_delivered_message(): void
    {
        foreach (['delivered', 'read', 'played'] as $i => $delivered) {
            $chat = $this->chat($this->contact(['phone' => '+96650248605' . $i]), ['status' => $delivered]);

            $this->report($chat->wam_id, 'failed');

            $this->assertSame($delivered, $chat->fresh()->status, $delivered . ' كُتب فوقها فشل');
        }
    }

    /** أمّا رسالة لم تصل بعد فيُسجَّل فشلها. */
    public function test_a_failure_is_recorded_for_a_message_that_never_arrived(): void
    {
        $chat = $this->chat($this->contact(), ['status' => 'sent']);

        $this->report($chat->wam_id, 'failed');

        $this->assertSame('failed', $chat->fresh()->status);
    }

    /** وتكرار البلاغ نفسه لا يضرّ. */
    public function test_repeating_the_same_report_is_harmless(): void
    {
        $chat = $this->chat($this->contact(), ['status' => 'sent']);

        $this->report($chat->wam_id, 'delivered');
        $this->report($chat->wam_id, 'delivered');

        $this->assertSame('delivered', $chat->fresh()->status);
    }

    // ------------------------------------------------- عدّة بلاغات

    public function test_several_reports_in_one_payload_all_apply(): void
    {
        $first = $this->chat($this->contact(['phone' => '+966502486051']), ['status' => 'sent']);
        $second = $this->chat($this->contact(['phone' => '+966502486052']), ['status' => 'sent']);

        $this->postJson($this->webhookUrl(), $this->payload('messages', [
            'statuses' => [
                ['id' => $first->wam_id, 'status' => 'delivered'],
                ['id' => $second->wam_id, 'status' => 'read'],
            ],
        ]))->assertOk();

        $this->assertSame('delivered', $first->fresh()->status);
        $this->assertSame('read', $second->fresh()->status);
    }

    // ------------------------------------------------- ما لا يُطابق

    /** بلاغ لرسالة لا نعرفها يمرّ بلا خطأ — قد تكون أُرسلت من خارج النظام. */
    public function test_a_report_for_an_unknown_message_is_ignored(): void
    {
        $chat = $this->chat($this->contact(), ['status' => 'sent']);

        $this->report('wamid.لا-نعرفه', 'read');

        $this->assertSame('sent', $chat->fresh()->status);
    }

    /** ولا يمسّ بلاغُ منشأةٍ رسالةَ منشأةٍ أخرى ولو تطابق المعرّف. */
    public function test_a_report_never_crosses_organizations(): void
    {
        $chat = $this->chat($this->contact(), ['status' => 'sent']);

        $other = $this->organization->replicate();
        $other->identifier = (string) \Illuminate\Support\Str::uuid();
        $other->save();

        $this->postJson('/webhook/whatsapp/' . $other->identifier, $this->payload('messages', [
            'statuses' => [['id' => $chat->wam_id, 'status' => 'read']],
        ]))->assertOk();

        $this->assertSame('sent', $chat->fresh()->status, 'بلاغ منشأة أخرى غيّر الحالة');
    }

    // ------------------------------------------------- السجلّ

    /** كل بلاغ يُسجَّل، فيبقى أثر الرحلة كاملاً. */
    public function test_each_report_is_logged(): void
    {
        $chat = $this->chat($this->contact(), ['status' => 'sent']);

        $this->report($chat->wam_id, 'delivered');
        $this->report($chat->wam_id, 'read');

        $this->assertSame(2, ChatStatusLog::where('chat_id', $chat->id)->count());
    }

    /**
     * وحتى البلاغ الذي لم يُطبَّق يُسجَّل — السجلّ مرجع تدقيق لا نسخة للحالة.
     *
     * فمن أراد أن يعرف لماذا بقيت الرسالة على «read» يجد بلاغ delivered
     * المتأخّر مكتوباً.
     */
    public function test_even_a_report_that_changed_nothing_is_logged(): void
    {
        $chat = $this->chat($this->contact(), ['status' => 'read']);

        $this->report($chat->wam_id, 'delivered');

        $this->assertSame('read', $chat->fresh()->status);
        $this->assertSame(1, ChatStatusLog::where('chat_id', $chat->id)->count());
    }

    // ------------------------------------------------- الحمولة الناقصة

    /**
     * بلاغ ناقص كان يرمي 500.
     *
     * وMeta تُعيد إرسال الحمولة كلّها على غير 200، فيتكرّر الخطأ بلا نهاية
     * وتُعاد معه البلاغات السليمة في الحمولة نفسها.
     */
    public function test_a_report_without_a_status_does_not_break_the_request(): void
    {
        $chat = $this->chat($this->contact(), ['status' => 'sent']);

        $this->postJson($this->webhookUrl(), $this->payload('messages', [
            'statuses' => [['id' => $chat->wam_id]],
        ]))->assertOk();

        $this->assertSame('sent', $chat->fresh()->status);
    }

    public function test_a_report_without_an_id_does_not_break_the_request(): void
    {
        $this->postJson($this->webhookUrl(), $this->payload('messages', [
            'statuses' => [['status' => 'delivered']],
        ]))->assertOk();
    }

    /** والبلاغ السليم بجوار الناقص يُطبَّق — لا يُهدره جاره. */
    public function test_a_valid_report_still_applies_next_to_a_broken_one(): void
    {
        $chat = $this->chat($this->contact(), ['status' => 'sent']);

        $this->postJson($this->webhookUrl(), $this->payload('messages', [
            'statuses' => [
                ['id' => 'wamid.بلا-حالة'],
                ['id' => $chat->wam_id, 'status' => 'read'],
            ],
        ]))->assertOk();

        $this->assertSame('read', $chat->fresh()->status);
    }

    /** واردٌ وبلاغٌ في حمولة واحدة: كلاهما يعمل. */
    public function test_an_inbound_message_and_a_status_report_together(): void
    {
        $chat = $this->chat($this->contact(), ['status' => 'sent']);

        $this->postJson($this->webhookUrl(), $this->payload('messages', [
            'contacts' => [['profile' => ['name' => 'أحمد'], 'wa_id' => '966502486051']],
            'messages' => [$this->textMessage()],
            'statuses' => [['id' => $chat->wam_id, 'status' => 'read']],
        ]))->assertOk();

        $this->assertSame('read', $chat->fresh()->status);
        $this->assertSame(1, Chat::where('type', 'inbound')->count());
    }
}
