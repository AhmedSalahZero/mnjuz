<?php

namespace Tests\Feature\ChatImport;

use App\Jobs\RollbackChatImportJob;
use App\Models\ChatImport;
use App\Models\Contact;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * التراجع عن استيراد: يحذف رسائله وحدها، ثم ما أنشأه ولم يعد له أثر.
 */
class ChatImportRollbackTest extends ChatImportTestCase
{
    private function rows(): string
    {
        return $this->csv([
            ['966501110001', 'Existing', '2026-05-23 10:00:00', 'in', 'old message'],
            ['966501110002', 'New one', '2026-05-23 11:00:00', 'in', 'hello'],
            ['966501110003', 'New two', '2026-05-23 12:00:00', 'out', 'hi there'],
        ]);
    }

    private function rollback(ChatImport $import): ChatImport
    {
        $import->forceFill(['status' => ChatImport::ROLLING_BACK])->save();
        (new RollbackChatImportJob($import->id))->handle();

        return $import->refresh();
    }

    public function test_it_removes_only_the_imported_messages(): void
    {
        $existing = Contact::create([
            'organization_id' => $this->organization->id,
            'phone' => '+966501110001',
            'created_by' => $this->user->id,
            'latest_chat_created_at' => '2026-04-01 09:00:00',
        ]);
        $liveId = DB::table('chats')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'wam_id' => 'wamid.LIVE',
            'contact_id' => $existing->id,
            'type' => 'inbound',
            'metadata' => json_encode(['type' => 'text', 'text' => ['body' => 'live']]),
            'status' => 'delivered',
            'is_read' => 1,
            'created_at' => '2026-04-01 09:00:00',
        ]);
        DB::table('chat_logs')->insert([
            'contact_id' => $existing->id, 'entity_type' => 'chat', 'entity_id' => $liveId,
            'created_at' => '2026-04-01 09:00:00', 'updated_at' => '2026-04-01 09:00:00',
        ]);

        $import = $this->runImport($this->makeImport($this->rows()));
        $this->assertSame('2026-05-23 07:00:00', DB::table('contacts')->where('id', $existing->id)->value('latest_chat_created_at'));

        $import = $this->rollback($import);

        $this->assertSame(ChatImport::ROLLED_BACK, $import->status);
        $this->assertSame(3, $import->rolled_back_messages);

        $imported = $this->importedChats($import);
        $this->assertTrue($imported->every(fn ($c) => $c->deleted_at !== null && $c->wam_id === null));
        $this->assertSame(0, DB::table('chat_logs')->whereIn('entity_id', $imported->pluck('id'))->where('entity_type', 'chat')->whereNull('deleted_at')->count());

        $this->assertNull(DB::table('chats')->where('id', $liveId)->value('deleted_at'), 'live messages stay');
        $this->assertSame('2026-04-01 09:00:00', DB::table('contacts')->where('id', $existing->id)->value('latest_chat_created_at'));
        $this->assertNull($existing->refresh()->deleted_at, 'a contact the import did not create stays');
    }

    public function test_it_removes_contacts_and_tickets_the_import_created(): void
    {
        $import = $this->runImport($this->makeImport($this->rows()));
        $created = Contact::where('organization_id', $this->organization->id)->pluck('id');
        $this->assertCount(3, $created);
        $this->assertSame(3, DB::table('chat_tickets')->whereIn('contact_id', $created)->count());

        $this->rollback($import);

        $this->assertSame(0, Contact::where('organization_id', $this->organization->id)->count());
        $this->assertSame(0, DB::table('chat_tickets')->whereIn('contact_id', $created)->count());
    }

    public function test_a_contact_who_wrote_after_the_import_is_kept(): void
    {
        $import = $this->runImport($this->makeImport($this->rows()));
        $contact = $this->contactByPhone('+966501110002');

        // رسالة حقيقية بعد الاستيراد أعادت فتح التذكرة.
        $liveId = DB::table('chats')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'wam_id' => 'wamid.AFTER',
            'contact_id' => $contact->id,
            'type' => 'inbound',
            'metadata' => json_encode(['type' => 'text', 'text' => ['body' => 'I am real']]),
            'status' => 'delivered',
            'is_read' => 0,
            'created_at' => '2026-11-01 08:00:00',
        ]);
        DB::table('chat_logs')->insert([
            'contact_id' => $contact->id, 'entity_type' => 'chat', 'entity_id' => $liveId,
            'created_at' => '2026-11-01 08:00:00', 'updated_at' => '2026-11-01 08:00:00',
        ]);
        DB::table('chat_tickets')->where('contact_id', $contact->id)->update(['status' => 'open']);

        $this->rollback($import);

        $this->assertNull($contact->refresh()->deleted_at);
        $this->assertSame('open', DB::table('chat_tickets')->where('contact_id', $contact->id)->value('status'));
        $this->assertSame('2026-11-01 08:00:00', DB::table('contacts')->where('id', $contact->id)->value('latest_chat_created_at'));
    }

    public function test_the_same_file_can_be_imported_again_after_rollback(): void
    {
        $first = $this->rollback($this->runImport($this->makeImport($this->rows())));
        $this->assertSame(ChatImport::ROLLED_BACK, $first->status);

        $second = $this->runImport($this->makeImport($this->rows()));

        $this->assertSame(ChatImport::COMPLETED, $second->status);
        $this->assertSame(3, $second->imported_messages, 'the cleared fingerprints no longer block the file');
    }

    public function test_rollback_runs_in_slices(): void
    {
        config(['chat_import.slice_seconds' => 0]);

        $import = $this->runImport($this->makeImport($this->rows()));
        config(['chat_import.slice_seconds' => 0]);

        $import = $this->rollback($import);

        $this->assertSame(ChatImport::ROLLED_BACK, $import->status);
        $this->assertSame(3, $import->rolled_back_messages);
    }

    public function test_a_failed_rollback_can_be_retried(): void
    {
        $import = $this->runImport($this->makeImport($this->rows()));
        $import->forceFill(['status' => ChatImport::ROLLING_BACK])->save();

        (new RollbackChatImportJob($import->id))->failed(new \RuntimeException('deadlock'));

        $import->refresh();
        $this->assertSame(ChatImport::FAILED, $import->status);
        $this->assertTrue($import->canRollback());
        $this->assertFalse($import->canResume(), 'a completed import has nothing left to resume');
    }
}
