<?php

namespace Tests\Feature\ChatImport;

use App\Jobs\ImportChatHistoryJob;
use App\Models\Chat;
use App\Models\ChatImport;
use App\Models\Contact;
use App\Services\ChatService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * الكتابة: من ملف حقيقي إلى chats وchat_logs وجهات الاتصال والتذاكر.
 */
class ChatImportJobTest extends ChatImportTestCase
{
    private function sampleCsv(): string
    {
        return $this->csv([
            ['966501110001', 'سارة', '2026-05-23T19:29:38+03:00', 'in', 'السلام عليكم'],
            ['966501110001', 'سارة', '2026-05-23T19:30:05+03:00', 'out', "أهلاً سارة\nكيف نخدمك؟"],
            ['966501110002', '.', '2026-05-24 10:00:00', 'in', '[image] صورة الوصفة'],
        ]);
    }

    public function test_it_imports_messages_with_their_thread_index(): void
    {
        $import = $this->runImport($this->makeImport($this->sampleCsv()));

        $this->assertSame(ChatImport::COMPLETED, $import->status);
        $this->assertSame(3, $import->total_rows);
        $this->assertSame(3, $import->processed_rows);
        $this->assertSame(3, $import->imported_messages);
        $this->assertSame(0, $import->duplicate_rows);
        $this->assertSame(2, $import->contacts_created);
        $this->assertNull($import->file_path, 'the uploaded file is removed when the import completes');

        $chats = $this->importedChats($import);
        $this->assertCount(3, $chats);

        // 19:29:38+03:00 = 16:29:38 UTC؛ وبلا إزاحة يُقرأ بتوقيت المنشأة (الرياض).
        $this->assertSame('2026-05-23 16:29:38', $chats[0]->created_at);
        $this->assertSame('2026-05-24 07:00:00', $chats[2]->created_at);

        foreach ($chats as $chat) {
            $this->assertNull($chat->user_id, 'imported messages were not sent by a team member');
            $this->assertSame(1, (int) $chat->is_read, 'history must not show as unread');
            $this->assertStringStartsWith('imp:', $chat->wam_id);

            $log = DB::table('chat_logs')->where('entity_type', 'chat')->where('entity_id', $chat->id)->first();
            $this->assertNotNull($log, 'a message without a chat_logs row is invisible in the thread');
            $this->assertSame($chat->created_at, $log->created_at);
            $this->assertSame((int) $chat->contact_id, (int) $log->contact_id);
        }

        $this->assertSame(['inbound', 'outbound', 'inbound'], $chats->pluck('type')->all());
        $this->assertSame(['delivered', 'sent', 'delivered'], $chats->pluck('status')->all());

        $metadata = json_decode($chats[2]->metadata, true);
        $this->assertSame('text', $metadata['type']);
        $this->assertSame('[image] صورة الوصفة', $metadata['text']['body']);
        $this->assertSame('image', $metadata['imported']['original_type']);
        $this->assertSame($import->uuid, $metadata['imported']['import']);
        $this->assertStringContainsString('السلام', $chats[0]->metadata, 'Arabic is stored unescaped like the rest of chats.metadata');
    }

    public function test_it_creates_contacts_with_names_and_display_format(): void
    {
        $this->runImport($this->makeImport($this->sampleCsv()));

        $sara = $this->contactByPhone('+966501110001');
        $this->assertNotNull($sara);
        $this->assertSame('سارة', $sara->first_name);
        $this->assertNotEmpty($sara->formatted_phone);
        $this->assertNotEmpty($sara->uuid);
        $this->assertSame($this->user->id, (int) $sara->created_by);

        // «.» ليس اسماً. (الـ accessor يُرجع '' للفارغ، فنقرأ العمود نفسه.)
        $this->assertNull(DB::table('contacts')->where('phone', '+966501110002')->value('first_name'));
    }

    public function test_it_matches_existing_contacts_without_renaming_them(): void
    {
        $existing = Contact::create([
            'organization_id' => $this->organization->id,
            'first_name' => 'Sara (VIP)',
            'phone' => '+966501110001',
            'created_by' => $this->user->id,
        ]);

        $import = $this->runImport($this->makeImport($this->sampleCsv()));

        $this->assertSame(1, $import->contacts_matched);
        $this->assertSame(1, $import->contacts_created);
        $this->assertSame('Sara (VIP)', $existing->refresh()->first_name);
        $this->assertSame(2, DB::table('chats')->where('contact_id', $existing->id)->count());
    }

