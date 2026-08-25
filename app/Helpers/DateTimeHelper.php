<?php 

namespace App\Helpers;

use App\Models\Organization;
use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class DateTimeHelper
{
    public static function formatDate(string $dateTimeString)
    {
        $dt = Carbon::create($dateTimeString);
        $dateFormat = Setting::where('key', '=', 'date_format')->first()->value;
        $timeFormat = Setting::where('key', '=', 'time_format')->first()->value;

        return $dt->format($dateFormat . ' ' . $timeFormat); 
    }
	public static function getOrginizationNowTime()
	{
		
	}
	public static function getCurrentTimeZone($organizationId = null):string
	{
		$timezone = 'UTC'; // Default to UTC
        $organizationId = session()->get('current_organization', $organizationId);
        if ($organizationId) {
            // تخزين مؤقت لكل طلب لتجنب N+1 (مثلاً من Chat::getCreatedAtAttribute)
            static $cache = [];
            if (!isset($cache[$organizationId])) {
                $organization = Organization::find($organizationId);
                $meta = $organization ? json_decode($organization->metadata ?? '{}', true) : null;
                $cache[$organizationId] = ($meta && isset($meta['timezone'])) ? $meta['timezone'] : 'UTC';
            }
            $timezone = $cache[$organizationId];
        }
		return $timezone;
	}
    public static function convertToOrganizationTimezone($date,$organizationId = null)
    {
        $timezone = self::getCurrentTimeZone($organizationId);
        return Carbon::parse($date)->setTimezone($timezone);
    }

    /**
     * نفس نتيجة convertToOrganizationTimezone(...)->toDateTimeString() بلا Carbon.
     *
     * الـ accessors في Chat وChatLog وChatMedia وغيرها تستدعي هذه الدالة مرّةً
     * لكل صفّ لكل عمود تاريخ. وCarbon::parse يبني DateTime ثمّ يمرّ على محلّل
     * التعابير الطبيعية ثمّ يُنشئ نسخةً أخرى عند setTimezone — عشرات الميكرو
     * ثوانٍ للصفّ الواحد. مزامنة منشأة فيها مئة ألف رسالة تستدعيها مئات
     * الآلاف من المرّات، فتلتهم وحدها عشرات الثواني من مهلة الطلب. لهذا يظهر
     * Carbon\Traits\Date::setTimezone في أعلى أثر خطأ «تجاوز مهلة التنفيذ».
     *
     * التواريخ الآتية من MySQL نصٌّ ثابت الشكل 'Y-m-d H:i:s' بتوقيت UTC
     * (config('app.timezone') = UTC)، فيكفيها DateTimeImmutable مباشرةً مع
     * كائن DateTimeZone محفوظ. ما خرج عن هذا الشكل — null أو تنسيق آخر —
     * يعود إلى Carbon كما كان، فلا يتغيّر سلوكه.
     */
    public static function toOrganizationTimeString($date, $organizationId = null): string
    {
        if (is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $date) === 1) {
            $timezone = self::getCurrentTimeZone($organizationId);

            static $zones = [];
            if (!isset($zones[$timezone])) {
                $zones[$timezone] = new \DateTimeZone($timezone);
            }

            static $utc = null;
            if ($utc === null) {
                $utc = new \DateTimeZone('UTC');
            }

            return (new \DateTimeImmutable($date, $utc))
                ->setTimezone($zones[$timezone])
                ->format('Y-m-d H:i:s');
        }

        return self::convertToOrganizationTimezone($date, $organizationId)->toDateTimeString();
    }

    public static function convertToCompanyTimezone($date)
    {
        $timezone = Setting::where('key', 'timezone')->value('value') ?? 'UTC';

        return Carbon::parse($date)->setTimezone($timezone);
    }

    public static function formatDateWithoutHours($date)
    {
        return $date->format('d M Y'); // Format without hours, minutes, and seconds
    }
}
