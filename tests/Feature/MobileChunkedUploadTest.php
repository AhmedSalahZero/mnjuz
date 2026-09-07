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
use App\Services\Chat\ChunkedUploadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * رفع مرفقات التطبيق على قطع.
 *
 * الطلب الواحد الحامل للملف كلّه يموت مرّتين على شبكة الجوال: عند
 * post_max_size، وقبله عند مهلة الوكيل الأمامي (١٢٥ ثانية) — فينقطع الرفع
 * بلا رسالة. القطعة الصغيرة تجعل الزمن يُقاس بالقطعة لا بالملف.
 *
 * وما يُختبَر هنا هو الطرف الذي يستقبل: أن الملف يعود كما كان، وأن الفحوص
 * تقع قبل قبول أي بايت، وأن المنشأة تأتي من التوكن لا من جلسةٍ لا يملكها
 * التطبيق.
 */
class MobileChunkedUploadTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/chats/upload/chunk';

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

    private function sendChunk(array $payload)
    {
        return $this->post(self::ENDPOINT, array_merge([
            'phone' => $this->contact->phone,
        ], $payload), ['Accept' => 'application/json']);
    }

    /** رفع ملف كامل قطعةً قطعة. @return \Illuminate\Testing\TestResponse آخر ردّ */
    private function uploadWhole(string $content, string $fileName, array $extra = [], int $chunkBytes = 4)
    {
        $parts = str_split($content, $chunkBytes);
        $uploadId = Str::random(20);
        $response = null;

        foreach ($parts as $index => $part) {
            $response = $this->sendChunk(array_merge([
                'upload_id' => $uploadId,
                'index' => $index,
                'total' => count($parts),
                'chunk' => UploadedFile::fake()->createWithContent('chunk', $part),
                'file_name' => $fileName,
            ], $extra));
        }

        return $response;
    }

    private function dispatchedJob(): SendMediaJob
    {
        $found = null;

        Bus::assertDispatched(SendMediaJob::class, function ($job) use (&$found) {
            $found = $job;

            return true;
        });

        return $found;
    }

    // ------------------------------------------------- المسار السعيد

    public function test_the_endpoint_is_reachable_with_a_bearer_token(): void
    {
        $this->sendChunk([
            'upload_id' => 'abc-123',
            'index' => 0,
            'total' => 2,
            'chunk' => UploadedFile::fake()->createWithContent('chunk', 'AAAA'),
            'file_name' => 'report.pdf',
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.completed', false)
            ->assertJsonPath('data.received', 1)
            ->assertJsonPath('data.total', 2);

        // لا إرسال قبل اكتمال القطع.
        Bus::assertNotDispatched(SendMediaJob::class);
    }

    /** القطع تُدمج بالترتيب، والملف يصل الوظيفةَ كما كان بايتاً ببايت. */
    public function test_the_assembled_file_matches_the_original_bytes(): void
    {
        $content = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

        $this->uploadWhole($content, 'report.pdf')
            ->assertOk()
            ->assertJsonPath('data.completed', true)
            ->assertJsonPath('data.queued', true)
            ->assertJsonPath('data.contact_id', $this->contact->id);

        $job = $this->dispatchedJob();

        $this->assertSame($content, Storage::disk('local')->get($job->tempFilePath));
        $this->assertSame('report.pdf', $job->fileName);
        $this->assertSame('document', $job->fileType);
    }

    /**
     * ملفٌ صغير في قطعة واحدة يكتمل فوراً.
     *
     * كي يصحّ للتطبيق أن يسلك هذا الطريق دائماً بلا حساب حجم: `total=1`
     * طريقٌ عاديّ لا حالةٌ خاصّة.
     */
    public function test_a_small_file_completes_in_a_single_chunk(): void
    {
        $this->sendChunk([
            'upload_id' => Str::random(20),
            'index' => 0,
            'total' => 1,
            'chunk' => UploadedFile::fake()->createWithContent('chunk', 'TINY'),
            'file_name' => 'photo.jpg',
        ])->assertOk()
            ->assertJsonPath('data.completed', true)
            ->assertJsonPath('data.queued', true);

        $this->assertSame('TINY', Storage::disk('local')->get($this->dispatchedJob()->tempFilePath));
    }

    /**
     * إعادة إرسال قطعة لا تُحتسب مرّتين ولا تُرسل الملف مرّتين.
     *
     * الشبكة تسقط، والتطبيق يُعيد المحاولة — فلو عدّت الإعادةُ قطعةً جديدة
     * لاكتمل الملف ناقصاً، ولو أُرسل مرّتين لظهرت الرسالة مرّتين عند العميل.
     */
    public function test_resending_a_chunk_is_idempotent(): void
    {
        $uploadId = Str::random(20);

        foreach ([0, 0, 0] as $index) {
            $this->sendChunk([
                'upload_id' => $uploadId,
                'index' => $index,
                'total' => 2,
                'chunk' => UploadedFile::fake()->createWithContent('chunk', 'AAA'),
                'file_name' => 'report.pdf',
            ])->assertOk()->assertJsonPath('data.received', 1);
        }

        Bus::assertNotDispatched(SendMediaJob::class);

        $this->sendChunk([
            'upload_id' => $uploadId,
            'index' => 1,
            'total' => 2,
            'chunk' => UploadedFile::fake()->createWithContent('chunk', 'BBB'),
            'file_name' => 'report.pdf',
        ])->assertOk()->assertJsonPath('data.completed', true);

        Bus::assertDispatchedTimes(SendMediaJob::class, 1);
        $this->assertSame('AAABBB', Storage::disk('local')->get($this->dispatchedJob()->tempFilePath));
    }

    /** الترتيب يتبع الفهرس لا ترتيب الوصول — الشبكة لا تضمن التتابع. */
    public function test_chunks_that_arrive_out_of_order_still_assemble_correctly(): void
    {
        $uploadId = Str::random(20);
        $parts = ['AAA', 'BBB', 'CCC'];

        foreach ([2, 0, 1] as $index) {
            $this->sendChunk([
                'upload_id' => $uploadId,
                'index' => $index,
                'total' => 3,
                'chunk' => UploadedFile::fake()->createWithContent('chunk', $parts[$index]),
                'file_name' => 'clip.mp4',
            ])->assertOk();
        }

        $this->assertSame('AAABBBCCC', Storage::disk('local')->get($this->dispatchedJob()->tempFilePath));
    }

    public function test_the_type_is_derived_from_the_file_name(): void
    {
        $this->uploadWhole('DATA', 'photo.jpg');

        $this->assertSame('image', $this->dispatchedJob()->fileType);
    }

    public function test_the_caption_and_message_id_reach_the_job(): void
    {
        $uuid = (string) Str::uuid();

        $this->uploadWhole('DATA', 'photo.jpg', [
            'caption' => 'الفاتورة المرفقة',
            'msg_uuid' => $uuid,
        ]);

        $job = $this->dispatchedJob();

        $this->assertSame('الفاتورة المرفقة', $job->caption);
        $this->assertSame($uuid, $job->messageUUID);
    }

    /**
     * جهة الاتصال تُحلّ من الرقم كما في /send-media — التطبيق لا يعرف uuid.
     *
     * ورقمٌ لم يراسلنا قطّ نافذته مغلقة بالتعريف، فيُرفض قبل أي بايت: هذا هو
     * سلوك /send-media نفسه، ولا يجوز أن يفترقا.
     */
    public function test_an_unknown_phone_is_rejected_by_the_messaging_window(): void
    {
        $phone = '+966500000777';

        $this->sendChunk([
            'phone' => $phone,
            'upload_id' => 'abc-123',
            'index' => 0,
            'total' => 1,
            'chunk' => UploadedFile::fake()->createWithContent('chunk', 'AAAA'),
            'file_name' => 'photo.jpg',
        ])->assertStatus(422)->assertJsonPath('statusCode', 422);

        Bus::assertNotDispatched(SendMediaJob::class);
    }

    /** والرقم الموجود تُحلّ جهته فيصل uuid الصحيح إلى الوظيفة. */
    public function test_the_known_phone_resolves_to_its_contact(): void
    {
        $this->uploadWhole('DATA', 'photo.jpg');

        $this->assertSame((string) $this->contact->uuid, $this->dispatchedJob()->uuid);
    }

    // ------------------------------------------------- الحراسة

    public function test_it_requires_authentication(): void
    {
        app('auth')->forgetGuards();

        $this->postJson(self::ENDPOINT, [])->assertStatus(401);
    }

    /** الرفع محصور بالمنشأة والمستخدم: معرّفٌ مخمَّن لا يبلغ رفع غيره. */
    public function test_the_upload_is_scoped_to_the_organization_and_user(): void
    {
        $this->sendChunk([
            'upload_id' => 'abc-123',
            'index' => 0,
            'total' => 2,
            'chunk' => UploadedFile::fake()->createWithContent('chunk', 'AAAA'),
            'file_name' => 'report.pdf',
        ])->assertOk();

        $mine = ChunkedUploadService::directoryFor($this->organization->id, $this->user->id, 'abc-123');
        $other = ChunkedUploadService::directoryFor($this->organization->id + 1, $this->user->id, 'abc-123');

        $this->assertTrue(Storage::disk('local')->exists($mine . '/000000.part'));
        $this->assertFalse(Storage::disk('local')->exists($other . '/000000.part'));
    }

    /** الفحص قبل البايت: نافذة مغلقة ⇒ لا قطعة تُكتب على القرص. */
    public function test_a_closed_window_is_rejected_before_any_byte_is_stored(): void
    {
        Chat::where('contact_id', $this->contact->id)->update(['created_at' => now()->subDays(2)]);
        $this->contact->forceFill(['last_inbound_chat_created_at' => now()->subDays(2)])->save();

        $this->sendChunk([
            'upload_id' => 'abc-123',
            'index' => 0,
            'total' => 2,
            'chunk' => UploadedFile::fake()->createWithContent('chunk', 'AAAA'),
            'file_name' => 'report.pdf',
        ])->assertStatus(422)->assertJsonPath('success', false)->assertJsonPath('statusCode', 422);

        $directory = ChunkedUploadService::directoryFor($this->organization->id, $this->user->id, 'abc-123');
        $this->assertFalse(Storage::disk('local')->exists($directory . '/000000.part'));
    }

    public function test_an_inactive_subscription_is_rejected(): void
    {
        Subscription::where('organization_id', $this->organization->id)
            ->update(['valid_until' => now()->subDay()]);

        $this->sendChunk([
            'upload_id' => 'abc-123',
            'index' => 0,
            'total' => 1,
            'chunk' => UploadedFile::fake()->createWithContent('chunk', 'AAAA'),
            'file_name' => 'report.pdf',
        ])->assertStatus(403)->assertJsonPath('statusCode', 403);
    }

    public function test_a_disconnected_whatsapp_is_rejected(): void
    {
        $this->organization->forceFill(['metadata' => json_encode([])])->save();

        $this->sendChunk([
            'upload_id' => 'abc-123',
            'index' => 0,
            'total' => 1,
            'chunk' => UploadedFile::fake()->createWithContent('chunk', 'AAAA'),
            'file_name' => 'report.pdf',
        ])->assertStatus(403)->assertJsonPath('statusCode', 403);
    }

    public function test_an_unsupported_extension_is_rejected(): void
    {
        $this->sendChunk([
            'upload_id' => 'abc-123',
            'index' => 0,
            'total' => 1,
            'chunk' => UploadedFile::fake()->createWithContent('chunk', 'AAAA'),
            'file_name' => 'script.exe',
        ])->assertStatus(400)->assertJsonPath('success', false);

        Bus::assertNotDispatched(SendMediaJob::class);
    }

    public function test_a_missing_field_is_rejected_with_the_api_shape(): void
    {
        $response = $this->sendChunk([
            'upload_id' => 'abc-123',
            'index' => 0,
            'chunk' => UploadedFile::fake()->createWithContent('chunk', 'AAAA'),
            'file_name' => 'report.pdf',
        ]);

        $response->assertStatus(400)
            ->assertJsonPath('statusCode', 400)
            ->assertJsonPath('success', false);

        $this->assertArrayHasKey('total', $response->json('errors'));
    }

    /**
     * المجمَّع يُقاس بحدّ نوعه لا بحدّ PHP.
     *
     * القطع تمرّ فرادى فلا يبلغ أيٌّ منها upload_max_filesize، وقياس المجمَّع
     * به كان يُبطل الغرض كلّه: مستندٌ تُقبل قطعه ثم يُرفض بعد الدمج.
     */
    public function test_a_file_over_its_type_limit_is_rejected_after_assembly(): void
    {
        config(['chat.max_upload_kb_by_type.document' => 1]); // 1KB

        $this->uploadWhole(str_repeat('A', 4096), 'big.pdf', [], 1024)
            ->assertStatus(400)
            ->assertJsonPath('success', false);

        Bus::assertNotDispatched(SendMediaJob::class);
    }

    /** الملغى يُحرَّر فوراً — لا ينتظر التنظيف المجدوَل. */
    public function test_a_cancelled_upload_is_discarded(): void
    {
        $this->sendChunk([
            'upload_id' => 'abc-123',
            'index' => 0,
            'total' => 2,
            'chunk' => UploadedFile::fake()->createWithContent('chunk', 'AAAA'),
            'file_name' => 'report.pdf',
        ])->assertOk();

        $directory = ChunkedUploadService::directoryFor($this->organization->id, $this->user->id, 'abc-123');
        $this->assertTrue(Storage::disk('local')->exists($directory . '/000000.part'));

        $this->delete(self::ENDPOINT, ['upload_id' => 'abc-123'], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertFalse(Storage::disk('local')->exists($directory . '/000000.part'));
    }
}
