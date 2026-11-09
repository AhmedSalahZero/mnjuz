<?php

namespace Tests\Feature\MobileApi;

use App\Jobs\ProcessCampaignMessagesJob;
use App\Models\Campaign;
use App\Models\CampaignLog;
use App\Models\CampaignMediaHistory;
use App\Models\Contact;
use App\Models\ContactGroup;
use App\Models\Organization;
use App\Models\Template;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * الحملات من تطبيق الجوال — الإنشاء يمرّ بالخدمة والتحقّق نفسيهما في الويب.
 */
class MobileCampaignTest extends MobileApiTestCase
{
    private Template $template;

    private Template $mediaTemplate;

    protected function planMetadata(): array
    {
        return parent::planMetadata() + ['campaign_limit' => 5];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->template = $this->makeTemplate('welcome_offer', [
            ['type' => 'HEADER', 'format' => 'TEXT', 'text' => 'عرض {{1}}', 'example' => ['header_text' => ['الصيف']]],
            ['type' => 'BODY', 'text' => 'مرحباً {{1}}، خصم {{2}}', 'example' => ['body_text' => [['سارة', '20%']]]],
            ['type' => 'FOOTER', 'text' => 'منجز'],
            ['type' => 'BUTTONS', 'buttons' => [
                ['type' => 'QUICK_REPLY', 'text' => 'إيقاف'],
                ['type' => 'URL', 'text' => 'تسوّق', 'url' => 'https://shop.test/{{1}}', 'example' => ['https://shop.test/summer']],
            ]],
        ]);

        $this->mediaTemplate = $this->makeTemplate('video_promo', [
            ['type' => 'HEADER', 'format' => 'VIDEO', 'example' => ['header_handle' => ['https://scontent.test/example.mp4']]],
            ['type' => 'BODY', 'text' => 'شاهد الجديد'],
        ]);
    }

