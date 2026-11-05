<?php

namespace Tests\Unit\ChatImport;

use App\Services\ChatImport\MessageFingerprint;
use App\Services\ChatImport\ParsedRow;
use App\Services\ChatImport\RunTracker;
use PHPUnit\Framework\TestCase;

class MessageFingerprintTest extends TestCase
{
    private function row(string $text = 'hi', string $at = '2026-05-23 10:00:00', string $phone = '+966501234567', string $direction = 'inbound'): ParsedRow
    {
        return new ParsedRow(2, $phone, null, $at, $direction, $text, null);
    }

    public function test_it_is_deterministic_and_fits_wam_id(): void
    {
        $a = MessageFingerprint::make(1, $this->row(), 0);

        $this->assertSame($a, MessageFingerprint::make(1, $this->row(), 0));
        $this->assertStringStartsWith('imp:', $a);
        $this->assertLessThanOrEqual(128, strlen($a));
        $this->assertSame(20, strlen(MessageFingerprint::binary($a)));
    }

    public function test_every_part_of_the_message_matters(): void
    {
        $base = MessageFingerprint::make(1, $this->row(), 0);

        $this->assertNotSame($base, MessageFingerprint::make(2, $this->row(), 0), 'organization');
        $this->assertNotSame($base, MessageFingerprint::make(1, $this->row('other'), 0), 'text');
        $this->assertNotSame($base, MessageFingerprint::make(1, $this->row(at: '2026-05-23 10:00:01'), 0), 'time');
        $this->assertNotSame($base, MessageFingerprint::make(1, $this->row(phone: '+966501234568'), 0), 'phone');
        $this->assertNotSame($base, MessageFingerprint::make(1, $this->row(direction: 'outbound'), 0), 'direction');
        $this->assertNotSame($base, MessageFingerprint::make(1, $this->row(), 1), 'occurrence');
        // الاسم لا يدخل: تصدير أحدث بعد تغيير اسم العميل لا يكرّر رسائله.
        $renamed = new ParsedRow(9, '+966501234567', 'New name', '2026-05-23 10:00:00', 'inbound', 'hi', null);
        $this->assertSame($base, MessageFingerprint::make(1, $renamed, 0));
    }

    public function test_the_run_tracker_numbers_identical_messages_in_a_burst(): void
    {
        $runs = new RunTracker();

        $this->assertSame(0, $runs->occurrence($this->row('[image]')));
        $this->assertSame(1, $runs->occurrence($this->row('[image]')));
        $this->assertSame(0, $runs->occurrence($this->row('caption')), 'a different message in the same second');
        $this->assertSame(2, $runs->occurrence($this->row('[image]')));
        $this->assertSame(0, $runs->occurrence($this->row('[image]', at: '2026-05-23 10:00:01')), 'a new second starts a new run');
        $this->assertSame(0, $runs->occurrence($this->row('[image]')), 'a run that ended is not resumed');
    }
}
