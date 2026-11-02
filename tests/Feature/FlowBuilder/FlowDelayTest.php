<?php

namespace Tests\Feature\FlowBuilder;

use Illuminate\Support\Facades\Queue;
use Modules\FlowBuilder\Jobs\ProcessDelayedFlowJob;
use Modules\FlowBuilder\Models\Flow;

/**
 * التأخير يُكمل من حيث توقّف العميل، ولا شيء غيره.
 *
 * كان العميل يقف على ما قبل التأخير، فيأخذ التأخير أوّل حافّة خارجة منه —
 * وبعد أزرار هي غالباً فرعُ زرٍّ آخر. والمهمة المؤجّلة لا تتحقّق من شيء:
 * تُعيد من غادر إلى فرعٍ آخر، وتُرسل من مسارٍ أُوقف نشره.
 */
class FlowDelayTest extends FlowBuilderTestCase
{
    private ?ProcessDelayedFlowJob $job = null;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    /**
     * start → أزرار [نعم، لا]. حافّة «لا» أُنشئت أوّلاً (هي ما كان يُختار خطأً).
     * نعم → تأخير → «تذكير بعد الانتظار» / لا → «حسناً».
     */
    private function delayedBranchFlow(): Flow
    {
        return $this->makeFlow([
            $this->startNode('keywords', 'start'),
            $this->buttonsNode('2', 'هل نذكّرك لاحقاً؟', ['نعم', 'لا']),
            $this->actionNode('3', 'delay', ['duration' => 30]),
            $this->textNode('4', 'تذكير بعد الانتظار'),
            $this->textNode('5', 'حسناً'),
        ], [['1', '2'], ['2', '5', 'b'], ['2', '3', 'a'], ['3', '4']]);
    }

    private function pendingJob(): ProcessDelayedFlowJob
    {
        Queue::assertPushed(ProcessDelayedFlowJob::class, function (ProcessDelayedFlowJob $job) {
            $this->job = $job;

            return true;
        });

        return $this->job;
    }

    public function test_a_delay_in_a_later_button_branch_resumes_on_its_own_branch(): void
    {
        $this->delayedBranchFlow();

        $this->receive('start');
        $this->receiveButton('نعم');

        $this->assertSame('3', (string) $this->flowSession()->current_step, 'العميل يقف على عقدة التأخير نفسها.');

        $this->pendingJob()->handle();

        $this->assertSame(['هل نذكّرك لاحقاً؟', 'تذكير بعد الانتظار'], $this->sentBodies());
    }

    public function test_the_delayed_step_is_skipped_when_the_contact_started_another_flow(): void
    {
        $this->delayedBranchFlow();
        $this->makeFlow([$this->startNode('keywords', 'menu'), $this->textNode('2', 'القائمة')], [['1', '2']]);

        $this->receive('start');
        $this->receiveButton('نعم');
        $job = $this->pendingJob();

        $this->receive('menu');
        $job->handle();

        $this->assertSame(['هل نذكّرك لاحقاً؟', 'القائمة'], $this->sentBodies());
    }

    public function test_the_delayed_step_is_skipped_when_the_flow_was_unpublished(): void
    {
        $flow = $this->delayedBranchFlow();

        $this->receive('start');
        $this->receiveButton('نعم');
        $flow->update(['status' => 'inactive']);

        $this->pendingJob()->handle();

        $this->assertSame(['هل نذكّرك لاحقاً؟'], $this->sentBodies());
    }

    /** رسالة أثناء الانتظار تذهب لبقية الردود، والتأخير يُكمل في موعده. */
    public function test_a_message_during_the_wait_goes_to_the_other_replies(): void
    {
        $this->delayedBranchFlow();
        $this->basicReply('السعر', 'الأسعار في الكتالوج');

        $this->receive('start');
        $this->receiveButton('نعم');
        $job = $this->pendingJob();

        $this->receive('كم السعر؟');

        $this->assertSame(['هل نذكّرك لاحقاً؟', 'الأسعار في الكتالوج'], $this->sentBodies());
        $this->assertSame('3', (string) $this->flowSession()->current_step);
        Queue::assertPushed(ProcessDelayedFlowJob::class, 1);

        $job->handle();
        $this->assertContains('تذكير بعد الانتظار', $this->sentBodies());
    }

    /** إعادة المسار بالكلمة جلسةٌ جديدة: قفل مهمة الجلسة القديمة لا يُسقط مهمتها. */
    public function test_the_unique_id_belongs_to_the_session(): void
    {
        $this->delayedBranchFlow();

        $this->receive('start');
        $this->receiveButton('نعم');
        $first = $this->pendingJob()->uniqueId();

        $this->receive('start');
        $this->receiveButton('نعم');

        $ids = [];
        Queue::assertPushed(ProcessDelayedFlowJob::class, function (ProcessDelayedFlowJob $job) use (&$ids) {
            $ids[] = $job->uniqueId();

            return true;
        });

        $this->assertCount(2, array_unique($ids));
        $this->assertContains($first, $ids);
    }

    /** تأخيرٌ أوّل المسار: الـ flow تولّى الرسالة، فلا تُجيبها الردود الآلية أيضاً. */
    public function test_a_flow_that_starts_with_a_delay_still_counts_as_the_reply(): void
    {
        $this->organization->update(['metadata' => json_encode(array_merge(
            json_decode($this->organization->metadata, true),
            ['automation' => ['response_sequence' => ['Automated Flows', 'Basic Replies']]]
        ))]);
        $this->basicReply('start', 'ردّ آلي');
        $this->makeFlow([
            $this->startNode('keywords', 'start'),
            $this->actionNode('2', 'delay', ['duration' => 5]),
            $this->textNode('3', 'بعد التأخير'),
        ], [['1', '2'], ['2', '3']]);

        $this->receive('start');

        $this->assertSame([], $this->sentBodies());
        Queue::assertPushed(ProcessDelayedFlowJob::class, 1);
    }
}
