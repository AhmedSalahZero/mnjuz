<?php

namespace Tests\Feature\Campaigns;

use App\Events\NewChatEvent;
use App\Jobs\CreateCampaignLogsJob;
use App\Jobs\ProcessCampaignMessagesJob;
use App\Models\Campaign;
use App\Models\CampaignLog;
use App\Models\Chat;
use App\Models\Contact;
use App\Models\ContactGroup;
use App\Models\Organization;
use App\Models\Setting;
use App\Models\Template;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Feature\FlowBuilder\FlowBuilderTestCase;

/**
 * أساس اختبارات الحملات: منشأة بواتساب متّصل، وقالب معتمد بصورة ومتغيّرات
 * وأزرار، ومجموعات عملاء، وMeta وهمية (fakeGraph) تسجّل كل طلب.
 *
 * الحملة تمرّ بمسارها الحقيقي كلّه: الحفظ من الويب أو التطبيق ← مُجدول
 * السجلّات ← مُوزّع الدفعات ← مهمّة الإرسال ← الطلب إلى Meta ← رسالة المحادثة
 * ← بلاغات الحالة عبر webhook واتساب الحقيقي.
 */
abstract class CampaignTestCase extends FlowBuilderTestCase
{
    protected string $identifier;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Event::fake([NewChatEvent::class]);

