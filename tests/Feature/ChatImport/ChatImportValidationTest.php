<?php

namespace Tests\Feature\ChatImport;

use App\Jobs\ValidateChatImportJob;
use App\Models\ChatImport;
use App\Models\Contact;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/**
 * الفحص الصارم قبل أي كتابة.
 */
class ChatImportValidationTest extends ChatImportTestCase
{
    private function validate(ChatImport $import): ChatImport
    {
        // الاستيراد لا يعمل هنا: نختبر الفحص وحده.
        Queue::fake();
        (new ValidateChatImportJob($import->id))->handle();

        return $import->refresh();
    }

    public function test_a_valid_file_is_queued_with_its_estimates(): void
    {
        Contact::create(['organization_id' => $this->organization->id, 'phone' => '+966501110001', 'created_by' => $this->user->id]);

        $import = $this->validate($this->makeImport($this->csv([
            ['966501110001', 'A', '2026-05-23 10:00:00', 'in', 'hi'],
            ['966501110002', 'B', '2026-05-23 10:01:00', 'out', 'hello'],
            ['966501110002', 'B', '2026-05-23 10:02:00', 'in', 'thanks'],
        ])));

        $this->assertSame(ChatImport::QUEUED, $import->status);
        $this->assertSame(3, $import->total_rows);
        $this->assertSame(3, $import->new_messages_estimate);
        $this->assertSame(1, $import->new_contacts_estimate);
        $this->assertSame(0, $import->error_count);
        $this->assertSame($import->file_size, $import->progress_bytes);
        Queue::assertPushed(\App\Jobs\ImportChatHistoryJob::class);
    }

