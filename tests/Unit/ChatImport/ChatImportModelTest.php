<?php

namespace Tests\Unit\ChatImport;

use App\Models\ChatImport;
use Tests\TestCase;

/**
 * النسبة والأزرار من حالة الاستيراد — ما تقرؤه الصفحة كل ثانيتين.
 */
class ChatImportModelTest extends TestCase
{
    private function import(array $attributes): ChatImport
    {
        return (new ChatImport())->forceFill(array_merge([
            'uuid' => 'u-1',
            'status' => ChatImport::PENDING_VALIDATION,
            'file_size' => 1000,
            'file_path' => 'chat-imports/1/x.csv',
            'progress_bytes' => 0,
            'total_rows' => 0,
            'processed_rows' => 0,
            'imported_messages' => 0,
            'rolled_back_messages' => 0,
        ], $attributes));
    }

    public function test_progress_follows_the_current_phase(): void
    {
        $this->assertSame(0, $this->import([])->progressPercent());
        $this->assertSame(25, $this->import(['status' => ChatImport::VALIDATING, 'progress_bytes' => 250])->progressPercent());
        $this->assertSame(0, $this->import(['status' => ChatImport::QUEUED, 'total_rows' => 10])->progressPercent());
        $this->assertSame(33, $this->import(['status' => ChatImport::IMPORTING, 'total_rows' => 3, 'processed_rows' => 1])->progressPercent());
        $this->assertSame(99, $this->import(['status' => ChatImport::IMPORTING, 'total_rows' => 1000, 'processed_rows' => 999])->progressPercent(), 'never 100 before it is done');
        $this->assertSame(50, $this->import(['status' => ChatImport::ROLLING_BACK, 'imported_messages' => 4, 'rolled_back_messages' => 2])->progressPercent());
        $this->assertSame(100, $this->import(['status' => ChatImport::COMPLETED])->progressPercent());
        $this->assertSame(0, $this->import(['status' => ChatImport::IMPORTING, 'total_rows' => 0])->progressPercent(), 'no division by zero');
    }

    public function test_phases(): void
    {
        $this->assertSame('validating', $this->import(['status' => ChatImport::VALIDATING])->phase());
        $this->assertSame('importing', $this->import(['status' => ChatImport::QUEUED])->phase());
        $this->assertSame('importing', $this->import(['status' => ChatImport::CANCELLING])->phase());
        $this->assertSame('rolling_back', $this->import(['status' => ChatImport::ROLLING_BACK])->phase());
        $this->assertSame('done', $this->import(['status' => ChatImport::INVALID])->phase());
    }

    public function test_available_actions(): void
    {
        $importing = $this->import(['status' => ChatImport::IMPORTING, 'total_rows' => 10, 'processed_rows' => 4, 'imported_messages' => 4, 'started_at' => now()]);
        $this->assertTrue($importing->canCancel());
        $this->assertFalse($importing->canResume());
        $this->assertFalse($importing->canRollback(), 'roll back after it stops, not while it writes');

        $failed = $this->import(['status' => ChatImport::FAILED, 'total_rows' => 10, 'processed_rows' => 4, 'imported_messages' => 4, 'started_at' => now()]);
        $this->assertTrue($failed->canResume());
        $this->assertTrue($failed->canRollback());
        $this->assertFalse($failed->canCancel());

        $this->assertFalse($this->import(['status' => ChatImport::FAILED, 'total_rows' => 10, 'processed_rows' => 4, 'started_at' => now(), 'file_path' => null])->canResume(), 'no file, nothing to resume from');
        $this->assertFalse($this->import(['status' => ChatImport::FAILED, 'total_rows' => 10])->canResume(), 'failed while validating');

        $completed = $this->import(['status' => ChatImport::COMPLETED, 'total_rows' => 10, 'processed_rows' => 10, 'imported_messages' => 8, 'started_at' => now()]);
        $this->assertTrue($completed->canRollback());
        $this->assertFalse($completed->canResume());

        $this->assertFalse($this->import(['status' => ChatImport::COMPLETED, 'imported_messages' => 0])->canRollback(), 'nothing was added');
        $this->assertFalse($this->import(['status' => ChatImport::INVALID])->canRollback());
        $this->assertFalse($this->import(['status' => ChatImport::ROLLED_BACK, 'imported_messages' => 8, 'rolled_back_messages' => 8])->canRollback());
    }

    public function test_an_import_without_progress_is_flagged_as_stalled(): void
    {
        $this->assertFalse($this->import(['status' => ChatImport::PENDING_VALIDATION, 'updated_at' => now()->subMinutes(2)])->isStalled());
        $this->assertTrue($this->import(['status' => ChatImport::PENDING_VALIDATION, 'updated_at' => now()->subMinutes(6)])->isStalled(), 'no worker on the imports queue');
        $this->assertFalse($this->import(['status' => ChatImport::IMPORTING, 'updated_at' => now()->subMinutes(10)])->isStalled(), 'a retry is still due');
        $this->assertTrue($this->import(['status' => ChatImport::IMPORTING, 'updated_at' => now()->subMinutes(20)])->isStalled());
        $this->assertFalse($this->import(['status' => ChatImport::COMPLETED, 'updated_at' => now()->subDays(3)])->isStalled(), 'finished imports never stall');
        $this->assertTrue($this->import(['status' => ChatImport::QUEUED, 'updated_at' => now()->subHour()])->toStatusPayload()['stalled']);
    }

    public function test_the_status_payload_hides_internal_paths(): void
    {
        $payload = $this->import(['status' => ChatImport::INVALID, 'errors_file_path' => 'chat-imports/1/x-errors.csv', 'errors' => [['row' => 2]]])->toStatusPayload();

        $this->assertArrayNotHasKey('file_path', $payload);
        $this->assertArrayNotHasKey('errors_file_path', $payload);
        $this->assertTrue($payload['has_errors_file']);
        $this->assertSame([['row' => 2]], $payload['errors']);
        $this->assertSame([], $this->import(['errors' => [['row' => 2]]])->toStatusPayload(false)['errors'], 'the history list skips errors');
        $this->assertFalse($this->import([])->toArray() === [] || array_key_exists('file_path', $this->import([])->toArray()));
    }
}
