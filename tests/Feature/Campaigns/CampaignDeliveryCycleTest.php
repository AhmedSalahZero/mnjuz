<?php

namespace Tests\Feature\Campaigns;

use App\Models\Addon;
use App\Models\Campaign;
use App\Models\CampaignLog;
use App\Models\Chat;
use App\Models\ChatStatusLog;
use App\Models\Organization;
use Illuminate\Support\Carbon;

/**
 * حملة واتساب من إنشائها إلى وصولها: ما يكتبه التاجر في النموذج هو ما يصل
 * Meta لكل عميل باسمه، وكل رسالة تُحفظ في محادثة عميلها، وبلاغات الوصول
 * والقراءة تُحدّث المحادثة وعدّادات الحملة، والحملة تُغلق حين تنتهي.
 */
class CampaignDeliveryCycleTest extends CampaignTestCase
{
    public function test_a_web_campaign_reaches_every_customer_and_reports_delivery(): void
    {
        $sara = $this->customer('+966500000011', 'سارة');
        $omar = $this->customer('+966500000012', 'عمر');
        $lina = $this->customer('+966500000013', 'لينا');
        $group = $this->group([$sara, $omar, $lina]);
        $template = $this->template();

        $this->createFromWeb($this->webForm($template, (string) $group->uuid))->assertSessionHasNoErrors();
        $campaign = Campaign::where('organization_id', $this->organization->id)->sole();
        $this->assertSame('scheduled', $campaign->status);
        $this->assertSame([], $this->templatesSent(), 'nothing leaves before the scheduler runs');

        $this->schedulerMinute();

        // ← ما وصل Meta: عميلٌ لكلٍّ رسالة، باسمه، والصورة والزرّان كما في النموذج.
        $sent = $this->templatesSent();
        $this->assertCount(3, $sent);
        $byPhone = collect($sent)->keyBy('to');
        foreach (['+966500000011' => 'سارة', '+966500000012' => 'عمر', '+966500000013' => 'لينا'] as $phone => $name) {
            $this->assertSame([
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $phone,
                'type' => 'template',
                'template' => [
                    'name' => 'eid_offer',
                    'language' => ['code' => 'ar'],
                    'components' => [
                        ['type' => 'header', 'parameters' => [['type' => 'image', 'image' => ['link' => 'https://cdn.example.com/eid.jpg']]]],
                        ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => $name], ['type' => 'text', 'text' => 'خصم 20%']]],
                        ['type' => 'button', 'sub_type' => 'url', 'index' => 0, 'parameters' => [['type' => 'text', 'text' => 'https://shop.example.com/eid']]],
                        ['type' => 'button', 'sub_type' => 'quick_reply', 'index' => 1, 'parameters' => [['type' => 'payload', 'payload' => 'stop']]],
                    ],
                ],
            ], $byPhone[$phone], $phone);
        }

        // ← كل رسالة في محادثة عميلها، بمعرّف Meta، والسجلّ ناجح يشير إليها.
        $logs = CampaignLog::where('campaign_id', $campaign->id)->get()->keyBy('contact_id');
        foreach ([$sara, $omar, $lina] as $contact) {
            $log = $logs[$contact->id];
            $this->assertSame('success', $log->status, $contact->first_name);
            $chat = Chat::findOrFail($log->chat_id);
            $this->assertSame($contact->id, (int) $chat->contact_id);
            $this->assertSame('outbound', $chat->type);
            $this->assertStringStartsWith('wamid.', $chat->wam_id);
            $this->assertStringContainsString($contact->first_name, json_encode(json_decode($chat->metadata, true), JSON_UNESCAPED_UNICODE));
        }
        $this->assertSame('completed', $campaign->fresh()->status, 'nothing left to send');

        // ← بلاغات الحالة من webhook واتساب الحقيقي.
        $wam = fn ($contact) => Chat::findOrFail($logs[$contact->id]->chat_id)->wam_id;
        foreach (['sent', 'delivered', 'read'] as $status) {
            $this->whatsappStatus($wam($sara), $status);
        }
        foreach (['sent', 'delivered'] as $status) {
            $this->whatsappStatus($wam($omar), $status);
        }
        $this->whatsappStatus($wam($lina), 'failed', ['errors' => [['code' => 131026, 'title' => 'Message undeliverable']]]);

        $this->assertSame('read', Chat::where('wam_id', $wam($sara))->value('status'));
        $this->assertSame('delivered', Chat::where('wam_id', $wam($omar))->value('status'));
        $this->assertSame('failed', Chat::where('wam_id', $wam($lina))->value('status'));
        $this->assertSame(3, ChatStatusLog::where('chat_id', $logs[$sara->id]->chat_id)->count());

        $counts = $campaign->fresh()->getCounts();
        $this->assertSame(3, (int) $counts->total_message_count);
        $this->assertSame(2, (int) $counts->total_sent_count);
        $this->assertSame(2, (int) $counts->total_delivered_count);
        $this->assertSame(1, (int) $counts->total_read_count);
        $this->assertSame(1, (int) $counts->total_failed_count);

        // الرقم ليس على واتساب (131026): فشلٌ نهائيّ لا يُعاد ولا يُعيد فتح الحملة.
        $this->assertSame('success', $logs[$lina->id]->fresh()->status);
        $this->assertSame('completed', $campaign->fresh()->status);
    }

    /** التطبيق يُنشئ الحملة نفسها بالمعاملات نفسها: الطلب إلى Meta واحدٌ حرفاً بحرف. */
    public function test_the_mobile_app_sends_exactly_what_the_web_sends(): void
    {
        $sara = $this->customer('+966500000011', 'سارة');
        $group = $this->group([$sara]);
        $template = $this->template();
        $form = $this->webForm($template, (string) $group->uuid);

        $this->createFromWeb($form)->assertSessionHasNoErrors();
        $this->schedulerMinute();
        $fromWeb = $this->templatesSent();
        $this->fakeGraph();

        Addon::factory()->create(['name' => 'Mobile App', 'status' => 1, 'is_active' => 1]);
        $this->owner->forceFill(['current_mobile_organization_id' => $this->organization->id])->save();
        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/v1/campaigns', array_merge($form, ['name' => 'من التطبيق']))
            ->assertOk()
            ->assertJsonPath('success', true);
        $this->schedulerMinute();

        $this->assertCount(1, $fromWeb);
        $this->assertSame($fromWeb, $this->templatesSent());
        $this->assertSame(2, CampaignLog::where('status', 'success')->count());
    }

    // ───────────────────── الموعد ─────────────────────

    public function test_a_scheduled_campaign_waits_for_its_time_in_the_organization_timezone(): void
    {
        $metadata = json_decode(Organization::find($this->organization->id)->metadata, true);
        $metadata['timezone'] = 'Asia/Riyadh';
        Organization::where('id', $this->organization->id)->update(['metadata' => json_encode($metadata)]);
        Carbon::setTestNow(Carbon::parse('2026-12-01 06:00:00', 'UTC')); // 09:00 في الرياض

        $group = $this->group([$this->customer('+966500000011', 'سارة')]);
        $this->createFromWeb($this->webForm($this->template(), (string) $group->uuid, [
            'skip_schedule' => false,
            'time' => '2026-12-01 10:30', // بتوقيت الرياض = 07:30 UTC
        ]))->assertSessionHasNoErrors();
        $campaign = Campaign::sole();
        $this->assertSame('2026-12-01 07:30:00', $campaign->getRawOriginal('scheduled_at'));

        $this->schedulerMinute();
        $this->assertSame([], $this->templatesSent(), 'not yet');
        $this->assertSame('scheduled', $campaign->fresh()->status);

        Carbon::setTestNow(Carbon::parse('2026-12-01 07:30:00', 'UTC'));
        $this->schedulerMinute();

        $this->assertCount(1, $this->templatesSent());
        $this->assertSame('completed', $campaign->fresh()->status);
        Carbon::setTestNow();
    }

    // ───────────────────── الجمهور ─────────────────────

    /** لا يصل من انسحب، ولا المحذوف، ولا عميل قناة أخرى في المجموعة نفسها. */
    public function test_only_reachable_whatsapp_customers_are_messaged(): void
    {
        $sara = $this->customer('+966500000011', 'سارة');
        $optedOut = $this->customer('+966500000012', 'منسحب', ['marketing_opted_out_at' => now()]);
        $deleted = $this->customer('+966500000013', 'محذوف');
        $telegram = $this->customer('+966500000014', 'تيليجرام', ['channel' => 'telegram', 'channel_identifier' => '555']);
        $group = $this->group([$sara, $optedOut, $deleted, $telegram]);
        $deleted->delete();

        $this->createFromWeb($this->webForm($this->template(), (string) $group->uuid))->assertSessionHasNoErrors();
        $this->schedulerMinute();

        $this->assertSame(['+966500000011'], array_column($this->templatesSent(), 'to'));
        $this->assertSame(1, CampaignLog::count());
    }

    /** «كل العملاء» يصل عملاء المنشأة وحدها. */
    public function test_all_customers_means_this_organization_only(): void
    {
        $this->customer('+966500000011', 'سارة');
        $other = $this->makeOrganization($this->owner);
        $this->customer('+966500000099', 'غريب', [], $other);

        $this->createFromWeb($this->webForm($this->template(), 'all'))->assertSessionHasNoErrors();
        $this->schedulerMinute();

        $phones = array_column($this->templatesSent(), 'to');
        sort($phones);
        // جهة الاتصال الافتراضية في الأساس (Maitha) من المنشأة نفسها.
        $this->assertSame(['+966500000001', '+966500000011'], $phones);
    }
}