    public function test_one_bad_row_rejects_the_whole_file_and_writes_nothing(): void
    {
        $import = $this->validate($this->makeImport($this->csv([
            ['966501110001', 'A', '2026-05-23 10:00:00', 'in', 'hi'],
            ['097433977744', 'B', '2026-05-23 10:01:00', 'in', 'bad phone'],
            ['966501110003', 'C', 'yesterday', 'sideways', ''],
        ])));

        $this->assertSame(ChatImport::INVALID, $import->status);
        $this->assertSame(0, DB::table('chats')->count());
        $this->assertSame(0, Contact::count());
        $this->assertNull($import->file_path, 'a rejected file is not kept');
        Queue::assertNotPushed(\App\Jobs\ImportChatHistoryJob::class);

        // كل الأخطاء لا أوّلها: الرقم في الصفّ 3، وثلاثة أعمدة في الصفّ 4.
        $this->assertSame(4, $import->error_count);
        $errors = collect($import->errors);
        $this->assertSame([3, 4, 4, 4], $errors->pluck('row')->all());
        $this->assertSame(['phone', 'sent_at', 'direction', 'text'], $errors->pluck('column')->all());
        $this->assertSame('097433977744', $errors[0]['value']);

        $this->assertNotNull($import->errors_file_path);
        $report = Storage::disk('local')->get($import->errors_file_path);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $report);
        $this->assertSame(5, substr_count(trim($report), "\n") + 1, 'header + one line per error');
    }

    public function test_a_missing_column_is_reported_without_reading_rows(): void
    {
        $import = $this->validate($this->makeImport($this->csv(
            [['966501110001', '2026-05-23 10:00:00', 'hi']],
            ['phone', 'sent_at', 'text']
        )));

        $this->assertSame(ChatImport::INVALID, $import->status);
        $this->assertStringContainsString('direction', $import->error_message);
    }

    public function test_an_empty_file_is_rejected(): void
    {
        $import = $this->validate($this->makeImport("phone,name,sent_at,direction,text\n"));

        $this->assertSame(ChatImport::INVALID, $import->status);
        $this->assertSame(__('The file has no messages.'), $import->error_message);
    }

    public function test_the_default_country_rescues_local_numbers(): void
    {
        $csv = $this->csv([['555770473', 'A', '2026-05-23 10:00:00', 'in', 'hi']]);

        $this->assertSame(ChatImport::INVALID, $this->validate($this->makeImport($csv))->status);

        $import = $this->validate($this->makeImport($csv, ['default_country' => 'SA']));
        $this->assertSame(ChatImport::QUEUED, $import->status);
    }

    /** @return array<int, array<int, string>> صفّ لكل رقم مختلف بعد البادئة. */
    private function rowsFor(string $prefix, int $count): array
    {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = [$prefix . str_pad((string) $i, 4, '0', STR_PAD_LEFT), '', '2026-05-23 10:00:00', 'in', 'hi'];
        }

        return $rows;
    }

    public function test_the_country_is_detected_from_the_file(): void
    {
        $rows = $this->rowsFor('96650111', 20);
        $rows[] = ['503011991', '', '2026-05-23 10:00:00', 'in', 'hi'];

        $import = $this->validate($this->makeImport($this->csv($rows)));

        $this->assertSame(ChatImport::QUEUED, $import->status);
        $this->assertSame(0, $import->error_count);
        $this->assertSame('SA', $import->default_country, 'the import job reads the numbers with it too');
    }

    public function test_a_few_foreign_numbers_do_not_hide_the_main_country(): void
    {
        $rows = array_merge($this->rowsFor('96650111', 18), $this->rowsFor('20100123', 2));
        $rows[] = ['503011991', '', '2026-05-23 10:00:00', 'in', 'hi'];

        $import = $this->validate($this->makeImport($this->csv($rows)));

        $this->assertSame(ChatImport::QUEUED, $import->status);
        $this->assertSame('SA', $import->default_country);
    }

    public function test_a_mixed_file_does_not_guess_a_country(): void
    {
        $rows = array_merge($this->rowsFor('96650111', 12), $this->rowsFor('20100123', 12));
        $rows[] = ['503011991', '', '2026-05-23 10:00:00', 'in', 'hi'];

        $import = $this->validate($this->makeImport($this->csv($rows)));

        $this->assertSame(ChatImport::QUEUED, $import->status);
        $this->assertNull($import->default_country);
        $this->assertSame(1, $import->error_count);
        $this->assertSame('503011991', $import->errors[0]['value']);
    }

    public function test_a_small_file_is_too_little_evidence(): void
    {
        $rows = $this->rowsFor('96650111', 19);
        $rows[] = ['503011991', '', '2026-05-23 10:00:00', 'in', 'hi'];

        $import = $this->validate($this->makeImport($this->csv($rows)));

        $this->assertSame(ChatImport::QUEUED, $import->status);
        $this->assertNull($import->default_country);
        $this->assertSame(1, $import->error_count);
    }

    public function test_a_chosen_country_is_not_replaced(): void
    {
        $rows = $this->rowsFor('96650111', 20);
        $rows[] = ['1001234567', '', '2026-05-23 10:00:00', 'in', 'hi'];

        $import = $this->validate($this->makeImport($this->csv($rows), ['default_country' => 'EG']));

        $this->assertSame(ChatImport::QUEUED, $import->status);
        $this->assertSame('EG', $import->default_country);
    }

    public function test_the_whole_file_is_read_after_detection(): void
    {
        $import = $this->validate($this->makeImport($this->csv($this->rowsFor('96650111', 25))));

        $this->assertSame(25, $import->total_rows);
    }

    public function test_rows_with_bad_numbers_are_skipped_and_reported(): void
    {
        $import = $this->validate($this->makeImport($this->csv([
            ['966501110001', 'A', '2026-05-23 10:00:00', 'in', 'hi'],
            ['96600647244', 'B', '2026-05-23 10:01:00', 'in', 'bad number'],
            ['966501110002', 'C', '2026-05-23 10:02:00', 'out', 'hello'],
        ]), ['default_country' => 'SA']));

        $this->assertSame(ChatImport::QUEUED, $import->status);
        $this->assertSame(3, $import->total_rows);
        $this->assertSame(2, $import->new_messages_estimate);
        $this->assertSame(2, $import->new_contacts_estimate);
        $this->assertSame(1, $import->error_count);
        $this->assertSame(1, $import->toStatusPayload()['skipped_rows']);
        $this->assertNotNull($import->file_path, 'the import job still needs the file');
        Queue::assertPushed(\App\Jobs\ImportChatHistoryJob::class);

        $this->assertSame(3, $import->errors[0]['row']);
        $this->assertStringContainsString('الرقم ناقص', $import->errors[0]['message_ar']);

        $report = Storage::disk('local')->get($import->errors_file_path);
        $this->assertStringContainsString('96600647244', $report);
        $this->assertStringContainsString('too short', $report);
        $this->assertStringContainsString('الرقم ناقص', $report);
    }

    public function test_a_row_with_a_bad_number_and_a_bad_date_is_only_skipped(): void
    {
        $import = $this->validate($this->makeImport($this->csv([
            ['966501110001', 'A', '2026-05-23 10:00:00', 'in', 'hi'],
            ['96600647244', 'B', 'yesterday', 'in', 'bad number'],
        ]), ['default_country' => 'SA']));

        $this->assertSame(ChatImport::QUEUED, $import->status);
        $this->assertSame(1, $import->error_count);
        $this->assertSame('phone', $import->errors[0]['column']);
    }

    public function test_a_bad_date_still_rejects_the_file(): void
    {
        $import = $this->validate($this->makeImport($this->csv([
            ['966501110001', 'A', '2026-05-23 10:00:00', 'in', 'hi'],
            ['96600647244', 'B', '2026-05-23 10:01:00', 'in', 'bad number'],
            ['966501110002', 'C', 'yesterday', 'in', 'bad date'],
        ]), ['default_country' => 'SA']));

        $this->assertSame(ChatImport::INVALID, $import->status);
        $this->assertSame(2, $import->error_count);
        $this->assertSame(0, $import->toStatusPayload()['skipped_rows']);
        $this->assertNull($import->file_path);
        Queue::assertNotPushed(\App\Jobs\ImportChatHistoryJob::class);
    }

    public function test_a_file_with_no_valid_number_is_rejected(): void
    {
        $import = $this->validate($this->makeImport($this->csv([
            ['96600647244', 'A', '2026-05-23 10:00:00', 'in', 'hi'],
            ['9665314916119', 'B', '2026-05-23 10:01:00', 'in', 'hello'],
        ]), ['default_country' => 'SA']));

        $this->assertSame(ChatImport::INVALID, $import->status);
        $this->assertSame(__('No row in the file has a valid phone number.'), $import->error_message);
        $this->assertNull($import->file_path);
    }

    public function test_an_arabic_report_has_one_reason_column(): void
    {
        $import = $this->validate($this->makeImport($this->csv([
            ['966501110001', 'A', '2026-05-23 10:00:00', 'in', 'hi'],
            ['96600647244', 'B', '2026-05-23 10:01:00', 'in', 'bad number'],
        ]), ['default_country' => 'SA', 'locale' => 'ar']));

        $lines = explode("\n", trim(Storage::disk('local')->get($import->errors_file_path)));

        $this->assertCount(4, str_getcsv($lines[0], ',', '"', ''));
        $this->assertCount(4, str_getcsv($lines[1], ',', '"', ''));
        $this->assertStringContainsString('الرقم ناقص', $lines[1]);
    }

    public function test_duplicates_inside_the_file_are_counted_not_rejected(): void
    {
        $import = $this->validate($this->makeImport($this->csv([
            ['966501110001', '', '2026-05-23 10:00:00', 'in', 'hi'],
            ['966501110001', '', '2026-05-23 10:05:00', 'in', 'other'],
            // نفس الأولى لكن ليست ملاصقة لها: تكرار في التصدير.
            ['966501110001', '', '2026-05-23 10:00:00', 'in', 'hi'],
        ])));

        $this->assertSame(ChatImport::QUEUED, $import->status);
        $this->assertSame(3, $import->total_rows);
        $this->assertSame(2, $import->new_messages_estimate);
    }

    public function test_exceeding_the_contacts_limit_rejects_the_file(): void
    {
        $this->setLimits(['contacts_limit' => 2]);
        Contact::create(['organization_id' => $this->organization->id, 'phone' => '+966501110009', 'created_by' => $this->user->id]);

        $import = $this->validate($this->makeImport($this->csv([
            ['966501110001', '', '2026-05-23 10:00:00', 'in', 'a'],
            ['966501110002', '', '2026-05-23 10:00:00', 'in', 'b'],
        ])));

        $this->assertSame(ChatImport::INVALID, $import->status);
        $this->assertStringContainsString('2 new contacts', $import->error_message);
        $this->assertStringContainsString('1 more', $import->error_message);
    }

    public function test_filling_the_contacts_limit_exactly_is_allowed(): void
    {
        $this->setLimits(['contacts_limit' => 2]);

        $import = $this->validate($this->makeImport($this->csv([
            ['966501110001', '', '2026-05-23 10:00:00', 'in', 'a'],
            ['966501110002', '', '2026-05-23 10:00:00', 'in', 'b'],
        ])));

        $this->assertSame(ChatImport::QUEUED, $import->status);
    }

    public function test_reaching_the_message_limit_exactly_is_rejected(): void
    {
        // بلوغ حدّ الرسائل يوقف الردّ الآلي ويُقفل الإرسال: count >= limit.
        $this->setLimits(['message_limit' => 2]);

        $import = $this->validate($this->makeImport($this->csv([
            ['966501110001', '', '2026-05-23 10:00:00', 'in', 'a'],
            ['966501110001', '', '2026-05-23 10:01:00', 'in', 'b'],
        ])));

        $this->assertSame(ChatImport::INVALID, $import->status);
        $this->assertStringContainsString('2 new messages', $import->error_message);

        $this->setLimits(['message_limit' => 3]);
        $this->assertSame(ChatImport::QUEUED, $this->validate($this->makeImport($this->csv([
            ['966501110001', '', '2026-05-23 10:00:00', 'in', 'a'],
            ['966501110001', '', '2026-05-23 10:01:00', 'in', 'b'],
        ])))->status);
    }

    public function test_already_imported_messages_do_not_count_against_the_limit(): void
    {
        $csv = $this->csv([
            ['966501110001', '', '2026-05-23 10:00:00', 'in', 'a'],
            ['966501110001', '', '2026-05-23 10:01:00', 'in', 'b'],
        ]);
        $this->runImport($this->makeImport($csv));

        $this->setLimits(['message_limit' => 3]);
        $import = $this->validate($this->makeImport($csv));

        $this->assertSame(ChatImport::QUEUED, $import->status, 'nothing new would be written');
        $this->assertSame(0, $import->new_messages_estimate);
    }

    public function test_an_expired_subscription_rejects_the_file(): void
    {
        DB::table('subscriptions')->where('organization_id', $this->organization->id)->update(['valid_until' => now()->subDay()]);

        $import = $this->validate($this->makeImport($this->csv([['966501110001', '', '2026-05-23 10:00:00', 'in', 'a']])));

        $this->assertSame(ChatImport::INVALID, $import->status);
        $this->assertSame(__('Your subscription is not active. Renew it to import conversations.'), $import->error_message);
    }

    public function test_a_cancelled_validation_stops_and_removes_the_file(): void
    {
        $import = $this->makeImport($this->csv([['966501110001', '', '2026-05-23 10:00:00', 'in', 'a']]));
        $import->forceFill(['status' => ChatImport::CANCELLED])->save();

        (new ValidateChatImportJob($import->id))->handle();

        $this->assertSame(ChatImport::CANCELLED, $import->refresh()->status, 'a cancelled import is never validated');
    }

    public function test_errors_are_written_in_the_uploaders_language(): void
    {
        $import = $this->validate($this->makeImport(
            $this->csv([['', '', '2026-05-23 10:00:00', 'in', 'a']]),
            ['locale' => 'ar']
        ));

        $this->assertSame(ChatImport::INVALID, $import->status);
        $this->assertSame(trans('Phone number is required.', [], 'ar'), $import->errors[0]['message']);
        $this->assertNotSame('Phone number is required.', $import->errors[0]['message']);
    }

    public function test_a_validation_crash_marks_the_import_failed(): void
    {
        $import = $this->makeImport($this->csv([['966501110001', '', '2026-05-23 10:00:00', 'in', 'a']]), [
            'status' => ChatImport::VALIDATING,
        ]);

        (new ValidateChatImportJob($import->id))->failed(new \RuntimeException('worker died'));

        $import->refresh();
        $this->assertSame(ChatImport::FAILED, $import->status);
        $this->assertFalse($import->canResume(), 'nothing was validated, there is nothing to resume');
        $this->assertNull($import->file_path);
    }
}
