<?php

namespace Tests\Feature\Automation;

use App\Models\AutoReply;
use App\Services\Messaging\AutomationChannels;
use Modules\FlowBuilder\Models\Flow;

/**
 * اللقطات نفسها، والردود والـ flows تحمل ما سيضعه الترحيل في الإنتاج.
 *
 * الأصل (WhatsappAutomationGoldenTest) ينشئ صفوفاً بلا channels، أي null =
 * «كل القنوات». والإنتاج بعد ترحيل 2026_11_10 يحمل ["whatsapp","webchat"] في
 * كل ردّ وflow قائم. هنا يُعبّأ العمود بالقيمة نفسها قبل الإنشاء، فيُثبت أن
 * مخرجات واتساب تبقى حرفاً بحرف بعد الترحيل — لا استنتاجاً من مسار null.
 */
class WhatsappAutomationGoldenAfterMigrationTest extends WhatsappAutomationGoldenTest
{
    protected function setUp(): void
    {
        parent::setUp();

        $fill = static function ($model): void {
            if ($model->channels === null) {
                $model->channels = AutomationChannels::LEGACY;
            }
        };
        AutoReply::creating($fill);
        Flow::creating($fill);
    }

    public function test_the_rows_carry_the_backfilled_channels(): void
    {
        $this->basicReply('مرحبا', 'أهلاً');
        $this->makeFlow([$this->startNode('keywords', 'start'), $this->textNode('2', 'أهلاً')], [['1', '2']]);

        $this->assertSame(AutomationChannels::LEGACY, AutoReply::sole()->channels);
        $this->assertSame(AutomationChannels::LEGACY, Flow::sole()->channels);
    }
}
