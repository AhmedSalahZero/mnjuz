<?php

namespace App\Services;

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

        $now = DateTimeHelper::convertToOrganizationTimezone(now(), $organizationId);
        $dow = (int) $now->format('w');
        $time = $now->format('H:i');

        foreach ($slots as $slot) {
            if ((int) $slot['day'] !== $dow) {
                continue;
            }
            if (strcmp($time, $slot['open']) >= 0 && strcmp($time, $slot['close']) < 0) {
                return true;
            }
        }

        return false;
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
