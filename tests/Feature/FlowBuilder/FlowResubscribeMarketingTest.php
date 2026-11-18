<?php

namespace Tests\Feature\FlowBuilder;

use App\Models\ContactGroup;
use App\Services\CampaignAudienceService;

/**
 * مسار «ايقاف الاعلانات» كما بناه التاجر، من أوّل رسالة إلى آخر ضغطة.
 *
 * «تأكيد إلغاء الإشتراك» ← إزالة من جميع المجموعات (انسحاب)، ثم زرّ «إعادة
 * الإشتراك». كان الزرّ يضيفه إلى مجموعة فقط فيبقى منسحباً: تعرضه المجموعة
 * وترفضه الحملة بـ«المجموعة بلا جهات اتصال». إجراء «إعادة الاشتراك في
 * العروض» قبل الإضافة يُعيده إلى الحملات.
 */
class FlowResubscribeMarketingTest extends FlowBuilderTestCase
{
    private ContactGroup $group;

    protected function setUp(): void
    {
        parent::setUp();

        $this->group = ContactGroup::create([
            'organization_id' => $this->organization->id,
            'name' => 'إعادة الاشتراك في تلقي العروض',
            'created_by' => $this->owner->id,
        ]);
    }

    public function test_pressing_resubscribe_brings_the_contact_back_to_campaigns(): void
    {
        $this->stopOffersFlow(withResubscribeAction: true);

        $this->receive('إيقاف تلقي العروض');
        $this->receiveButton('تأكيد إلغاء الإشتراك');
        $this->assertNotNull($this->contact->fresh()->marketing_opted_out_at, 'confirming opts the contact out');

        $this->receiveButton('إعادة الإشتراك');

        $this->assertNull($this->contact->fresh()->marketing_opted_out_at);
        $this->assertTrue($this->contact->fresh()->contactGroups()->where('contact_groups.id', $this->group->id)->exists());
        $this->assertFalse(CampaignAudienceService::isEmpty($this->organization->id, $this->group->id));
        $this->assertContains('سعيدين باهتمامك بعروضنا', $this->sentBodies());
    }

    /** المسار كما هو اليوم على الإنتاج: الإضافة إلى المجموعة وحدها لا تُعيده. */
    public function test_adding_to_a_group_alone_keeps_the_opt_out(): void
    {
        $this->stopOffersFlow(withResubscribeAction: false);

        $this->receive('إيقاف تلقي العروض');
        $this->receiveButton('تأكيد إلغاء الإشتراك');
        $this->receiveButton('إعادة الإشتراك');

        $this->assertNotNull($this->contact->fresh()->marketing_opted_out_at);
        $this->assertTrue($this->contact->fresh()->contactGroups()->where('contact_groups.id', $this->group->id)->exists());
        $this->assertTrue(CampaignAudienceService::isEmpty($this->organization->id, $this->group->id));
    }

    /** عقدة معطّلة لا تمسح شيئاً، ويكمل المسار بعدها. */
    public function test_an_inactive_resubscribe_step_is_skipped(): void
    {
        $this->stopOffersFlow(withResubscribeAction: true, resubscribeActive: false);

        $this->receive('إيقاف تلقي العروض');
        $this->receiveButton('تأكيد إلغاء الإشتراك');
        $this->receiveButton('إعادة الإشتراك');

        $this->assertNotNull($this->contact->fresh()->marketing_opted_out_at);
        $this->assertContains('سعيدين باهتمامك بعروضنا', $this->sentBodies());
    }

    private function stopOffersFlow(bool $withResubscribeAction, bool $resubscribeActive = true): void
    {
        $nodes = [
            $this->startNode('keywords', 'إيقاف تلقي العروض'),
            $this->buttonsNode('2', 'هل فعلاً لاتريد استقبال العروض؟', ['تأكيد إلغاء الإشتراك', 'أبغى أستقبل عروضك']),
            $this->actionNode('4', 'remove_from_group'),
            $this->buttonsNode('5', 'تم إلغاء الإشتراك في رسائل العروض', ['إعادة الإشتراك']),
            $this->actionNode('7', 'add_to_group', ['group_id' => $this->group->id]),
            $this->textNode('8', 'سعيدين باهتمامك بعروضنا'),
            $this->textNode('9', 'يسعدنا بقاؤك معنا'),
        ];
        $links = [['1', '2'], ['2', '4', 'a'], ['2', '9', 'b'], ['4', '5'], ['7', '8']];

        if ($withResubscribeAction) {
            $nodes[] = $this->actionNode('6', 'resubscribe_marketing', [], $resubscribeActive);
            $links[] = ['5', '6', 'a'];
            $links[] = ['6', '7'];
        } else {
            $links[] = ['5', '7', 'a'];
        }

        $this->makeFlow($nodes, $links);
    }
}
