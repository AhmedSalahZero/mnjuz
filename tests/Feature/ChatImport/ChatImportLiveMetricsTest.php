<?php

namespace Tests\Feature\ChatImport;

use App\Models\ChatImport;
use App\Services\AgentPerformanceService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * السجلّ المستورد لم يحدث على هذه المنصّة: لا يدخل مقاييس الفريق ولا نافذة
 * الأربع والعشرين ساعة.
 */
class ChatImportLiveMetricsTest extends ChatImportTestCase
{
    private function chat(int $contactId, string $type, string $at, ?int $userId = null, ?int $importId = null): void
    {
        DB::table('chats')->insert([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'contact_id' => $contactId,
            'user_id' => $userId,
            'type' => $type,
            'metadata' => json_encode(['type' => 'text', 'text' => ['body' => 'x']]),
            'status' => 'delivered',
            'is_read' => 1,
            'created_at' => $at,
            'chat_import_id' => $importId,
        ]);
    }

    public function test_an_unanswered_imported_message_is_not_a_response_time(): void
    {
        $import = $this->runImport($this->makeImport($this->csv([
            ['966501110001', '', now()->subDays(3)->format('Y-m-d H:i:s'), 'in', 'unanswered on the old platform'],
        ])));
        $this->assertSame(ChatImport::COMPLETED, $import->status);
        $contactId = $this->contactByPhone('+966501110001')->id;

        // أوّل ردّ حقيقي من الموظّف اليوم.
        $this->chat($contactId, 'outbound', now()->subHour()->utc()->toDateTimeString(), $this->user->id);

        $metrics = (new AgentPerformanceService($this->organization->id))->metrics(Carbon::now()->subWeek(), Carbon::now());
        $me = collect($metrics['agents'])->firstWhere('user_id', $this->user->id);

        $this->assertNull($me['avg_first_response_seconds'], 'three days of "waiting" on another platform is not this agent\'s response time');
        $this->assertSame(1, $me['messages_sent']);
    }

    public function test_the_last_inbound_repair_command_ignores_imported_messages(): void
    {
        $import = $this->runImport($this->makeImport($this->csv([
            ['966501110001', '', now()->subHours(2)->format('Y-m-d H:i:s'), 'in', 'imported'],
        ])));
        $contactId = $this->contactByPhone('+966501110001')->id;
        $this->chat($contactId, 'inbound', '2026-01-01 10:00:00');

        $this->artisan('contacts:update-last-inbound-chat-created-at')->assertSuccessful();

        $this->assertSame('2026-01-01 10:00:00', DB::table('contacts')->where('id', $contactId)->value('last_inbound_chat_created_at'));
        $this->assertSame(1, $import->imported_messages);
    }

    public function test_the_prune_command_removes_old_files_only(): void
    {
        $old = $this->makeImport($this->csv([['966501110001', '', '2026-05-23 10:00:00', 'in', 'a']]), ['status' => ChatImport::FAILED]);
        $fresh = $this->makeImport($this->csv([['966501110001', '', '2026-05-23 10:00:00', 'in', 'a']]), ['status' => ChatImport::FAILED]);
        $active = $this->makeImport($this->csv([['966501110001', '', '2026-05-23 10:00:00', 'in', 'a']]), ['status' => ChatImport::IMPORTING]);
        DB::table('chat_imports')->whereIn('id', [$old->id, $active->id])->update(['updated_at' => now()->subDays(30)]);

        $this->artisan('chat-imports:prune')->assertSuccessful();

        $this->assertNull($old->refresh()->file_path);
        $this->assertNotNull($fresh->refresh()->file_path);
        $this->assertNotNull($active->refresh()->file_path, 'a running import keeps its file');
    }
}
