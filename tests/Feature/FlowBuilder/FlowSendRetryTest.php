<?php

namespace Tests\Feature\FlowBuilder;

use App\Models\Chat;
use Illuminate\Support\Facades\Log;

/**
 * إعادة إرسال رسالة الـ flow حين تردّ Meta بعطل مؤقت.
 *
 * رُصد في الإنتاج: flow ترحيب بعميل جديد، عقدة قائمة سليمة تُرسَل ١٣٠ مرّة
 * يومياً، فشلت لعميل بعينه بـ 131000 «Something went wrong». كانت المحاولة
 * الأولى الأخيرة: العميل لم يصله الترحيب، ولم يعلم أحد.
 */
class FlowSendRetryTest extends FlowBuilderTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['flow_builder.send_retry_delays' => [0, 0]]);

        $this->makeFlow([
            $this->startNode('keywords', 'start'),
            $this->listNode('2', 'يرجى تحديد الخدمة المناسبة لك', [['قص شعر', 'صبغة'], ['الحمام المغربي']]),
        ], [['1', '2']]);
    }

    private function outboundChats(): int
    {
        return Chat::where('contact_id', $this->contact->id)->where('type', 'outbound')->count();
    }

    public function test_a_transient_error_is_retried_and_the_flow_continues(): void
    {
        $this->fakeGraphSequence([131000, 'ok']);

        $this->receive('start');

        $this->assertCount(2, $this->sentMessages(), 'one failed attempt, one successful retry');
        $this->assertSame(1, $this->outboundChats(), 'the customer gets the message once');
        $this->assertSame('2', (string) $this->flowSession()->current_step, 'the flow waits on the list');
        $this->assertSame($this->sentMessages()[0], $this->sentMessages()[1], 'the retry sends the same payload');
    }

    public function test_every_transient_code_is_retried(): void
    {
        foreach ([1, 2, 131016, 130429] as $code) {
            \Modules\FlowBuilder\Models\FlowUserData::where('contact_id', $this->contact->id)->delete();
            $this->fakeGraphSequence([$code, 'ok']);

            $this->receive('start');

            $this->assertCount(2, $this->sentMessages(), "code {$code} is retried");
        }
    }

    public function test_a_permanent_error_is_not_retried(): void
    {
        // 131026: الرقم ليس على واتساب — الإعادة لا تُصلحه.
        $this->fakeGraphSequence([131026]);

        $this->receive('start');

        $this->assertCount(1, $this->sentMessages());
        $this->assertSame(0, $this->outboundChats());
    }

    public function test_it_gives_up_after_three_attempts_and_logs_them(): void
    {
        Log::spy();
        $this->fakeGraphSequence([131000, 131000, 131000, 'ok']);

        $this->receive('start');

        $this->assertCount(3, $this->sentMessages(), 'two retries, then stop');
        $this->assertSame(0, $this->outboundChats());
        $this->assertNotSame('2', (string) optional($this->flowSession())->current_step, 'the flow did not move past the unsent message');

        Log::shouldHaveReceived('warning')->with('Flow message send retry', \Mockery::on(fn ($context) => $context['error_code'] === 131000))->twice();
        Log::shouldHaveReceived('error')->with('Flow message failed to send', \Mockery::on(fn ($context) => $context['attempts'] === 3 && $context['error_code'] === 131000))->once();
    }

    public function test_a_network_error_is_not_retried(): void
    {
        // انقطاع الشبكة بلا كود: قد تكون الرسالة وصلت وضاع الردّ — الإعادة تُكرّرها.
        $this->fakeGraphSequence(['connect']);

        $this->receive('start');

        $this->assertCount(1, $this->sentMessages());
    }

    public function test_a_text_node_without_body_is_rejected_before_sending(): void
    {
        \Modules\FlowBuilder\Models\Flow::query()->delete();
        $this->makeFlow([$this->startNode('keywords', 'hello'), $this->textNode('2', '   ')], [['1', '2']]);
        Log::spy();
        $this->fakeGraphSequence([]);

        $this->receive('hello');

        $this->assertCount(0, $this->sentMessages());
        Log::shouldHaveReceived('error')->with('Flow text node is missing body text', \Mockery::any())->once();
        Log::shouldNotHaveReceived('error', ['Flow message failed to send', \Mockery::any()]);
    }
}
