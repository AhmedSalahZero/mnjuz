<?php

namespace Tests\Unit;

use App\Services\WorkingHoursService;
use PHPUnit\Framework\TestCase;

class WorkingHoursServiceTest extends TestCase
{
    public function test_time_to_minutes_parses_padded_and_unpadded_values(): void
    {
        $this->assertSame(480, WorkingHoursService::timeToMinutes('08:00'));
        $this->assertSame(480, WorkingHoursService::timeToMinutes('8:00'));
        $this->assertSame(960, WorkingHoursService::timeToMinutes('16:00'));
    }

    public function test_time_to_minutes_rejects_invalid_values(): void
    {
        $this->assertNull(WorkingHoursService::timeToMinutes('25:00'));
        $this->assertNull(WorkingHoursService::timeToMinutes('bad'));
    }
}
