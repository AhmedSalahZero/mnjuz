<?php

namespace App\Services;

use App\Helpers\CustomHelper;
use App\Helpers\DateTimeHelper;
use App\Models\Organization;
use Carbon\Carbon;
use Illuminate\Support\Facades\Lang;

class WorkingHoursService
{
    /**
     * @return array<int, array{day:int, open:string, close:string}>
     */
    public static function slotsForOrganization(int $organizationId): array
    {
        $org = Organization::find($organizationId);
        if (!$org || !$org->metadata) {
            return [];
        }
        $meta = json_decode($org->metadata, true) ?: [];
        $slots = $meta['working_hours'] ?? [];
        if (!is_array($slots)) {
            return [];
        }

        return array_values(array_filter($slots, function ($row) {
            return isset($row['day'], $row['open'], $row['close'])
                && is_numeric($row['day'])
                && is_string($row['open'])
                && is_string($row['close']);
        }));
    }

    public static function isOrganizationOpenNow(int $organizationId): bool
    {
        $slots = self::slotsForOrganization($organizationId);
        if ($slots === []) {
            return true;
        }

        $now = self::organizationNow($organizationId);
        $dayOfWeek = (int) $now->dayOfWeek; // 0 = Sunday … 6 = Saturday (matches PHP date('w'))
        $minutesNow = $now->hour * 60 + $now->minute;

        foreach ($slots as $slot) {
            if ((int) $slot['day'] !== $dayOfWeek) {
                continue;
            }

            $openMinutes = self::timeToMinutes($slot['open']);
            $closeMinutes = self::timeToMinutes($slot['close']);
            if ($openMinutes === null || $closeMinutes === null) {
                continue;
            }

            // Inclusive start/end: 08:00–16:00 means open through 16:00.
            if ($minutesNow >= $openMinutes && $minutesNow <= $closeMinutes) {
                return true;
            }
        }

        return false;
    }

    /**
     * Outside configured weekly hours while the Working Hours addon is active.
     */
    public static function isOutsideConfiguredHours(int $organizationId): bool
    {
        if (!CustomHelper::isModuleEnabled('Working Hours', $organizationId)) {
            return false;
        }

        if (self::slotsForOrganization($organizationId) === []) {
            return false;
        }

        return !self::isOrganizationOpenNow($organizationId);
    }

    public static function organizationNow(int $organizationId): Carbon
    {
        $timezone = DateTimeHelper::getCurrentTimeZone($organizationId);

        return Carbon::now($timezone);
    }

    /**
     * @return int|null Minutes since midnight for HH:MM or H:MM.
     */
    public static function timeToMinutes(string $time): ?int
    {
        $time = trim($time);
        if (!preg_match('/^(\d{1,2}):(\d{2})$/', $time, $matches)) {
            return null;
        }

        $hours = (int) $matches[1];
        $minutes = (int) $matches[2];
        if ($hours > 23 || $minutes > 59) {
            return null;
        }

        return ($hours * 60) + $minutes;
    }

    public static function buildAwayNoticeMessage(int $organizationId): string
    {
        $locale = Lang::getLocale();
        $intro = __('working_hours_away_intro', [], $locale);
        $heading = __('working_hours_weekly_schedule_heading', [], $locale);
        $schedule = self::formatWeeklyScheduleText($organizationId, $locale);

        return trim($intro . "\n\n" . $heading . "\n" . $schedule);
    }

    /**
     * Custom WhatsApp text when contacts message outside configured hours (metadata).
     * Non-empty value replaces the default intro + weekly schedule message.
     */
    public static function customOutsideHoursMessage(int $organizationId): string
    {
        $org = Organization::find($organizationId);
        if (!$org || !$org->metadata) {
            return '';
        }
        $meta = json_decode($org->metadata, true) ?: [];
        $raw = $meta['working_hours_outside_message'] ?? '';
        if (!is_string($raw)) {
            return '';
        }

        return trim($raw);
    }

    public static function resolveAwayNoticeBody(int $organizationId): string
    {
        $custom = self::customOutsideHoursMessage($organizationId);
        if ($custom !== '') {
            return $custom;
        }

        return self::buildAwayNoticeMessage($organizationId);
    }

    public static function formatWeeklyScheduleText(int $organizationId, ?string $locale = null): string
    {
        $slots = self::slotsForOrganization($organizationId);
        if ($slots === []) {
            return __('working_hours_not_configured', [], $locale ?? Lang::getLocale());
        }

        $byDay = [];
        foreach ($slots as $slot) {
            $d = (int) $slot['day'];
            $byDay[$d][] = $slot;
        }
        foreach ($byDay as &$list) {
            usort($list, fn ($a, $b) => strcmp($a['open'], $b['open']));
        }
        unset($list);

        $loc = $locale ?? Lang::getLocale();
        $lines = [];
        foreach (range(0, 6) as $d) {
            if (empty($byDay[$d])) {
                continue;
            }
            $parts = array_map(
                fn ($s) => $s['open'] . '–' . $s['close'],
                $byDay[$d]
            );
            $lines[] = self::weekdayName($d, $loc) . ': ' . implode(', ', $parts);
        }

        return implode("\n", $lines);
    }

    /**
     * Full weekday name for PHP date('w'): 0 = Sunday … 6 = Saturday.
     */
    public static function weekdayName(int $dayOfWeek, ?string $locale = null): string
    {
        $loc = $locale ?? Lang::getLocale();

        return Carbon::create(2024, 1, 7)
            ->addDays($dayOfWeek)
            ->locale($loc)
            ->translatedFormat('l');
    }
}
