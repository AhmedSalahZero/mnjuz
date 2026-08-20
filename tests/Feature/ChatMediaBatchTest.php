<?php

namespace Tests\Feature;

use App\Jobs\SendMediaJob;
use App\Models\Addon;
use App\Models\Chat;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * رفع عدّة مرفقات في طلب واحد.
 *
 * كان كل ملف يستهلك رحلة HTTP كاملة، فثلاثة ملفات ثلاث رحلات متعاقبة. الرحلة
 * الواحدة تختصر الزمن وتضمن ترتيب الإرسال: الوظائف تُلقى في الطابور بترتيب
 * الملفات.
 *
 * المسار الجديد مستقلّ عن الملف المفرد عمداً — ذاك يخدم زرّ الاختيار وواجهة
 * الـAPI — فهذه الاختبارات تحرس الجديد وتتأكّد أن القديم لم يُمسّ.
 */
class ChatMediaBatchTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;
    private Contact $contact;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Bus::fake();

        $this->user = User::factory()->create(['role' => 'user']);
        $this->organization = Organization::factory()->create(['created_by' => $this->user->id]);
        Team::factory()->create([
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'role' => 'owner',
            'created_by' => $this->user->id,
        ]);

        Addon::factory()->create(['name' => 'Google Authenticator']);
        Setting::create(['key' => 'storage_system', 'value' => 'local']);
        foreach (['pusher_app_key', 'pusher_app_secret', 'pusher_app_id', 'pusher_app_cluster'] as $key) {
            Setting::create(['key' => $key, 'value' => 'test']);
        }

        $plan = SubscriptionPlan::create([
            'name' => 'Test Plan',
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

        $this->contact = Contact::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'first_name' => 'Maitha',
            'phone' => '+966500000001',
            'created_by' => $this->user->id,
        ]);

        // نافذة الـ24 ساعة يجب أن تكون مفتوحة وإلا رُفض كل إرسال.
        Chat::create([
            'organization_id' => $this->organization->id,
            'contact_id' => $this->contact->id,
            'type' => 'inbound',
            'metadata' => json_encode(['type' => 'text', 'text' => ['body' => 'مرحباً']]),
            'status' => 'delivered',
            'created_at' => now()->subMinutes(5),
        ]);

        session(['current_organization' => $this->organization->id]);
        $this->actingAs($this->user);
    }

    /** @return array<string, mixed> */
    private function batchPayload(array $files, array $types, ?string $caption = null): array
    {
        $payload = [
            'uuid' => $this->contact->uuid,
            'files' => $files,
            'types' => $types,
            'tempMessageIds' => array_map(fn () => (string) Str::uuid(), $files),
        ];

        if ($caption !== null) {
            $payload['message'] = $caption;
        }

        return $payload;
    }

    // ------------------------------------------------- الدفعة

    public function test_every_file_in_the_batch_is_queued(): void
    {
        $response = $this->post('/chats', $this->batchPayload(
            [
                UploadedFile::fake()->image('one.jpg'),
                UploadedFile::fake()->create('contract.pdf', 40, 'application/pdf'),
                UploadedFile::fake()->create('clip.mp4', 120, 'video/mp4'),
            ],
            ['image', 'document', 'video']
        ));

        $response->assertOk();
        Bus::assertDispatchedTimes(SendMediaJob::class, 3);
    }

    /**
     * ترتيب الإلقاء في الطابور هو ترتيب الملفات: العميل يستقبلها كما اختارها
     * المرسِل لا حسب أيّها انتهى رفعه أولاً.
     */
    public function test_files_are_queued_in_the_order_they_were_chosen(): void
    {
        $this->post('/chats', $this->batchPayload(
            [
                UploadedFile::fake()->image('first.jpg'),
                UploadedFile::fake()->image('second.jpg'),
                UploadedFile::fake()->image('third.jpg'),
            ],
            ['image', 'image', 'image']
        ))->assertOk();

        $order = [];
        Bus::assertDispatched(SendMediaJob::class, function ($job) use (&$order) {
            $order[] = $this->jobProperty($job, 'fileName');

            return true;
        });

        $this->assertSame(['first.jpg', 'second.jpg', 'third.jpg'], $order);
    }

    private function jobProperty(SendMediaJob $job, string $name)
    {
        $property = new \ReflectionProperty(SendMediaJob::class, $name);
        $property->setAccessible(true);

        return $property->getValue($job);
    }

    /** التعليق للأول وحده — تكراره على كل ملف يُغرق المحادثة. */
    public function test_the_caption_is_attached_to_the_first_file_only(): void
    {
        $this->post('/chats', $this->batchPayload(
            [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')],
            ['image', 'image'],
            'الفاتورة المرفقة'
        ))->assertOk();

        $captions = [];
        Bus::assertDispatched(SendMediaJob::class, function ($job) use (&$captions) {
            $captions[] = $this->jobProperty($job, 'caption');

            return true;
        });

        $this->assertSame(['الفاتورة المرفقة', null], $captions);
    }

    public function test_a_single_file_batch_still_works(): void
    {
        $this->post('/chats', $this->batchPayload(
            [UploadedFile::fake()->image('only.jpg')],
            ['image']
        ))->assertOk();

        Bus::assertDispatchedTimes(SendMediaJob::class, 1);
    }

    /** كل ملف يُحفظ مؤقّتاً قبل الإلقاء: العامل يقرأ من القرص لا من الطلب. */
    public function test_each_file_is_persisted_before_it_is_queued(): void
    {
        $this->post('/chats', $this->batchPayload(
            [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->create('b.pdf', 10, 'application/pdf')],
            ['image', 'document']
        ))->assertOk();

        $stored = Storage::disk('local')->files('temp/send-media');
        $this->assertCount(2, $stored);
    }

    // ------------------------------------------------- الحُرّاس

    /**
     * عدد المعرّفات المؤقّتة يجب أن يطابق عدد الملفات: الاختلاف يعني حمولة
     * مشوّهة، وإلقاؤها كان سيُنتج رسائل بلا فقاعات تُستبدَل.
     */
    public function test_a_mismatched_payload_is_rejected(): void
    {
        $response = $this->postJson('/chats', [
            'uuid' => $this->contact->uuid,
            'files' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')],
            'types' => ['image', 'image'],
            'tempMessageIds' => [(string) Str::uuid()],
        ]);

        $response->assertStatus(422);
        Bus::assertNotDispatched(SendMediaJob::class);
    }

    public function test_a_closed_messaging_window_blocks_the_whole_batch(): void
    {
        Chat::where('contact_id', $this->contact->id)->update(['created_at' => now()->subHours(30)]);
        $this->contact->forceFill(['last_inbound_chat_created_at' => now()->subHours(30)])->save();

        $this->postJson('/chats', $this->batchPayload(
            [UploadedFile::fake()->image('a.jpg')],
            ['image']
        ))->assertStatus(422);

        Bus::assertNotDispatched(SendMediaJob::class);
    }

    public function test_a_contact_of_another_organization_is_rejected(): void
    {
        $other = Organization::factory()->create(['created_by' => $this->user->id]);
        $foreign = Contact::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $other->id,
            'first_name' => 'Foreign',
            'phone' => '+201111111111',
            'created_by' => $this->user->id,
        ]);

        $payload = $this->batchPayload([UploadedFile::fake()->image('a.jpg')], ['image']);
        $payload['uuid'] = $foreign->uuid;

        $this->postJson('/chats', $payload)->assertStatus(404);
        Bus::assertNotDispatched(SendMediaJob::class);
    }

    // -------------------------------- ألّا ينكسر المسار القديم

    /**
     * كود إنتاجي: زرّ الاختيار وواجهة الـAPI ما زالا يرسلان ملفاً مفرداً
     * باسم file. المسار الجديد يجب ألّا يعترضه.
     */
    public function test_the_single_file_path_is_untouched(): void
    {
        $response = $this->post('/chats', [
            'uuid' => $this->contact->uuid,
            'type' => 'image',
            'tempMessageId' => (string) Str::uuid(),
            'file' => UploadedFile::fake()->image('single.jpg'),
        ]);

        $response->assertOk();
        Bus::assertDispatchedTimes(SendMediaJob::class, 1);
    }

    public function test_a_plain_text_message_is_untouched_by_the_batch_branch(): void
    {
        $this->postJson('/chats', [
            'uuid' => $this->contact->uuid,
            'type' => 'text',
            'message' => 'رسالة نصّية',
        ]);

        Bus::assertNotDispatched(SendMediaJob::class);
    }

    // ------------------------------------------- الواجهة

    /** الطلب الواحد شرط التحسين: العودة إلى حلقة طلبات تُلغيه. */
    public function test_the_composer_uploads_the_batch_in_one_request(): void
    {
        $composer = file_get_contents(
            base_path('resources/js/Components/ChatComponents/ChatForm.vue')
        );

        $this->assertStringContainsString("formData.append('files[]', item.file)", $composer);
        $this->assertMatchesRegularExpression(
            '/const sendAttachments = async \(\) => \{(?:(?!\n\}).)*?await axios\.post\(\x27\/chats\x27, formData\)/s',
            $composer,
            'الإرسال يجب أن يكون طلباً واحداً لا حلقة'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/for \(const \[index, item\] of queue\.entries\(\)\) \{\s*\n[^}]*await sendMessage\(\)/s',
            $composer,
            'حلقة إرسال متعاقبة تُعيد البطء'
        );
    }

    /**
     * FileReader كان يقرأ الملف كاملاً إلى base64 ثم يُرمى الناتج — نسخة
     * ثانية في الذاكرة وتأخير قبل أن يبدأ الرفع.
     */
    public function test_the_file_picker_no_longer_reads_the_whole_file_first(): void
    {
        $composer = file_get_contents(
            base_path('resources/js/Components/ChatComponents/ChatForm.vue')
        );

        $this->assertStringNotContainsString('readAsDataURL', $composer);
        $this->assertStringNotContainsString('new FileReader()', $composer);
    }

    /** ترتيب العرض يتحدّد بالفقاعات المتفائلة لا بترتيب وصول الردود. */
    public function test_optimistic_bubbles_are_created_before_the_request(): void
    {
        $composer = file_get_contents(
            base_path('resources/js/Components/ChatComponents/ChatForm.vue')
        );

        $bubbles = strpos($composer, 'appendMessageIntoBody(form)\n\t})');
        $this->assertMatchesRegularExpression(
            '/queue\.forEach\(\(item, index\) => \{.*?appendMessageIntoBody\(form\).*?\}\).*?await axios\.post/s',
            $composer,
            'الفقاعات تُنشأ قبل الطلب لا بعده'
        );
    }

    /** فشل الطلب يجب أن يُزيل الفقاعات المتفائلة، وإلا بقيت رسائل لم تُرسَل. */
    public function test_a_failed_batch_removes_its_optimistic_bubbles(): void
    {
        $composer = file_get_contents(
            base_path('resources/js/Components/ChatComponents/ChatForm.vue')
        );

        $this->assertMatchesRegularExpression(
            "/catch \(error\) \{.*?tempIds\.forEach\(\(id\) => emit\('removeMessage', id\)\)/s",
            $composer
        );
    }
}