    public function test_it_matches_a_contact_stored_in_a_legacy_phone_format(): void
    {
        // جهة محفوظة قبل توحيد الصيغة: بلا «+» وبمسافات. الـ observer يطبّعها عند
        // الحفظ، فنكتبها مباشرةً كما هي في القاعدات القديمة.
        $id = DB::table('contacts')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'first_name' => 'Legacy',
            'phone' => '966 50 111 0001',
            'created_by' => $this->user->id,
        ]);

        $import = $this->runImport($this->makeImport($this->sampleCsv()));

        $this->assertSame(1, $import->contacts_matched);
        $this->assertSame(2, DB::table('chats')->where('contact_id', $id)->count());
        $this->assertNull($this->contactByPhone('+966501110001'), 'no duplicate contact is created');
    }

    public function test_latest_chat_moves_forward_but_never_backwards(): void
    {
        $recent = Contact::create([
            'organization_id' => $this->organization->id,
            'phone' => '+966501110001',
            'created_by' => $this->user->id,
            'latest_chat_created_at' => '2026-10-01 12:00:00',
            'last_inbound_chat_created_at' => '2026-10-01 12:00:00',
        ]);
        $stale = Contact::create([
            'organization_id' => $this->organization->id,
            'phone' => '+966501110002',
            'created_by' => $this->user->id,
            'latest_chat_created_at' => '2026-01-01 00:00:00',
        ]);

        $this->runImport($this->makeImport($this->sampleCsv()));

        $this->assertSame('2026-10-01 12:00:00', DB::table('contacts')->where('id', $recent->id)->value('latest_chat_created_at'));
        $this->assertSame('2026-05-24 07:00:00', DB::table('contacts')->where('id', $stale->id)->value('latest_chat_created_at'));
    }

    public function test_it_does_not_open_a_fake_24_hour_window(): void
    {
        $import = $this->runImport($this->makeImport($this->csv([
            ['966501110003', 'Ali', now()->subHour()->toIso8601String(), 'in', 'رسالة قبل ساعة'],
        ])));

        $this->assertSame(ChatImport::COMPLETED, $import->status);
        $this->assertNull($this->contactByPhone('+966501110003')->last_inbound_chat_created_at);
    }

    public function test_contacts_without_tickets_get_a_closed_one(): void
    {
        $withTicket = Contact::create([
            'organization_id' => $this->organization->id,
            'phone' => '+966501110001',
            'created_by' => $this->user->id,
        ]);
        DB::table('chat_tickets')->insert([
            'contact_id' => $withTicket->id,
            'assigned_to' => $this->user->id,
            'status' => 'open',
            'is_latest' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->runImport($this->makeImport($this->sampleCsv()));

        $this->assertSame(1, DB::table('chat_tickets')->where('contact_id', $withTicket->id)->count(), 'existing tickets are left alone');
        $this->assertSame('open', DB::table('chat_tickets')->where('contact_id', $withTicket->id)->value('status'));

        $newContact = $this->contactByPhone('+966501110002');
        $ticket = DB::table('chat_tickets')->where('contact_id', $newContact->id)->first();
        $this->assertNotNull($ticket);
        $this->assertSame('closed', $ticket->status);
        $this->assertSame(1, (int) $ticket->is_latest);
        $this->assertNull($ticket->assigned_to);
        $this->assertSame('2026-05-24 07:00:00', $ticket->created_at);
    }

    public function test_reimporting_the_same_file_adds_nothing(): void
    {
        $first = $this->runImport($this->makeImport($this->sampleCsv()));
        $second = $this->runImport($this->makeImport($this->sampleCsv()));

        $this->assertSame(3, $first->imported_messages);
        $this->assertSame(ChatImport::COMPLETED, $second->status);
        $this->assertSame(0, $second->imported_messages);
        $this->assertSame(3, $second->duplicate_rows);
        $this->assertSame(3, DB::table('chats')->where('organization_id', $this->organization->id)->count());
    }

    public function test_messages_the_user_deleted_are_not_brought_back(): void
    {
        $first = $this->runImport($this->makeImport($this->sampleCsv()));
        // حذف المستخدم محادثةً (ChatService::clearContactChat) يترك wam_id كما هو.
        DB::table('chats')->where('chat_import_id', $first->id)->update(['deleted_at' => now()]);

        $second = $this->runImport($this->makeImport($this->sampleCsv()));

        $this->assertSame(ChatImport::COMPLETED, $second->status, 'the raw wam_id unique index must not fail the batch');
        $this->assertSame(0, $second->imported_messages);
        $this->assertSame(3, $second->duplicate_rows);
    }

    public function test_identical_messages_in_the_same_second_are_all_kept(): void
    {
        $import = $this->runImport($this->makeImport($this->csv([
            ['966501110001', '', '2026-05-23T19:29:38+03:00', 'in', '[image]'],
            ['966501110001', '', '2026-05-23T19:29:38+03:00', 'in', '[image]'],
            ['966501110001', '', '2026-05-23T19:29:38+03:00', 'in', '[image]'],
        ])));

        $this->assertSame(3, $import->imported_messages);
        $this->assertSame(0, $import->duplicate_rows);
    }

    public function test_the_same_file_in_two_organizations_is_imported_twice(): void
    {
        [$otherUser, $otherOrganization] = $this->makeOrganization();

        $mine = $this->runImport($this->makeImport($this->sampleCsv()));
        $theirs = $this->runImport($this->makeImport($this->sampleCsv(), [], $otherOrganization, $otherUser));

        $this->assertSame(3, $mine->imported_messages);
        $this->assertSame(3, $theirs->imported_messages);
        $this->assertSame(3, DB::table('chats')->where('organization_id', $otherOrganization->id)->count());
        $this->assertNotSame(
            $this->contactByPhone('+966501110001')->id,
            $this->contactByPhone('+966501110001', $otherOrganization)->id
        );
    }

    public function test_many_small_slices_give_the_same_result(): void
    {
        config(['chat_import.batch_rows' => 2, 'chat_import.slice_rows' => 2]);

        $rows = [];
        for ($i = 0; $i < 9; $i++) {
            $rows[] = ['96650111000' . ($i % 3), 'N' . $i, '2026-05-23 10:0' . $i . ':00', $i % 2 ? 'out' : 'in', 'msg ' . $i];
        }
        // سلسلة متطابقة على حدّ الدفعة: لا تُقطع بين شريحتين.
        $rows[] = ['966501110009', '', '2026-05-23 11:00:00', 'in', '[sticker]'];
        $rows[] = ['966501110009', '', '2026-05-23 11:00:00', 'in', '[sticker]'];
        $rows[] = ['966501110009', '', '2026-05-23 11:00:00', 'in', '[sticker]'];

        $import = $this->runImport($this->makeImport($this->csv($rows)));

        $this->assertSame(ChatImport::COMPLETED, $import->status);
        $this->assertSame(12, $import->imported_messages);
        $this->assertSame(12, $import->processed_rows);
        $this->assertSame(4, $import->contacts_created);
        $this->assertSame(12, DB::table('chat_logs')->whereIn('entity_id', $this->importedChats($import)->pluck('id'))->count());
    }

    public function test_a_resumed_slice_continues_from_its_cursor(): void
    {
        config(['chat_import.batch_rows' => 2, 'chat_import.slice_rows' => 2]);

        $rows = [];
        for ($i = 0; $i < 6; $i++) {
            $rows[] = ['966501110001', '', '2026-05-23 10:0' . $i . ':00', 'in', 'msg ' . $i];
        }
        $import = $this->makeImport($this->csv($rows), ['status' => ChatImport::QUEUED, 'total_rows' => 6]);

        // شريحة واحدة ثم توقّف العامل: لا نرسل الشريحة التالية.
        $import->forceFill(['status' => ChatImport::QUEUED])->save();
        \Illuminate\Support\Facades\Queue::fake();
        (new ImportChatHistoryJob($import->id))->handle();
        \Illuminate\Support\Facades\Queue::assertPushed(ImportChatHistoryJob::class, 1);

        $import->refresh();
        $this->assertSame(ChatImport::IMPORTING, $import->status);
        $this->assertSame(2, $import->processed_rows);
        $this->assertGreaterThan(0, $import->cursor_offset);

        // الشريحتان التاليتان تكملان من الموضع المحفوظ.
        (new ImportChatHistoryJob($import->id))->handle();
        (new ImportChatHistoryJob($import->id))->handle();
        (new ImportChatHistoryJob($import->id))->handle();

        $import->refresh();
        $this->assertSame(ChatImport::COMPLETED, $import->status);
        $this->assertSame(6, $import->imported_messages);
        $this->assertSame(
            ['msg 0', 'msg 1', 'msg 2', 'msg 3', 'msg 4', 'msg 5'],
            $this->importedChats($import)->map(fn ($c) => json_decode($c->metadata, true)['text']['body'])->all()
        );
    }

    public function test_replaying_a_slice_after_a_crash_is_harmless(): void
    {
        $import = $this->makeImport($this->sampleCsv(), ['status' => ChatImport::QUEUED, 'total_rows' => 3]);
        (new ImportChatHistoryJob($import->id))->handle();

        // عامل سقط بعد الكتابة وقبل حفظ الحالة: الموضع والحالة يعودان للبداية.
        $import->refresh()->forceFill([
            'status' => ChatImport::IMPORTING,
            'cursor_offset' => 0,
            'processed_rows' => 0,
            'imported_messages' => 0,
            'file_path' => $this->makeImport($this->sampleCsv())->file_path,
        ])->save();

        (new ImportChatHistoryJob($import->id))->handle();

        $this->assertSame(3, DB::table('chats')->where('organization_id', $this->organization->id)->count());
        $this->assertSame(3, $import->refresh()->duplicate_rows);
    }

    public function test_cancelling_stops_between_batches(): void
    {
        config(['chat_import.batch_rows' => 1]);

        $import = $this->makeImport($this->sampleCsv(), ['status' => ChatImport::CANCELLING, 'total_rows' => 3]);
        (new ImportChatHistoryJob($import->id))->handle();

        $import->refresh();
        $this->assertSame(ChatImport::CANCELLED, $import->status);
        $this->assertSame(0, $import->imported_messages);
        $this->assertNotNull($import->file_path, 'the file stays so the import can be resumed');
    }

    public function test_a_failure_marks_the_import_failed_and_it_can_resume(): void
    {
        $import = $this->makeImport($this->sampleCsv(), [
            'status' => ChatImport::IMPORTING,
            'total_rows' => 3,
            'started_at' => now(),
        ]);

        (new ImportChatHistoryJob($import->id))->failed(new \RuntimeException('connection lost'));

        $import->refresh();
        $this->assertSame(ChatImport::FAILED, $import->status);
        $this->assertNotEmpty($import->error_message);
        $this->assertTrue($import->canResume());
        $this->assertFalse($import->canRollback(), 'nothing was written yet');
    }

    public function test_messages_already_received_live_are_not_duplicated(): void
    {
        $contact = Contact::create([
            'organization_id' => $this->organization->id,
            'phone' => '+966501110001',
            'created_by' => $this->user->id,
        ]);
        // نفس الرسالة وصلت حيّةً عبر webhook بعد ربط الرقم، بفارق ثانيتين.
        DB::table('chats')->insert([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'wam_id' => 'wamid.LIVE1',
            'contact_id' => $contact->id,
            'type' => 'inbound',
            'metadata' => json_encode(['type' => 'text', 'text' => ['body' => 'السلام عليكم']]),
            'status' => 'delivered',
            'is_read' => 1,
            'created_at' => '2026-05-23 16:29:40',
        ]);

        $import = $this->runImport($this->makeImport($this->sampleCsv()));

        $this->assertSame(2, $import->imported_messages);
        $this->assertSame(1, $import->duplicate_rows);
    }

    public function test_limits_are_checked_again_before_writing(): void
    {
        $import = $this->makeImport($this->sampleCsv(), [
            'status' => ChatImport::QUEUED,
            'total_rows' => 3,
            'new_messages_estimate' => 3,
            'new_contacts_estimate' => 2,
        ]);
        // بين الفحص والكتابة وصلت رسائل حيّة فامتلأت الباقة.
        $this->setLimits(['message_limit' => 2]);

        (new ImportChatHistoryJob($import->id))->handle();

        $import->refresh();
        $this->assertSame(ChatImport::FAILED, $import->status);
        $this->assertStringContainsString('limit', $import->error_message);
        $this->assertSame(0, DB::table('chats')->count());
    }

    public function test_imported_messages_appear_in_the_thread_in_order(): void
    {
        $import = $this->runImport($this->makeImport($this->csv([
            ['966501110001', '', '2026-05-23 10:00:00', 'in', 'first'],
            ['966501110001', '', '2026-05-23 10:00:00', 'out', 'second'],
            ['966501110001', '', '2026-05-23 10:00:00', 'in', 'third'],
        ])));

        $contact = $this->contactByPhone('+966501110001');
        $messages = (new ChatService($this->organization->id))->getChatMessages($contact->id);

        $bodies = collect($messages['messages'])->map(fn ($m) => json_decode($m[0]['value']->metadata, true)['text']['body'])->all();
        $this->assertSame(['first', 'second', 'third'], $bodies, 'same-second messages keep their file order');
        $this->assertInstanceOf(Chat::class, $messages['messages'][0][0]['value']);
        $this->assertSame(3, $import->imported_messages);
    }
}
