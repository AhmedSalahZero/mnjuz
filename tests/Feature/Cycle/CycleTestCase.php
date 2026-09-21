<?php

namespace Tests\Feature\Cycle;

use App\Models\Addon;
use App\Models\Chat;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Team;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * أرضية دورة الإرسال والاستقبال.
 *
 * الدورة تمرّ بطبقات كثيرة — webhook، ثم وظيفة، ثم خدمة واتساب — ولكلٍّ
 * شروطها: منشأة لها identifier، وربط واتساب في metadata، واشتراك فعّال،
 * وحدّ رسائل. فبناؤها هنا يمنع أن يمرّ اختبار أو يسقط لسبب غير الذي نختبره.
 */
abstract class CycleTestCase extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;
    protected User $owner;
    protected string $identifier;

    /**
     * ترحيلات وحدة FlowBuilder قبل أن تبدأ معاملة العزل.
     *
     * الردّ الآلي يمرّ بمنشئ المسارات، وجداوله ليست من ترحيلات التطبيق —
     * فبدونها تسقط كل رسالة واردة بخطأ «جدول غير موجود».
     *
     * وموضعها هنا لا في setUp مقصود: أمرُ ترحيلٍ داخل الاختبار يُنفّذ DDL،
     * وDDL في MySQL يُغلق المعاملة الجارية ضمناً — فيتسرّب ما أنشأه الاختبار
     * إلى ما بعده وتسقط البقيّة بـ«صفّ مكرّر». وهنا نحن قبل بدء المعاملة.
     */
    protected function refreshTestDatabase(): void
    {
        if (!RefreshDatabaseState::$migrated) {
            $this->artisan('migrate:fresh', $this->migrateFreshUsing());
            $this->app[ConsoleKernel::class]->setArtisan(null);
            RefreshDatabaseState::$migrated = true;
        }

        if (!Schema::hasTable('flow_user_data')) {
            $this->artisan('migrate', ['--path' => 'modules/FlowBuilder/Database/Migrations']);
            $this->app[ConsoleKernel::class]->setArtisan(null);
        }

        $this->beginDatabaseTransaction();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => 'user']);
        $this->identifier = (string) Str::uuid();

        $this->organization = Organization::factory()->create([
            'created_by' => $this->owner->id,
            'identifier' => $this->identifier,
            'metadata' => json_encode([
                'timezone' => 'Asia/Riyadh',
                'whatsapp' => [
                    'access_token' => 'test-token',
                    'app_id' => '1',
                    'phone_number_id' => '2',
                    'waba_id' => '3',
                ],
            ]),
        ]);

        Team::factory()->create([
            'user_id' => $this->owner->id,
            'organization_id' => $this->organization->id,
            'role' => 'owner',
            'created_by' => $this->owner->id,
        ]);

        Addon::factory()->create(['name' => 'Google Authenticator']);
        Setting::create(['key' => 'storage_system', 'value' => 'local']);
        Setting::create(['key' => 'whatsapp_callback_token', 'value' => 'global-verify-token']);

        $plan = SubscriptionPlan::create([
            'name' => 'Plan',
            'price' => 0,
            'period' => 'monthly',
            'metadata' => json_encode(['message_limit' => -1]),
        ]);

        Subscription::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'valid_until' => now()->addYear(),
        ]);
    }

    protected function webhookUrl(): string
    {
        return '/webhook/whatsapp/' . $this->identifier;
    }

    /** حمولة واتساب كما تصل فعلاً: entry ⇐ changes ⇐ value. */
    protected function payload(string $field, array $value): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => '123456',
                'changes' => [[
                    'field' => $field,
                    'value' => $value,
                ]],
            ]],
        ];
    }

    /** @param array<string, mixed> $overrides */
    protected function textMessage(array $overrides = []): array
    {
        return array_merge([
            'from' => '966502486051',
            'id' => 'wamid.' . Str::random(24),
            'timestamp' => (string) now()->timestamp,
            'type' => 'text',
            'text' => ['body' => 'السلام عليكم'],
        ], $overrides);
    }

    protected function contact(array $attributes = []): Contact
    {
        return Contact::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'first_name' => 'عميل',
            'phone' => '+966502486051',
            'created_by' => $this->owner->id,
        ], $attributes));
    }

    protected function chat(Contact $contact, array $attributes = []): Chat
    {
        return Chat::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'contact_id' => $contact->id,
            'wam_id' => 'wamid.' . Str::random(24),
            'type' => 'outbound',
            'status' => 'sent',
            'metadata' => json_encode(['type' => 'text', 'text' => ['body' => 'مرحباً']]),
            'created_at' => now(),
        ], $attributes));
    }
}
