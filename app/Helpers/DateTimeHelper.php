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
        $organizationId = session()->get('current_organization',$organizationId);
        if ($organizationId) {
            $organization = Organization::find($organizationId);
            if ($organization) {
			//	logger('inside if'.$organizationId);
                $metadata = $organization->metadata;
                $metadata = isset($metadata) ? json_decode($metadata, true) : null;
                if ($metadata && isset($metadata['timezone'])) {
			//			logger('time  from if zone'.$timezone);
						$timezone = $metadata['timezone'];
                }
            }
        }
		return $timezone;
	}
    public static function convertToOrganizationTimezone($date,$organizationId = null)
    {
        $timezone = self::getCurrentTimeZone($organizationId);
        return Carbon::parse($date)->setTimezone($timezone);
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
