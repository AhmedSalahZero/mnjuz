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
 * مرفقات المحادثة المرفوعة على قطع من الويب: الصورة تُجهَّز لواتساب كما في
 * الرفع العادي (ChatService)، والملف المجمَّع يُقاس بحدّ نوعه لا بحدّ PHP.
 */
class ChatChunkedUploadImageTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/chats/upload/chunk';

    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();

        if (!extension_loaded('imagick')) {
            $this->markTestSkipped('needs Imagick');
        }

        Storage::fake('local');
        Bus::fake();

        $user = User::factory()->create(['role' => 'user', 'email_verified_at' => now()]);
        $organization = Organization::factory()->create([
            'created_by' => $user->id,
            'metadata' => json_encode(['whatsapp' => ['access_token' => 't', 'app_id' => '1', 'phone_number_id' => '2', 'waba_id' => '3']]),
        ]);
        Team::factory()->create(['user_id' => $user->id, 'organization_id' => $organization->id, 'role' => 'owner', 'created_by' => $user->id]);

        Addon::factory()->create(['name' => 'Google Authenticator']);
        Setting::create(['key' => 'storage_system', 'value' => 'local']);

        $plan = SubscriptionPlan::create(['name' => 'P', 'price' => 0, 'period' => 'monthly', 'metadata' => json_encode(['message_limit' => -1])]);
        Subscription::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $organization->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'valid_until' => now()->addYear(),
        ]);

        $this->contact = Contact::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $organization->id,
            'first_name' => 'Sara',
            'phone' => '+966500000001',
            'created_by' => $user->id,
        ])->fresh();
        Chat::create([
            'organization_id' => $organization->id,
            'contact_id' => $this->contact->id,
            'type' => 'inbound',
            'metadata' => json_encode(['type' => 'text', 'text' => ['body' => 'hi']]),
            'status' => 'delivered',
            'created_at' => now()->subMinutes(5),
        ]);

        session(['current_organization' => $organization->id]);
        $this->actingAs($user);
    }

    private function upload(string $bytes, string $fileName, int $chunkBytes = 4096)
    {
        $parts = str_split($bytes, $chunkBytes);
        $uploadId = 'web-' . Str::random(12);
        $response = null;

        foreach ($parts as $index => $part) {
            $response = $this->post(self::ENDPOINT, [
                'upload_id' => $uploadId,
                'index' => $index,
                'total' => count($parts),
                'chunk' => UploadedFile::fake()->createWithContent('chunk', $part),
                'contact_uuid' => $this->contact->uuid,
                'file_name' => $fileName,
                'file_type' => 'image',
            ], ['Accept' => 'application/json']);
        }

        return $response;
    }

    private function image(int $colorspace = \Imagick::COLORSPACE_SRGB, int $size = 120): string
    {
        $img = new \Imagick();
        $img->newPseudoImage($size, $size, 'gradient:red-blue');
        if ($colorspace !== \Imagick::COLORSPACE_SRGB) {
            $img->transformImageColorspace($colorspace);
        }
        $img->setImageFormat('jpeg');
        $bytes = $img->getImageBlob();
        $img->destroy();

        return $bytes;
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

    public function test_a_chunked_cmyk_image_is_normalised_before_sending(): void
    {
        $this->upload($this->image(\Imagick::COLORSPACE_CMYK), 'design.jpg')->assertOk();

        $job = $this->dispatchedJob();
        $sent = new \Imagick();
        $sent->readImageBlob(Storage::disk('local')->get($job->tempFilePath));

        $this->assertSame(\Imagick::COLORSPACE_SRGB, $sent->getImageColorspace());
        $this->assertStringEndsWith('.jpg', $job->fileName);
    }

    public function test_an_unreadable_chunked_heic_photo_is_refused_with_the_reason(): void
    {
        $response = $this->upload("\x00\x00\x00\x18ftypheic\x00\x00\x00\x00mif1heic" . str_repeat("\x00", 64), 'IMG_0001.heic');

        $response->assertStatus(422)->assertJsonPath('success', false);
        $this->assertStringContainsString('HEIC', $response->json('message'));
        Bus::assertNotDispatched(SendMediaJob::class);
    }

    public function test_a_valid_chunked_jpeg_is_sent_untouched(): void
    {
        $bytes = $this->image();

        $this->upload($bytes, 'ok.jpg')->assertOk();

        $this->assertSame($bytes, Storage::disk('local')->get($this->dispatchedJob()->tempFilePath));
    }

    /**
     * الملف المجمَّع يُقاس بحدّ نوعه، لا بحدّ PHP للطلب الواحد — وإلا بطل
     * غرض التجزئة: كل صورة أكبر من upload_max_filesize تُرفض بعد رفعها كاملة.
     */
    public function test_the_assembled_file_is_not_measured_against_the_php_request_limit(): void
    {
        $phpLimit = \App\Helpers\ChatMediaUploadHelper::phpMaxUploadBytes();
        if ($phpLimit > 8 * 1024 * 1024) {
            $this->markTestSkipped('PHP upload limit is large here; the case needs a small limit');
        }

        // صورة سليمة أكبر من حدّ PHP وأصغر من 5MB تُرفع على قطع صغيرة.
        $bytes = $this->image(\Imagick::COLORSPACE_SRGB, 1);
        $bytes .= str_repeat("\x00", $phpLimit + 1024 - strlen($bytes));

        $response = $this->upload($bytes, 'big.jpg', 512 * 1024);

        $this->assertNotSame(422, $response->status(), 'rejected by the PHP request limit: ' . $response->getContent());
    }
}
