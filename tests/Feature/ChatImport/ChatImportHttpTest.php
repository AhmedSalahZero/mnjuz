<?php

namespace Tests\Feature\ChatImport;

use App\Jobs\ImportChatHistoryJob;
use App\Jobs\RollbackChatImportJob;
use App\Jobs\ValidateChatImportJob;
use App\Models\ChatImport;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * المسارات: الرفع المجزّأ، الحالة، التقرير، والإلغاء والاستئناف والتراجع.
 */
class ChatImportHttpTest extends ChatImportTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        session(['current_organization' => $this->organization->id]);
        $this->actingAs($this->user);
    }

    private function validCsv(): string
    {
        return $this->csv([
            ['966501110001', 'Sara', '2026-05-23T19:29:38+03:00', 'in', 'hello'],
            ['966501110001', 'Sara', '2026-05-23T19:30:00+03:00', 'out', 'hi'],
        ]);
    }

    /** @return \Illuminate\Testing\TestResponse[] */
    private function uploadInChunks(string $contents, string $uploadId, int $chunkBytes = 40, array $extra = []): array
    {
        $chunks = str_split($contents, $chunkBytes);
        $responses = [];

        foreach ($chunks as $index => $chunk) {
            $responses[] = $this->post('/settings/chat-imports/upload/chunk', array_merge([
                'upload_id' => $uploadId,
                'index' => $index,
                'total' => count($chunks),
                'chunk' => UploadedFile::fake()->createWithContent('chunk', $chunk),
                'file_name' => 'history.csv',
                'file_size' => strlen($contents),
            ], $extra), ['Accept' => 'application/json']);
        }

        return $responses;
    }

    public function test_the_page_renders_for_the_owner(): void
    {
        $this->makeImport($this->validCsv(), ['status' => ChatImport::COMPLETED]);

        $this->get('/settings/chat-imports')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('User/Settings/ChatImport')
                ->has('imports', 1)
                ->has('latest')
                ->has('countries')
                ->where('limits.max_file_mb', config('chat_import.max_file_mb')));
    }

    public function test_the_template_is_a_utf8_csv_with_the_expected_columns(): void
    {
        $response = $this->get('/settings/chat-imports/template')->assertOk();

        $body = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);
        $this->assertStringStartsWith("phone,name,sent_at,direction,text\n", substr($body, 3));
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('chat_history_template.csv', $response->headers->get('Content-Disposition'));

        // القالب نفسه يجتاز الفحص.
        $import = $this->makeImport(substr($body, 3));
        Queue::fake();
        (new ValidateChatImportJob($import->id))->handle();
        $this->assertSame(ChatImport::QUEUED, $import->refresh()->status);
    }

    public function test_a_chunked_upload_creates_the_import_and_queues_validation(): void
    {
        $responses = $this->uploadInChunks($this->validCsv(), 'up-1', 40, ['default_country' => 'sa']);

        foreach (array_slice($responses, 0, -1) as $response) {
            $response->assertOk()->assertJson(['completed' => false]);
        }

        $last = end($responses);
        $last->assertCreated()->assertJsonPath('import.status', ChatImport::PENDING_VALIDATION);

        $import = ChatImport::firstOrFail();
        $this->assertSame($this->organization->id, (int) $import->organization_id);
        $this->assertSame('SA', $import->default_country);
        $this->assertSame('Asia/Riyadh', $import->timezone);
        $this->assertSame('history.csv', $import->original_name);
        $this->assertSame($this->validCsv(), Storage::disk('local')->get($import->file_path));

        Queue::assertPushedOn(config('chat_import.queue'), ValidateChatImportJob::class);
    }

    public function test_a_wrong_header_is_rejected_immediately(): void
    {
        $responses = $this->uploadInChunks("mobile,body\n966501110001,hi\n", 'up-2', 1000);

        end($responses)->assertStatus(422)->assertJsonFragment(['success' => false]);
        $this->assertSame(0, ChatImport::count());
        Queue::assertNothingPushed();
    }

    public function test_a_non_utf8_file_is_rejected_immediately(): void
    {
        $windows1256 = "phone,name,sent_at,direction,text\n966501110001,\xC3\xCD\xE3\xCF,2026-05-23 10:00:00,in,\xE3\xD1\xCD\xC8\xC7\n";

        $responses = $this->uploadInChunks($windows1256, 'up-3', 1000);

        end($responses)->assertStatus(422)->assertJsonPath('message', __('The file is not UTF-8 encoded. In Excel choose "Save as" then "CSV UTF-8 (Comma delimited)".'));
    }

    public function test_an_oversized_file_is_rejected_on_the_first_chunk(): void
    {
        config(['chat_import.max_file_mb' => 1]);

        $this->post('/settings/chat-imports/upload/chunk', [
            'upload_id' => 'big',
            'index' => 0,
            'total' => 3,
            'chunk' => UploadedFile::fake()->createWithContent('chunk', 'phone'),
            'file_name' => 'history.csv',
            'file_size' => 2 * 1024 * 1024,
        ], ['Accept' => 'application/json'])->assertStatus(422);

        $this->assertSame(0, ChatImport::count());
    }

    public function test_only_csv_files_are_accepted(): void
    {
        $this->post('/settings/chat-imports/upload/chunk', [
            'upload_id' => 'xlsx',
            'index' => 0,
            'total' => 1,
            'chunk' => UploadedFile::fake()->createWithContent('chunk', 'x'),
            'file_name' => 'history.xlsx',
            'file_size' => 1,
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonPath('message', __('Only CSV files are accepted.'));
    }

    public function test_a_second_import_waits_for_the_first(): void
    {
        $this->makeImport($this->validCsv(), ['status' => ChatImport::IMPORTING]);

        $responses = $this->uploadInChunks($this->validCsv(), 'up-4', 1000);

        end($responses)->assertStatus(409);
    }

    public function test_resending_the_last_chunk_does_not_create_a_second_import(): void
    {
        $this->uploadInChunks($this->validCsv(), 'up-5', 1000);
        $import = ChatImport::firstOrFail();
        $import->forceFill(['status' => ChatImport::VALIDATING])->save();

        // الشبكة انقطعت بعد وصول القطعة الأخيرة فأعاد المتصفّح إرسالها.
        $responses = $this->uploadInChunks($this->validCsv(), 'up-5', 1000);

        end($responses)->assertOk()->assertJsonPath('import.uuid', $import->uuid);
        $this->assertSame(1, ChatImport::count());
    }

    public function test_status_is_scoped_to_the_organization(): void
    {
        $import = $this->makeImport($this->validCsv(), ['status' => ChatImport::IMPORTING, 'total_rows' => 4, 'processed_rows' => 1]);

        $this->getJson("/settings/chat-imports/{$import->uuid}/status")
            ->assertOk()
            ->assertJsonPath('status', ChatImport::IMPORTING)
            ->assertJsonPath('phase', 'importing')
            ->assertJsonPath('percent', 25)
            ->assertJsonMissingPath('file_path')
            ->assertJsonMissingPath('id');

        [$otherUser, $otherOrganization] = $this->makeOrganization();
        $foreign = $this->makeImport($this->validCsv(), [], $otherOrganization, $otherUser);

        $this->getJson("/settings/chat-imports/{$foreign->uuid}/status")->assertNotFound();
        $this->postJson("/settings/chat-imports/{$foreign->uuid}/rollback")->assertNotFound();
    }

    public function test_the_errors_report_can_be_downloaded(): void
    {
        $import = $this->makeImport($this->csv([['bad', '', 'bad', 'bad', '']]));
        (new ValidateChatImportJob($import->id))->handle();

        $response = $this->get("/settings/chat-imports/{$import->uuid}/errors")->assertOk();
        $this->assertStringContainsString('history-errors.csv', $response->headers->get('Content-Disposition'));

        $clean = $this->makeImport($this->validCsv(), ['status' => ChatImport::COMPLETED]);
        $this->get("/settings/chat-imports/{$clean->uuid}/errors")->assertNotFound();
    }

    public function test_cancel_asks_a_running_job_to_stop_and_cancels_a_waiting_one(): void
    {
        $running = $this->makeImport($this->validCsv(), ['status' => ChatImport::IMPORTING]);
        $this->postJson("/settings/chat-imports/{$running->uuid}/cancel")->assertOk()->assertJsonPath('status', ChatImport::CANCELLING);

        $waiting = $this->makeImport($this->validCsv(), ['status' => ChatImport::PENDING_VALIDATION]);
        $this->postJson("/settings/chat-imports/{$waiting->uuid}/cancel")->assertOk()->assertJsonPath('status', ChatImport::CANCELLED);
        $this->assertNull($waiting->refresh()->file_path);

        $done = $this->makeImport($this->validCsv(), ['status' => ChatImport::COMPLETED]);
        $this->postJson("/settings/chat-imports/{$done->uuid}/cancel")->assertStatus(422);
    }

    public function test_resume_requeues_a_failed_import(): void
    {
        $import = $this->makeImport($this->validCsv(), [
            'status' => ChatImport::FAILED,
            'total_rows' => 2,
            'processed_rows' => 1,
            'started_at' => now(),
            'error_message' => 'boom',
        ]);

        $this->postJson("/settings/chat-imports/{$import->uuid}/resume")
            ->assertOk()
            ->assertJsonPath('status', ChatImport::QUEUED)
            ->assertJsonPath('error_message', null);

        Queue::assertPushed(ImportChatHistoryJob::class, fn ($job) => $job->chatImportId === $import->id);

        $invalid = $this->makeImport($this->validCsv(), ['status' => ChatImport::INVALID]);
        $this->postJson("/settings/chat-imports/{$invalid->uuid}/resume")->assertStatus(422);
    }

    public function test_rollback_is_queued_for_an_import_with_messages(): void
    {
        $import = $this->makeImport($this->validCsv(), ['status' => ChatImport::COMPLETED, 'imported_messages' => 2]);

        $this->postJson("/settings/chat-imports/{$import->uuid}/rollback")
            ->assertOk()
            ->assertJsonPath('status', ChatImport::ROLLING_BACK);

        Queue::assertPushed(RollbackChatImportJob::class);

        $empty = $this->makeImport($this->validCsv(), ['status' => ChatImport::COMPLETED, 'imported_messages' => 0]);
        $this->postJson("/settings/chat-imports/{$empty->uuid}/rollback")->assertStatus(422);
    }

    public function test_agents_cannot_reach_the_import(): void
    {
        $agent = User::factory()->create(['role' => 'user']);
        Team::factory()->create([
            'user_id' => $agent->id,
            'organization_id' => $this->organization->id,
            'role' => 'agent',
            'created_by' => $this->user->id,
        ]);
        $import = $this->makeImport($this->validCsv());

        $this->actingAs($agent);

        $this->get('/settings/chat-imports')->assertForbidden();
        $this->getJson("/settings/chat-imports/{$import->uuid}/status")->assertForbidden();
        $this->post('/settings/chat-imports/upload/chunk', [], ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_managers_can_import(): void
    {
        $manager = User::factory()->create(['role' => 'user']);
        Team::factory()->create([
            'user_id' => $manager->id,
            'organization_id' => $this->organization->id,
            'role' => 'manager',
            'created_by' => $this->user->id,
        ]);

        $this->actingAs($manager)->get('/settings/chat-imports')->assertOk();
    }

    public function test_discarding_an_upload_removes_its_chunks(): void
    {
        $this->post('/settings/chat-imports/upload/chunk', [
            'upload_id' => 'gone',
            'index' => 0,
            'total' => 2,
            'chunk' => UploadedFile::fake()->createWithContent('chunk', 'phone,'),
            'file_name' => 'history.csv',
            'file_size' => 12,
        ], ['Accept' => 'application/json'])->assertOk();

        $directory = \App\Services\Chat\ChunkedUploadService::directoryFor($this->organization->id, $this->user->id, 'ci-gone');
        $this->assertTrue(Storage::disk('local')->exists($directory));

        $this->deleteJson('/settings/chat-imports/upload/chunk', ['upload_id' => 'gone'])->assertOk();
        $this->assertFalse(Storage::disk('local')->exists($directory));
    }
}