    private function makeTemplate(string $name, array $components, string $status = 'APPROVED', ?Organization $organization = null): Template
    {
        return Template::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => ($organization ?? $this->organization)->id,
            'meta_id' => (string) random_int(100000, 999999),
            'name' => $name,
            'category' => 'MARKETING',
            'language' => 'ar',
            'status' => $status,
            'metadata' => json_encode(['name' => $name, 'components' => $components]),
            'created_by' => $this->owner->id,
        ])->fresh();
    }

    private function group(string $name = 'VIP', int $contacts = 2): ContactGroup
    {
        $group = ContactGroup::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'name' => $name,
            'created_by' => $this->owner->id,
        ]);

        for ($i = 0; $i < $contacts; $i++) {
            $contact = $this->contact();
            DB::table('contact_contact_group')->insert([
                'contact_id' => $contact->id,
                'contact_group_id' => $group->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $group->fresh();
    }

    private function contact(array $attributes = []): Contact
    {
        return Contact::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'first_name' => 'عميل',
            'phone' => '+9665' . random_int(10000000, 99999999),
            'created_by' => $this->owner->id,
        ], $attributes));
    }

    private function campaign(array $attributes = []): Campaign
    {
        return Campaign::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'name' => 'حملة الصيف',
            'template_id' => $this->template->id,
            'contact_group_id' => 0,
            'metadata' => json_encode(['header' => ['format' => 'TEXT', 'parameters' => []], 'body' => ['parameters' => []], 'buttons' => []]),
            'status' => 'completed',
            'scheduled_at' => '2026-11-01 07:00:00',
            'created_by' => $this->owner->id,
        ], $attributes))->fresh();
    }

    /** نفس ما يرجعه GET /campaigns/templates/{uuid} بعد تعبئته. */
    private function textCampaignPayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'name' => 'حملة الصيف',
            'template' => $this->template->uuid,
            'contacts' => 'all',
            'skip_schedule' => false,
            'time' => '2026-12-01 10:30',
            'header' => ['format' => 'TEXT', 'text' => 'عرض {{1}}', 'parameters' => [['type' => 'text', 'selection' => 'static', 'value' => 'الشتاء']]],
            'body' => ['text' => 'مرحباً {{1}}، خصم {{2}}', 'parameters' => [
                ['type' => 'text', 'selection' => 'dynamic', 'value' => 'first name'],
                ['type' => 'text', 'selection' => 'static', 'value' => '30%'],
            ]],
            'footer' => ['text' => 'منجز'],
            'buttons' => [
                ['type' => 'QUICK_REPLY', 'text' => 'إيقاف', 'value' => null, 'parameters' => [['type' => 'static', 'value' => 'STOP']]],
                ['type' => 'URL', 'text' => 'تسوّق', 'value' => 'https://shop.test/{{1}}', 'parameters' => [['type' => 'static', 'value' => 'https://shop.test/winter']]],
            ],
        ], $overrides);
    }

    // ------------------------------------------------------------ options

    public function test_options_list_approved_templates_groups_and_limits(): void
    {
        $this->makeTemplate('pending_one', [], 'PENDING');
        [, $other] = [null, Organization::factory()->create(['created_by' => $this->owner->id])];
        $this->makeTemplate('foreign', [], 'APPROVED', $other);
        $this->group('VIP', 2);
        $this->contact(['marketing_opted_out_at' => now()]);
        $this->campaign();

        $response = $this->getJson('/api/v1/campaigns/options')->assertOk();

        $this->assertEqualsCanonicalizing(['welcome_offer', 'video_promo'], array_column($response->json('data.templates'), 'name'));
        $this->assertSame('VIDEO', collect($response->json('data.templates'))->firstWhere('name', 'video_promo')['header_format']);
        $response->assertJsonPath('data.contact_groups.0.name', 'VIP')
            ->assertJsonPath('data.contact_groups.0.contacts_count', 2)
            ->assertJsonPath('data.all_contacts_count', 2)
            ->assertJsonPath('data.timezone', 'Asia/Riyadh')
            ->assertJsonPath('data.limits.campaign_limit', 5)
            ->assertJsonPath('data.limits.used', 1)
            ->assertJsonPath('data.limits.remaining', 4)
            ->assertJsonPath('data.subscription_active', true);
    }

    public function test_template_returns_a_ready_parameter_skeleton(): void
    {
        $this->getJson('/api/v1/campaigns/templates/' . $this->template->uuid)
            ->assertOk()
            ->assertJsonPath('data.template.name', 'welcome_offer')
            ->assertJsonPath('data.parameters.header.format', 'TEXT')
            ->assertJsonPath('data.parameters.header.parameters.0', ['type' => 'text', 'selection' => 'static', 'value' => 'الصيف'])
            ->assertJsonPath('data.parameters.body.parameters.1.value', '20%')
            ->assertJsonPath('data.parameters.footer.text', 'منجز')
            ->assertJsonPath('data.parameters.buttons.0.parameters', [['type' => 'static', 'value' => null]])
            ->assertJsonPath('data.parameters.buttons.1.value', 'https://shop.test/{{1}}')
            ->assertJsonPath('data.parameters.buttons.1.parameters.0.value', 'https://shop.test/summer')
            ->assertJsonPath('data.dynamic_values.0', 'first name');

        // ترويسة الوسائط لها معامل واحد دائماً.
        $this->getJson('/api/v1/campaigns/templates/' . $this->mediaTemplate->uuid)
            ->assertOk()
            ->assertJsonPath('data.parameters.header.parameters.0.type', 'VIDEO')
            ->assertJsonPath('data.parameters.header.parameters.0.selection', 'upload')
            ->assertJsonPath('data.parameters.header.parameters.0.example_url', 'https://scontent.test/example.mp4');
    }

    public function test_a_template_of_another_organization_is_not_found(): void
    {
        $other = Organization::factory()->create(['created_by' => $this->owner->id]);
        $foreign = $this->makeTemplate('foreign', [], 'APPROVED', $other);

        $this->getJson('/api/v1/campaigns/templates/' . $foreign->uuid)->assertNotFound();
    }

    // ------------------------------------------------------------ create

    public function test_a_scheduled_campaign_is_created_in_the_organization_timezone(): void
    {
        $this->contact();

        $response = $this->postJson('/api/v1/campaigns', $this->textCampaignPayload())
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'حملة الصيف')
            ->assertJsonPath('data.status', 'scheduled')
            ->assertJsonPath('data.audience.type', 'all')
            ->assertJsonPath('data.template.uuid', $this->template->uuid);

        // 10:30 بتوقيت الرياض = 07:30 UTC، ويعود للتطبيق بتوقيت المنشأة.
        $response->assertJsonPath('data.scheduled_at', '2026-12-01T10:30:00+03:00');

        $campaign = Campaign::where('uuid', $response->json('data.uuid'))->firstOrFail();
        $this->assertSame($this->organization->id, (int) $campaign->organization_id);
        $this->assertSame('2026-12-01 07:30:00', $campaign->getRawOriginal('scheduled_at'));
        $this->assertSame($this->owner->id, (int) $campaign->created_by);

        $metadata = json_decode($campaign->getRawOriginal('metadata'), true);
        $this->assertSame('الشتاء', $metadata['header']['parameters'][0]['value']);
        $this->assertSame('first name', $metadata['body']['parameters'][0]['value']);
        $this->assertSame('STOP', $metadata['buttons'][0]['parameters'][0]['value']);

        $this->assertDatabaseHas('activity_logs', ['event' => 'campaign_created', 'organization_id' => $this->organization->id]);
    }

    public function test_send_now_and_a_string_false_from_multipart(): void
    {
        $this->contact();

        $now = $this->postJson('/api/v1/campaigns', $this->textCampaignPayload(['skip_schedule' => true, 'time' => null]))->assertOk();
        $this->assertLessThan(60, abs(now()->utc()->diffInSeconds(Campaign::where('uuid', $now->json('data.uuid'))->value('scheduled_at'))));

        // multipart يرسل «false» نصّاً — ليس «أرسل الآن».
        $later = $this->postJson('/api/v1/campaigns', $this->textCampaignPayload(['skip_schedule' => 'false']))->assertOk();
        $this->assertSame('2026-12-01 07:30:00', Campaign::where('uuid', $later->json('data.uuid'))->value('scheduled_at'));
    }

    /**
     * التطبيق يرسل المعاملات وحدها بلا نصوص القالب، فكانت رسالة الحملة
     * تُسجَّل في المحادثة بلا نصّ مع أن المستلم رآها كاملة.
     */
    public function test_texts_the_app_leaves_out_are_filled_from_the_template(): void
    {
        $this->contact();

        $payload = $this->textCampaignPayload(['skip_schedule' => true, 'time' => null]);
        unset($payload['header']['text'], $payload['body']['text'], $payload['footer']);

        $response = $this->postJson('/api/v1/campaigns', $payload)->assertOk();

        $metadata = json_decode(Campaign::where('uuid', $response->json('data.uuid'))->value('metadata'), true);
        $this->assertSame('عرض {{1}}', $metadata['header']['text']);
        $this->assertSame('مرحباً {{1}}، خصم {{2}}', $metadata['body']['text']);
        $this->assertSame('منجز', $metadata['footer']['text']);
        $this->assertSame('first name', $metadata['body']['parameters'][0]['value']);

        // نصّ وصل في الطلب لا يُستبدل.
        $kept = $this->postJson('/api/v1/campaigns', $this->textCampaignPayload([
            'skip_schedule' => true,
            'time' => null,
            'body' => ['text' => 'نصّ الطلب'],
        ]))->assertOk();

        $keptMetadata = json_decode(Campaign::where('uuid', $kept->json('data.uuid'))->value('metadata'), true);
        $this->assertSame('نصّ الطلب', $keptMetadata['body']['text']);
    }

    public function test_a_campaign_to_a_group_with_an_uploaded_header_video(): void
    {
        Storage::fake('local');
        $group = $this->group('VIP', 1);

        $response = $this->post('/api/v1/campaigns', [
            'name' => 'فيديو',
            'template' => $this->mediaTemplate->uuid,
            'contacts' => $group->uuid,
            'skip_schedule' => '1',
            'header' => ['format' => 'VIDEO', 'parameters' => [[
                'type' => 'VIDEO',
                'selection' => 'upload',
                'value' => UploadedFile::fake()->create('promo.mp4', 500, 'video/mp4'),
            ]]],
            'body' => ['parameters' => []],
        ], ['Accept' => 'application/json']);
        $this->assertSame(200, $response->status(), $response->getContent());

        $response->assertJsonPath('data.audience.type', 'group')->assertJsonPath('data.audience.group.uuid', $group->uuid);

        $metadata = json_decode(Campaign::where('uuid', $response->json('data.uuid'))->value('metadata'), true);
        $this->assertSame('VIDEO', $metadata['header']['format']);
        $this->assertStringContainsString('/media/public/', $metadata['header']['parameters'][0]['value']);
        $this->assertNotNull($metadata['media']);
        // التطبيق أرسل body بلا text، والمحادثة تعرض النصّ من هنا.
        $this->assertSame('شاهد الجديد', $metadata['body']['text']);

        // الملف صار في «الملفات السابقة» ويُختار منها لحملة تالية.
        $history = $this->getJson('/api/v1/campaigns/media-history?type=VIDEO')->assertOk()->json('data');
        $this->assertCount(1, $history);
        $this->assertSame('promo.mp4', $history[0]['name']);

        $this->postJson('/api/v1/campaigns', [
            'name' => 'فيديو مرة ثانية',
            'template' => $this->mediaTemplate->uuid,
            'contacts' => $group->uuid,
            'skip_schedule' => true,
            'header' => ['format' => 'VIDEO', 'parameters' => [['type' => 'VIDEO', 'selection' => 'history', 'value' => $history[0]['uuid']]]],
        ])->assertOk();
    }

    public function test_validation_errors_use_the_mobile_envelope(): void
    {
        $this->contact();

        $this->postJson('/api/v1/campaigns', ['template' => $this->template->uuid])
            ->assertStatus(400)
            ->assertJsonPath('success', false)
            ->assertJsonPath('statusCode', 400)
            ->assertJsonValidationErrors(['name', 'contacts', 'time'], 'errors');

        // ترويسة الفيديو بلا ملف — رسالة الويب نفسها.
        $this->postJson('/api/v1/campaigns', [
            'name' => 'x', 'template' => $this->mediaTemplate->uuid, 'contacts' => 'all', 'skip_schedule' => true,
            'header' => ['format' => 'VIDEO', 'parameters' => [['type' => 'VIDEO', 'selection' => 'upload', 'value' => null]]],
        ])->assertStatus(400)->assertJsonValidationErrors(['header.parameters.0.value'], 'errors');

        $this->postJson('/api/v1/campaigns', $this->textCampaignPayload(['time' => '01/12/2026 10:30']))
            ->assertStatus(400)->assertJsonValidationErrors(['time'], 'errors');
    }

    public function test_an_empty_audience_is_rejected(): void
    {
        // المنشأة بلا جهات اتصال: التحقّق يسأل الخدمة التي يسألها المُرسِل.
        $this->postJson('/api/v1/campaigns', $this->textCampaignPayload())
            ->assertStatus(400)
            ->assertJsonValidationErrors(['contacts'], 'errors');

        $empty = $this->group('فارغة', 0);
        $this->contact();

        $this->postJson('/api/v1/campaigns', $this->textCampaignPayload(['contacts' => $empty->uuid]))
            ->assertStatus(400)
            ->assertJsonValidationErrors(['contacts'], 'errors');
    }

    public function test_another_organizations_template_or_group_is_rejected(): void
    {
        $this->contact();
        $other = Organization::factory()->create(['created_by' => $this->owner->id]);
        $foreignTemplate = $this->makeTemplate('foreign', [['type' => 'BODY', 'text' => 'x']], 'APPROVED', $other);
        $foreignGroup = ContactGroup::create(['uuid' => (string) Str::uuid(), 'organization_id' => $other->id, 'name' => 'x', 'created_by' => $this->owner->id]);

        $this->postJson('/api/v1/campaigns', $this->textCampaignPayload(['template' => $foreignTemplate->uuid]))
            ->assertStatus(400)->assertJsonValidationErrors(['template'], 'errors');

        $this->postJson('/api/v1/campaigns', $this->textCampaignPayload(['contacts' => $foreignGroup->uuid]))
            ->assertStatus(400)->assertJsonValidationErrors(['contacts'], 'errors');

        $this->assertSame(0, Campaign::count());
    }

    public function test_the_campaign_limit_is_enforced_without_a_session(): void
    {
        $this->contact();
        for ($i = 0; $i < 5; $i++) {
            $this->campaign();
        }

        $this->postJson('/api/v1/campaigns', $this->textCampaignPayload())
            ->assertStatus(400)
            ->assertJsonValidationErrors(['name'], 'errors');

        // دون الحدّ يمرّ — قبل الإصلاح كان الحدّ يُقرأ من الجلسة فيُرفض كل طلب.
        Campaign::where('organization_id', $this->organization->id)->limit(1)->delete();
        $this->postJson('/api/v1/campaigns', $this->textCampaignPayload())->assertOk();
    }

    public function test_an_expired_subscription_cannot_create(): void
    {
        $this->contact();
        DB::table('subscriptions')->where('organization_id', $this->organization->id)->update(['valid_until' => now()->subDay()]);

        $this->postJson('/api/v1/campaigns', $this->textCampaignPayload())->assertStatus(400);
        $this->assertSame(0, Campaign::count());
    }

    public function test_agents_manage_campaigns_like_on_the_web(): void
    {
        $this->contact();
        $this->actAs($this->member('agent'));

        $this->getJson('/api/v1/campaigns')->assertOk();
        $this->postJson('/api/v1/campaigns', $this->textCampaignPayload())->assertOk();
    }

    // ------------------------------------------------------------ list / show / logs

    public function test_the_list_is_scoped_paginated_and_carries_stats(): void
    {
        $campaign = $this->campaign(['name' => 'حملة واحد']);
        $this->campaign(['name' => 'محذوفة', 'deleted_at' => now()]);
        $other = Organization::factory()->create(['created_by' => $this->owner->id]);
        $this->campaign(['name' => 'غريبة', 'organization_id' => $other->id]);

        $contact = $this->contact();
        $chatId = $this->chat($contact, 'read');
        CampaignLog::create(['campaign_id' => $campaign->id, 'contact_id' => $contact->id, 'chat_id' => $chatId, 'status' => 'success']);
        CampaignLog::create(['campaign_id' => $campaign->id, 'contact_id' => $this->contact()->id, 'status' => 'failed']);

        $this->getJson('/api/v1/campaigns?per_page=10')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.name', 'حملة واحد')
            ->assertJsonPath('data.items.0.stats', ['total' => 2, 'sent' => 1, 'delivered' => 1, 'read' => 1, 'failed' => 1])
            ->assertJsonPath('data.items.0.can_resend', true)
            ->assertJsonPath('data.items.0.scheduled_at', '2026-11-01T10:00:00+03:00');

        $this->getJson('/api/v1/campaigns?search=welcome')->assertJsonCount(1, 'data.items');
        $this->getJson('/api/v1/campaigns?search=nothing')->assertJsonCount(0, 'data.items');
        $this->getJson('/api/v1/campaigns?status=scheduled')->assertJsonCount(0, 'data.items');
    }

    public function test_show_and_logs_explain_each_failure(): void
    {
        $campaign = $this->campaign();

        $delivered = $this->contact(['first_name' => 'سارة']);
        CampaignLog::create(['campaign_id' => $campaign->id, 'contact_id' => $delivered->id, 'chat_id' => $this->chat($delivered, 'delivered'), 'status' => 'success']);

        // قُبلت عند Meta ثم فشل التسليم: السبب في سجلّ حالة المحادثة.
        $undelivered = $this->contact(['first_name' => 'خالد']);
        $chatId = $this->chat($undelivered, 'failed');
        DB::table('chat_status_logs')->insert([
            'chat_id' => $chatId,
            'metadata' => json_encode(['status' => 'failed', 'errors' => [['code' => 131049, 'title' => 'Ecosystem', 'message' => 'Not delivered']]]),
            'created_at' => now(),
        ]);
        CampaignLog::create(['campaign_id' => $campaign->id, 'contact_id' => $undelivered->id, 'chat_id' => $chatId, 'status' => 'success']);

        // رُفض الطلب قبل الإرسال: السبب في سجلّ الحملة.
        $rejected = $this->contact(['first_name' => 'منى']);
        CampaignLog::create([
            'campaign_id' => $campaign->id,
            'contact_id' => $rejected->id,
            'status' => 'failed',
            'metadata' => json_encode(['data' => ['error' => ['code' => 132001, 'message' => 'Template name does not exist']]]),
        ]);

        // قبلتها Meta ثم فشل التسليم فعُلِّم السجلّ فاشلاً: سجلّ الحملة فيه
        // «accepted» وحدها، والسبب في سجلّ حالة المحادثة (شكلٌ حقيقي من الإنتاج).
        $markedFailed = $this->contact(['first_name' => 'هدى']);
        $markedChat = $this->chat($markedFailed, 'failed');
        DB::table('chat_status_logs')->insert([
            'chat_id' => $markedChat,
            'metadata' => json_encode(['status' => 'failed', 'errors' => [['code' => 131049, 'title' => 'Not delivered']]]),
            'created_at' => now(),
        ]);
        CampaignLog::create([
            'campaign_id' => $campaign->id,
            'contact_id' => $markedFailed->id,
            'chat_id' => $markedChat,
            'status' => 'failed',
            'metadata' => json_encode(['data' => ['messages' => [['id' => 'wamid.X', 'message_status' => 'accepted']]]]),
        ]);

        $this->getJson('/api/v1/campaigns/' . $campaign->uuid)
            ->assertOk()
            ->assertJsonPath('data.stats.failed', 3)
            // إعادة الإرسال لسجلّات الحملة الفاشلة (المرفوض، والمُعلَّم فاشلاً بكود
            // قابل للإعادة) — كالويب. ما زال «success» وفشلت محادثته لا يُعاد من هنا.
            ->assertJsonPath('data.resendable_count', 2)
            ->assertJsonPath('data.metadata.header.format', 'TEXT');

        $logs = $this->getJson('/api/v1/campaigns/' . $campaign->uuid . '/logs')->assertOk()->json('data.items');
        $this->assertSame(['delivered', 'failed', 'failed', 'failed'], array_column($logs, 'status'));
        $this->assertNull($logs[0]['error']);
        $this->assertSame(131049, $logs[1]['error']['code']);
        $this->assertStringContainsString('marketing message', $logs[1]['error']['reason']);
        $this->assertSame(132001, $logs[2]['error']['code']);
        $this->assertSame('Template name does not exist', $logs[2]['error']['reason'], 'unknown codes show WhatsApp\'s own text');
        $this->assertSame('سارة', $logs[0]['contact']['name']);
        $this->assertSame(131049, $logs[3]['error']['code'] ?? null, 'the reason of a log marked failed after delivery comes from the chat');

        $this->getJson('/api/v1/campaigns/' . $campaign->uuid . '/logs?status=failed')->assertJsonCount(3, 'data.items');
        $this->getJson('/api/v1/campaigns/' . $campaign->uuid . '/logs?status=delivered')->assertJsonCount(1, 'data.items');
        $this->getJson('/api/v1/campaigns/' . $campaign->uuid . '/logs?search=منى')->assertJsonCount(1, 'data.items');
    }

    public function test_another_organizations_campaign_is_not_found(): void
    {
        $other = Organization::factory()->create(['created_by' => $this->owner->id]);
        $foreign = $this->campaign(['organization_id' => $other->id]);

        $this->getJson('/api/v1/campaigns/' . $foreign->uuid)->assertNotFound();
        $this->getJson('/api/v1/campaigns/' . $foreign->uuid . '/logs')->assertNotFound();
        $this->deleteJson('/api/v1/campaigns/' . $foreign->uuid)->assertNotFound();
        $this->postJson('/api/v1/campaigns/' . $foreign->uuid . '/resend-failed')->assertNotFound();
        $this->assertNull($foreign->fresh()->getRawOriginal('deleted_at'));
    }

    // ------------------------------------------------------------ resend / delete

    public function test_resend_failed_skips_permanent_failures(): void
    {
        Queue::fake();
        $campaign = $this->campaign();

        $retryable = CampaignLog::create(['campaign_id' => $campaign->id, 'contact_id' => $this->contact()->id, 'status' => 'failed']);

        // 131026: الرقم ليس على واتساب — الإعادة لا تنجح أبداً.
        $permanentContact = $this->contact();
        $permanentChat = $this->chat($permanentContact, 'failed');
        DB::table('chat_status_logs')->insert([
            'chat_id' => $permanentChat,
            'metadata' => json_encode(['status' => 'failed', 'errors' => [['code' => 131026]]]),
            'created_at' => now(),
        ]);
        $permanent = CampaignLog::create(['campaign_id' => $campaign->id, 'contact_id' => $permanentContact->id, 'chat_id' => $permanentChat, 'status' => 'failed']);

        $this->postJson('/api/v1/campaigns/' . $campaign->uuid . '/resend-failed')
            ->assertOk()
            ->assertJsonPath('data.queued', 1)
            ->assertJsonPath('data.status', 'ongoing');

        $this->assertSame('pending', $retryable->fresh()->status);
        $this->assertSame('failed', $permanent->fresh()->status);
        Queue::assertPushedOn('campaign-messages', ProcessCampaignMessagesJob::class);

        // لا فاشل بقي — 422 برسالة واضحة.
        $retryable->update(['status' => 'success']);
        $this->postJson('/api/v1/campaigns/' . $campaign->uuid . '/resend-failed')->assertStatus(422);

        $scheduled = $this->campaign(['status' => 'scheduled']);
        $this->postJson('/api/v1/campaigns/' . $scheduled->uuid . '/resend-failed')->assertStatus(422);
    }

    public function test_delete_soft_deletes_and_logs(): void
    {
        $campaign = $this->campaign();

        $this->deleteJson('/api/v1/campaigns/' . $campaign->uuid)->assertOk();

        $this->assertNotNull($campaign->fresh()->getRawOriginal('deleted_at'));
        $this->assertSame($this->owner->id, (int) $campaign->fresh()->deleted_by);
        $this->getJson('/api/v1/campaigns/' . $campaign->uuid)->assertNotFound();
        $this->assertDatabaseHas('activity_logs', ['event' => 'campaign_deleted']);
    }

    public function test_media_history_is_scoped_and_validated(): void
    {
        $mine = CampaignMediaHistory::create([
            'uuid' => (string) Str::uuid(), 'organization_id' => $this->organization->id, 'created_by' => $this->owner->id,
            'media_type' => 'IMAGE', 'name' => 'a.png', 'path' => 'https://x.test/a.png', 'location' => 'local',
        ]);
        $other = Organization::factory()->create(['created_by' => $this->owner->id]);
        $foreign = CampaignMediaHistory::create([
            'uuid' => (string) Str::uuid(), 'organization_id' => $other->id, 'created_by' => $this->owner->id,
            'media_type' => 'IMAGE', 'name' => 'b.png', 'path' => 'https://x.test/b.png', 'location' => 'local',
        ]);

        $this->getJson('/api/v1/campaigns/media-history?type=image')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'a.png');
        $this->getJson('/api/v1/campaigns/media-history?type=GIF')->assertStatus(400);

        $this->deleteJson('/api/v1/campaigns/media-history/' . $foreign->uuid)->assertNotFound();
        $this->deleteJson('/api/v1/campaigns/media-history/' . $mine->uuid)->assertOk();
        $this->getJson('/api/v1/campaigns/media-history?type=IMAGE')->assertJsonCount(0, 'data');
    }

    private function chat(Contact $contact, string $status): int
    {
        return DB::table('chats')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'contact_id' => $contact->id,
            'type' => 'outbound',
            'metadata' => json_encode(['type' => 'text', 'text' => ['body' => 'x']]),
            'status' => $status,
            'is_read' => 1,
            'created_at' => '2026-11-01 07:05:00',
        ]);
    }
}