        $this->identifier = (string) Str::uuid();
        $this->organization->forceFill(['identifier' => $this->identifier])->save();
        Setting::create(['key' => 'whatsapp_callback_token', 'value' => 'verify-token']);
    }

    // ───────────────────── البيانات ─────────────────────

    /** قالب معتمد: صورة في الترويسة، متغيّران في المتن، زرّ رابط بمتغيّر وزرّ ردّ سريع. */
    protected function template(string $name = 'eid_offer'): Template
    {
        return Template::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'meta_id' => (string) random_int(100000, 999999),
            'name' => $name,
            'language' => 'ar',
            'category' => 'MARKETING',
            'status' => 'APPROVED',
            'metadata' => json_encode([
                'name' => $name,
                'language' => 'ar',
                'category' => 'MARKETING',
                'components' => [
                    ['type' => 'HEADER', 'format' => 'IMAGE', 'example' => ['header_handle' => ['https://scontent.example/x.jpg']]],
                    ['type' => 'BODY', 'text' => 'أهلاً {{1}}، {{2}}', 'example' => ['body_text' => [['سارة', 'خصم 20%']]]],
                    ['type' => 'FOOTER', 'text' => 'متجر منجز'],
                    ['type' => 'BUTTONS', 'buttons' => [
                        ['type' => 'URL', 'text' => 'تسوّق الآن', 'url' => 'https://shop.example.com/{{1}}', 'example' => ['https://shop.example.com/eid']],
                        ['type' => 'QUICK_REPLY', 'text' => 'إيقاف'],
                    ]],
                ],
            ]),
            'created_by' => $this->owner->id,
        ]);
    }

    protected function customer(string $phone, string $firstName, array $attributes = [], ?Organization $organization = null): Contact
    {
        return Contact::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'organization_id' => ($organization ?? $this->organization)->id,
            'first_name' => $firstName,
            'phone' => $phone,
            'created_by' => $this->owner->id,
        ], $attributes));
    }

    /** @param array<int, Contact> $contacts */
    protected function group(array $contacts, string $name = 'عملاء العيد', ?Organization $organization = null): ContactGroup
    {
        $group = ContactGroup::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => ($organization ?? $this->organization)->id,
            'name' => $name,
            'created_by' => $this->owner->id,
        ]);

        foreach ($contacts as $contact) {
            DB::table('contact_contact_group')->insert([
                'contact_id' => $contact->id,
                'contact_group_id' => $group->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $group;
    }

    /** ما يرسله نموذج إنشاء الحملة في الويب. */
    protected function webForm(Template $template, string $contacts, array $overrides = []): array
    {
        return array_replace_recursive([
            'name' => 'عرض العيد',
            'template' => (string) $template->uuid,
            'contacts' => $contacts,
            'skip_schedule' => true,
            'header' => ['format' => 'IMAGE', 'parameters' => [[
                'type' => 'IMAGE', 'selection' => 'default', 'value' => 'https://cdn.example.com/eid.jpg',
            ]]],
            'body' => ['text' => 'أهلاً {{1}}، {{2}}', 'parameters' => [
                ['type' => 'text', 'selection' => 'dynamic', 'value' => 'first name'],
                ['type' => 'text', 'selection' => 'static', 'value' => 'خصم 20%'],
            ]],
            'footer' => ['text' => 'متجر منجز'],
            'buttons' => [
                ['type' => 'URL', 'text' => 'تسوّق الآن', 'value' => 'https://shop.example.com/{{1}}', 'parameters' => [
                    ['type' => 'static', 'selection' => 'static', 'value' => 'https://shop.example.com/eid'],
                ]],
                ['type' => 'QUICK_REPLY', 'text' => 'إيقاف', 'value' => null, 'parameters' => [
                    ['type' => 'static', 'selection' => 'static', 'value' => 'stop'],
                ]],
            ],
        ], $overrides);
    }

    protected function createFromWeb(array $form)
    {
        return $this->actingAs($this->owner)
            ->withSession(['current_organization' => $this->organization->id])
            ->post('/campaigns', $form, ['Accept' => 'application/json']);
    }

    /** حملة جارية مع سجلّاتها مباشرةً — لاختبار المهامّ وحدها. */
    protected function ongoingCampaign(Template $template, array $contacts, array $attributes = []): Campaign
    {
        $campaign = Campaign::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'name' => 'عرض',
            'status' => 'ongoing',
            'template_id' => $template->id,
            'contact_group_id' => 0,
            'metadata' => json_encode([
                'header' => ['format' => null, 'parameters' => []],
                'body' => ['text' => 'أهلاً {{1}}، {{2}}', 'parameters' => [
                    ['type' => 'text', 'selection' => 'dynamic', 'value' => 'first name'],
                    ['type' => 'text', 'selection' => 'static', 'value' => 'خصم'],
                ]],
                'footer' => ['text' => null],
                'buttons' => [],
                'media' => null,
            ]),
            'created_by' => $this->owner->id,
            'scheduled_at' => now(),
        ], $attributes));

        foreach ($contacts as $contact) {
            CampaignLog::create(['campaign_id' => $campaign->id, 'contact_id' => $contact->id, 'status' => 'pending']);
        }

        return $campaign;
    }

    // ───────────────────── المُجدول والمهامّ ─────────────────────

    /** دقيقة المُجدول: CreateCampaignLogsJob، والطابور متزامن فتمضي السلسلة كلّها. */
    protected function schedulerMinute(): void
    {
        $this->asConsole(fn () => (new CreateCampaignLogsJob())->handle());
    }

    /** دورة المُوزّع (كل 5 ثوانٍ في الإنتاج). */
    protected function dispatcherTick(): void
    {
        $this->asConsole(fn () => (new ProcessCampaignMessagesJob())->handle());
    }

    /**
     * المُجدول يعمل من الـ console بلا جلسة. والجلسة تغيّر قراءة التواريخ:
     * Campaign::scheduled_at يُحوَّل إلى توقيت منشأة الجلسة، فيبدو موعد
     * الحملة متأخراً بفارق التوقيت. الاختبار يعمل كما يعمل الإنتاج.
     */
    private function asConsole(callable $run): void
    {
        $organization = session('current_organization');
        session()->forget('current_organization');

        try {
            $run();
        } finally {
            session(['current_organization' => $organization]);
        }
    }

    protected function setRetrySettings(bool $enabled, array $intervals = [1], ?string $failedGroupUuid = null): void
    {
        $metadata = json_decode(Organization::find($this->organization->id)->metadata, true) ?: [];
        $metadata['campaigns'] = [
            'enable_resend' => $enabled,
            'resend_intervals' => $intervals,
            'move_failed_contacts_to_group' => $failedGroupUuid !== null,
            'failed_campaign_group' => $failedGroupUuid,
        ];
        Organization::where('id', $this->organization->id)->update(['metadata' => json_encode($metadata)]);
    }

    // ───────────────────── Meta ─────────────────────

    /** @return array<int, array> رسائل القوالب كما وصلت Meta، بالترتيب. */
    protected function templatesSent(): array
    {
        return array_values(array_filter($this->sentMessages(), fn (array $message) => ($message['type'] ?? null) === 'template'));
    }

    protected function whatsappStatus(string $wamId, string $status, array $extra = []): void
    {
        $this->postJson('/webhook/whatsapp/' . $this->identifier, [
            'object' => 'whatsapp_business_account',
            'entry' => [['id' => '123456', 'changes' => [['field' => 'messages', 'value' => [
                'messaging_product' => 'whatsapp',
                'statuses' => [array_merge([
                    'id' => $wamId,
                    'status' => $status,
                    'timestamp' => (string) now()->timestamp,
                    'recipient_id' => '966500000000',
                ], $extra)],
            ]]]]],
        ])->assertOk();
    }

    protected function chatOf(CampaignLog $log): Chat
    {
        return Chat::findOrFail($log->fresh()->chat_id);
    }
}
