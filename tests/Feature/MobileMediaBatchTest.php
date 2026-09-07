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
 * رفع عدّة ملفات من تطبيق الجوال في طلب واحد.
 *
 * لم يكن للتطبيق سبيل إلى رفع ملف أصلاً: النقطة معطّلة في المسارات، والأخرى
 * تأخذ رابطاً جاهزاً لا ملفاً. وحين فُعّلت كانت تُلقي وظيفةً مستقلّة لكل ملف،
 * فتُنفَّذ متوازيةً ويصل الملف البطيء بين الصور: يختلف الترتيب عمّا اختاره
 * المرسِل وينقطع ضمّها في ألبوم واحد.
 *
 * صارت كلّها نقطةً واحدة — /send-msg — تمرّ بمسار الويب نفسه: سلسلة مرتَّبة.
 */
class MobileMediaBatchTest extends TestCase
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
        $this->organization = Organization::factory()->create([
            'created_by' => $this->user->id,
            'metadata' => json_encode([
                'whatsapp' => [
                    'access_token' => 'token',
                    'app_id' => '1',
                    'phone_number_id' => '2',
                    'waba_id' => '3',
                ],
            ]),
        ]);
        Team::factory()->create([
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'role' => 'owner',
            'created_by' => $this->user->id,
        ]);

        Addon::factory()->create(['name' => 'Google Authenticator']);
        Addon::factory()->create(['name' => 'Mobile App', 'status' => 1, 'is_active' => 1]);
        Setting::create(['key' => 'storage_system', 'value' => 'local']);
        foreach (['pusher_app_key', 'pusher_app_secret', 'pusher_app_id', 'pusher_app_cluster'] as $key) {
            Setting::create(['key' => $key, 'value' => 'test']);
        }

        $plan = SubscriptionPlan::create([
            'name' => 'Test Plan',
            'price' => 0,
            'period' => 'monthly',
            'metadata' => json_encode(['message_limit' => -1, 'activity_log' => 1]),
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

        // نافذة الـ24 ساعة مفتوحة، وإلا رُفض كل إرسال.
        Chat::create([
            'organization_id' => $this->organization->id,
            'contact_id' => $this->contact->id,
            'type' => 'inbound',
            'metadata' => json_encode(['type' => 'text', 'text' => ['body' => 'مرحباً']]),
            'status' => 'delivered',
            'created_at' => now()->subMinutes(5),
        ]);

        $this->user->forceFill([
            'current_mobile_organization_id' => $this->organization->id,
        ])->save();

        $this->actingAs($this->user, 'sanctum');
    }

    /** الرفع بشكل multipart: postJson يُسلسل الملفات ولا يرفعها. */
    private function upload(array $payload)
    {
        return $this->post(
            '/api/v1/send-msg',
            array_merge(['phone' => $this->contact->phone], $payload),
            ['Accept' => 'application/json']
        );
    }

    /** @return list<SendMediaJob> رأس السلسلة ثمّ ما عُلّق به، بالترتيب. */
    private function batchJobs(): array
    {
        $jobs = [];

        Bus::assertDispatched(SendMediaJob::class, function ($job) use (&$jobs) {
            $jobs[] = $job;

            foreach ($job->chained as $serialized) {
                $jobs[] = unserialize($serialized);
            }

            return true;
        });

        return $jobs;
    }

    private function jobProperty(SendMediaJob $job, string $name)
    {
        $property = new \ReflectionProperty(SendMediaJob::class, $name);
        $property->setAccessible(true);

        return $property->getValue($job);
    }

    // ------------------------------------------------- النقطة نفسها

    public function test_the_unified_endpoint_is_reachable(): void
    {
        $this->upload(['file' => [UploadedFile::fake()->image('one.jpg')]])->assertOk();
    }

    // ------------------------------------------------- الدفعة

    /** طلبٌ واحد يحمل الملفات كلها، وسلسلةٌ واحدة تُلقى في الطابور. */
    public function test_many_files_go_out_in_one_ordered_chain(): void
    {
        $this->upload([
            'file' => [
                UploadedFile::fake()->image('first.jpg'),
                UploadedFile::fake()->image('second.jpg'),
                UploadedFile::fake()->create('third.pdf', 40, 'application/pdf'),
            ],
        ])->assertOk()->assertJsonPath('data.files', 3);

        Bus::assertDispatchedTimes(SendMediaJob::class, 1);

        $names = array_map(fn (SendMediaJob $job) => $this->jobProperty($job, 'fileName'), $this->batchJobs());

        $this->assertSame(['first.jpg', 'second.jpg', 'third.pdf'], $names, 'الترتيب يجب أن يطابق ترتيب الاختيار');
    }

    public function test_each_file_keeps_its_own_message_id(): void
    {
        $ids = [(string) Str::uuid(), (string) Str::uuid()];

        $this->upload([
            'file' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')],
            'msg_uuid' => $ids,
        ])->assertOk();

        $sent = array_map(fn (SendMediaJob $job) => $this->jobProperty($job, 'messageUUID'), $this->batchJobs());

        $this->assertSame($ids, $sent, 'معرّف واحد لعدّة ملفات يُنجح الأول ويُفشل البقية');
    }

    public function test_the_file_type_is_resolved_per_file(): void
    {
        $this->upload([
            'file' => [
                UploadedFile::fake()->image('photo.jpg'),
                UploadedFile::fake()->create('clip.mp4', 120, 'video/mp4'),
                UploadedFile::fake()->create('contract.pdf', 40, 'application/pdf'),
            ],
        ])->assertOk();

        $types = array_map(fn (SendMediaJob $job) => $this->jobProperty($job, 'fileType'), $this->batchJobs());

        $this->assertSame(['image', 'video', 'document'], $types);
    }

    /** التعليق للأول وحده — تكراره على كل ملف يُغرق محادثة العميل. */
    public function test_the_caption_is_attached_to_the_first_file_only(): void
    {
        $this->upload([
            'file' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')],
            'caption' => 'الفاتورة المرفقة',
        ])->assertOk();

        $captions = array_map(fn (SendMediaJob $job) => $this->jobProperty($job, 'caption'), $this->batchJobs());

        $this->assertSame(['الفاتورة المرفقة', null], $captions);
    }

    /** وفشل ملف لا يبتلع بقيّة الدفعة. */
    public function test_batch_jobs_survive_a_rejected_file(): void
    {
        $this->upload([
            'file' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')],
        ])->assertOk();

        foreach ($this->batchJobs() as $job) {
            $this->assertTrue($this->jobProperty($job, 'continueBatchOnFailure'));
        }
    }

    /** سطرٌ واحد في سجلّ النشاط للدفعة، لا سطر لكل مسار يمرّ به الطلب. */
    public function test_the_upload_is_logged_once(): void
    {
        $this->upload([
            'file' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')],
        ])->assertOk();

        $this->assertSame(
            1,
            \App\Models\ActivityLog::where('event', \App\Services\ActivityLogger::MEDIA_SENT)->count(),
            'المسار المشترك يسجّل بنفسه — التسجيل في المتحكّم يُكرّر السطر'
        );
    }

    public function test_a_single_file_still_works(): void
    {
        $this->upload([
            'file' => UploadedFile::fake()->image('only.jpg'),
        ])->assertOk()->assertJsonPath('data.files', 1);

        Bus::assertDispatchedTimes(SendMediaJob::class, 1);
        $this->assertCount(1, $this->batchJobs());
    }

    /** الشكل القديم `type=image` مع `file[]` كما كان يستعمله التطبيق. */
    public function test_the_old_shape_with_a_type_field_still_works(): void
    {
        $this->upload([
            'type' => 'image',
            'file' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')],
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.files', 2)
            ->assertJsonPath('data.contact_id', $this->contact->id);

        $this->assertCount(2, $this->batchJobs());
    }

    // ------------------------------------------------- توحيد النقطة

    /** `files[]` اسمٌ آخر لـ `file[]` — الداشبورد يسمّيها كذلك. */
    public function test_files_is_accepted_as_an_alias_for_file(): void
    {
        $this->upload([
            'files' => [UploadedFile::fake()->image('first.jpg'), UploadedFile::fake()->image('second.jpg')],
        ])->assertOk()->assertJsonPath('data.files', 2);

        $names = array_map(fn (SendMediaJob $job) => $this->jobProperty($job, 'fileName'), $this->batchJobs());

        $this->assertSame(['first.jpg', 'second.jpg'], $names);
    }

    public function test_a_single_file_under_the_files_name_still_works(): void
    {
        $this->upload([
            'files' => UploadedFile::fake()->image('only.jpg'),
        ])->assertOk()->assertJsonPath('data.files', 1);

        $this->assertCount(1, $this->batchJobs());
    }

    /** الاسمان معاً في طلب واحد: تُجمع كلّها ولا يُسقط أحدهما الآخر. */
    public function test_both_names_in_one_request_are_merged(): void
    {
        $this->upload([
            'file' => [UploadedFile::fake()->image('a.jpg')],
            'files' => [UploadedFile::fake()->image('b.jpg')],
        ])->assertOk()->assertJsonPath('data.files', 2);

        $names = array_map(fn (SendMediaJob $job) => $this->jobProperty($job, 'fileName'), $this->batchJobs());

        $this->assertSame(['a.jpg', 'b.jpg'], $names);
    }

    /**
     * النصّ يُرسل مع ملف ⇒ تعليقٌ عليه.
     *
     * `type=text` مع ملف كان يذهب إلى مسار النصّ فيُهمَل الملف صامتاً.
     */
    public function test_a_file_wins_over_a_text_type_and_the_message_becomes_its_caption(): void
    {
        $this->upload([
            'type' => 'text',
            'message' => 'الفاتورة المرفقة',
            'file' => [UploadedFile::fake()->image('a.jpg')],
        ])->assertOk()->assertJsonPath('data.files', 1);

        $jobs = $this->batchJobs();

        $this->assertCount(1, $jobs);
        $this->assertSame('الفاتورة المرفقة', $this->jobProperty($jobs[0], 'caption'));
    }

    /** و`caption` الصريح يعلو `message` حين يُرسلان معاً. */
    public function test_an_explicit_caption_wins_over_the_message_field(): void
    {
        $this->upload([
            'message' => 'نصّ',
            'caption' => 'تعليق',
            'file' => [UploadedFile::fake()->image('a.jpg')],
        ])->assertOk();

        $this->assertSame('تعليق', $this->jobProperty($this->batchJobs()[0], 'caption'));
    }

    /** والنصّ وحده يبقى نصّاً: لا يُحوَّل إلى مسار الملفات. */
    public function test_a_text_only_request_does_not_go_down_the_media_path(): void
    {
        $this->post('/api/v1/send-msg', [
            'phone' => $this->contact->phone,
            'type' => 'text',
            'message' => 'مرحباً',
        ], ['Accept' => 'application/json']);

        Bus::assertNotDispatched(SendMediaJob::class);
    }

    /** النقطة المستقلّة معطّلة عمداً — نقطة واحدة لا نقطتان. */
    public function test_the_standalone_media_route_is_disabled(): void
    {
        $this->post('/api/v1/send-media', [
            'phone' => $this->contact->phone,
            'file' => [UploadedFile::fake()->image('a.jpg')],
        ], ['Accept' => 'application/json'])->assertStatus(404);
    }

    // ------------------------------------------------- الرفض

    public function test_a_closed_messaging_window_is_rejected_in_the_api_shape(): void
    {
        Chat::where('contact_id', $this->contact->id)->update([
            'created_at' => now()->subDays(2),
        ]);
        // العمود المختصر على جهة الاتصال هو ما تقرأه النافذة أولاً.
        $this->contact->forceFill(['last_inbound_chat_created_at' => now()->subDays(2)])->save();

        $response = $this->upload([
            'file' => [UploadedFile::fake()->image('a.jpg')],
        ]);

        $response->assertJsonPath('success', false);
        $this->assertArrayHasKey('statusCode', $response->json(), 'التطبيق يقرأ statusCode');
        Bus::assertNotDispatched(SendMediaJob::class);
    }

    public function test_an_unsupported_file_is_rejected(): void
    {
        $this->upload([
            'file' => [UploadedFile::fake()->create('script.exe', 10, 'application/x-msdownload')],
        ])->assertStatus(400);

        Bus::assertNotDispatched(SendMediaJob::class);
    }

    public function test_more_files_than_the_limit_are_rejected(): void
    {
        $files = [];
        for ($i = 0; $i < (int) config('chat.max_batch_files', 10) + 1; $i++) {
            $files[] = UploadedFile::fake()->image("f{$i}.jpg");
        }

        $this->upload([
            'file' => $files,
        ])->assertStatus(400);

        Bus::assertNotDispatched(SendMediaJob::class);
    }
}
