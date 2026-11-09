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

    /** @dataProvider teamWork */
    public function test_a_contact_the_team_worked_on_is_kept(string $work): void
    {
        $import = $this->runImport($this->makeImport($this->rows()));
        $contact = $this->contactByPhone('+966501110002');

        match ($work) {
            'note' => DB::table('chat_notes')->insert([
                'uuid' => (string) Str::uuid(), 'contact_id' => $contact->id, 'content' => 'called her',
                'created_by' => $this->user->id, 'created_at' => now(), 'updated_at' => now(),
            ]),
            'ticket_log' => DB::table('chat_logs')->insert([
                'contact_id' => $contact->id, 'entity_type' => 'ticket', 'entity_id' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]),
            'group' => DB::table('contact_contact_group')->insert([
                'contact_id' => $contact->id, 'created_at' => now(), 'updated_at' => now(),
                'contact_group_id' => DB::table('contact_groups')->insertGetId([
                    'uuid' => (string) Str::uuid(), 'organization_id' => $this->organization->id, 'name' => 'VIP',
                    'created_by' => $this->user->id, 'created_at' => now(), 'updated_at' => now(),
                ]),
            ]),
            'category' => DB::table('contact_category_contact')->insert([
                'contact_id' => $contact->id, 'created_at' => now(), 'updated_at' => now(),
                'contact_category_id' => DB::table('contact_categories')->insertGetId([
                    'uuid' => (string) Str::uuid(), 'organization_id' => $this->organization->id, 'name' => 'VIP',
                    'created_at' => now(), 'updated_at' => now(),
                ]),
            ]),
            'campaign' => DB::table('campaign_logs')->insert([
                'campaign_id' => 1, 'contact_id' => $contact->id, 'status' => 'failed', 'created_at' => now(), 'updated_at' => now(),
            ]),
        };

        $import = $this->rollback($import);

        $this->assertSame(ChatImport::ROLLED_BACK, $import->status);
        $this->assertNull($contact->refresh()->deleted_at, "a contact with team work ($work) stays");
        $this->assertNull(DB::table('contacts')->where('id', $contact->id)->value('latest_chat_created_at'), 'it has no messages left');
        $this->assertSame(1, DB::table('chat_tickets')->where('contact_id', $contact->id)->count());
        $this->assertSame(2, Contact::onlyTrashed()->where('organization_id', $this->organization->id)->count(), 'the untouched contacts still go');
    }

    public static function teamWork(): array
    {
        return [['note'], ['ticket_log'], ['group'], ['category'], ['campaign']];
    }

    public function test_rolling_back_one_import_leaves_the_other(): void
    {
        $first = $this->runImport($this->makeImport($this->rows()));

        $second = $this->runImport($this->makeImport($this->csv([
            ['966501110002', 'New one', '2026-05-23 11:00:00', 'in', 'hello'],
            ['966501110004', 'Fourth', '2026-05-23 13:00:00', 'in', 'only in the second file'],
        ])));
        $this->assertSame(1, $second->imported_messages);
        $this->assertSame(1, $second->duplicate_rows);

        $second = $this->rollback($second);

        $this->assertSame(1, $second->rolled_back_messages);
        $this->assertSame(3, $this->importedChats($first)->whereNull('deleted_at')->count());
        $this->assertNull($this->contactByPhone('+966501110002')->deleted_at);
        $this->assertNull($this->contactByPhone('+966501110004'), 'the contact only the second file created goes');
    }

    public function test_skipped_rows_do_not_change_what_is_removed(): void
    {
        $import = $this->runImport($this->makeImport($this->csv([
            ['966501110001', 'A', '2026-05-23 10:00:00', 'in', 'one'],
            ['96600647244', 'B', '2026-05-23 10:01:00', 'in', 'skipped'],
            ['966501110002', 'C', '2026-05-23 10:02:00', 'in', 'two'],
        ]), ['default_country' => 'SA']));
        $this->assertSame(2, $import->imported_messages);

        $import = $this->rollback($import);

        $this->assertSame(ChatImport::ROLLED_BACK, $import->status);
        $this->assertSame(2, $import->rolled_back_messages);
        $this->assertSame(0, $this->importedChats($import)->whereNull('deleted_at')->count());
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
