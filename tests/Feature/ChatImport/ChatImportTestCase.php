<?php

namespace Tests\Feature\ChatImport;

use App\Jobs\ValidateChatImportJob;
use App\Models\Addon;
use App\Models\ChatImport;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * أساس اختبارات استيراد المحادثات: منشأة بمالكها وباقة فعّالة، وملف CSV
 * حقيقي على القرص يمرّ بالوظائف الحقيقية.
 */
abstract class ChatImportTestCase extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;

    protected User $user;

    protected SubscriptionPlan $plan;

    /** @var array<int, string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();

        [$this->user, $this->organization] = $this->makeOrganization('owner');

        Addon::factory()->create(['name' => 'Google Authenticator']);
        Setting::create(['key' => 'storage_system', 'value' => 'local']);
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $path) {
            Storage::disk('local')->delete($path);
        }
        Storage::disk('local')->deleteDirectory('chat-imports/' . $this->organization->id);

        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $limits حدود الباقة (contacts_limit، message_limit…)
     * @return array{0: User, 1: Organization}
     */
    protected function makeOrganization(string $role = 'owner', array $limits = [], array $metadata = ['timezone' => 'Asia/Riyadh']): array
    {
        $user = User::factory()->create(['role' => 'user']);
        $organization = Organization::factory()->create([
            'created_by' => $user->id,
            'metadata' => json_encode($metadata),
        ]);

        Team::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'role' => $role,
            'created_by' => $user->id,
        ]);

        $this->plan = SubscriptionPlan::create([
            'name' => 'Test Plan ' . Str::random(4),
            'price' => 0,
            'period' => 'monthly',
            'metadata' => json_encode(array_merge(['contacts_limit' => -1, 'message_limit' => -1], $limits)),
        ]);

        Subscription::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $organization->id,
            'plan_id' => $this->plan->id,
            'status' => 'active',
            'valid_until' => now()->addYear(),
        ]);

        return [$user, $organization];
    }

    protected function setLimits(array $limits): void
    {
        $this->plan->update(['metadata' => json_encode(array_merge(['contacts_limit' => -1, 'message_limit' => -1], $limits))]);
    }

    /** يكتب الملف وينشئ له استيراداً بانتظار الفحص — كما يتركه الرفع. */
    protected function makeImport(string $csv, array $attributes = [], ?Organization $organization = null, ?User $user = null): ChatImport
    {
        $organization ??= $this->organization;
        $user ??= $this->user;

        $path = 'chat-imports/' . $organization->id . '/test-' . Str::random(8) . '.csv';
        Storage::disk('local')->put($path, $csv);
        $this->files[] = $path;

        return ChatImport::create(array_merge([
            'upload_id' => 'ci-test-' . Str::random(12),
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'original_name' => 'history.csv',
            'file_path' => $path,
            'file_size' => strlen($csv),
            'timezone' => 'Asia/Riyadh',
            'locale' => 'en',
            'status' => ChatImport::PENDING_VALIDATION,
        ], $attributes));
    }

    /** الفحص ثم الاستيراد كلّه — على طابور sync يعمل كلٌّ منهما فوراً. */
    protected function runImport(ChatImport $import): ChatImport
    {
        (new ValidateChatImportJob($import->id))->handle();

        return $import->refresh();
    }

    /** @param array<int, array<int, string>> $rows */
    protected function csv(array $rows, array $header = ['phone', 'name', 'sent_at', 'direction', 'text']): string
    {
        $handle = fopen('php://temp', 'w+b');
        fputcsv($handle, $header, ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($handle, $row, ',', '"', '');
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    protected function contactByPhone(string $phone, ?Organization $organization = null): ?Contact
    {
        return Contact::where('organization_id', ($organization ?? $this->organization)->id)->where('phone', $phone)->first();
    }

    protected function importedChats(ChatImport $import)
    {
        return DB::table('chats')->where('chat_import_id', $import->id)->orderBy('id')->get();
    }
}
