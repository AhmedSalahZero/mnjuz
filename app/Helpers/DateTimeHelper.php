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
    public static function convertToOrganizationTimezone($date,$organizationId = null)
    {
        $timezone = 'UTC'; // Default to UTC
        $organizationId = session()->get('current_organization',$organizationId);
	//	logger('organization id in datetime helper'.$organizationId);
		// if(Cache::has("org_timezone_{$organizationId}")){
		// 	$timezone = Cache::get("org_timezone_{$organizationId}");
		// 	logger('used cached time zone'.$timezone);
		// 	return Carbon::parse($date)->setTimezone($timezone);
		// }
		
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

		//  $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);

 		//    $caller = $trace[1] ?? null;
	//  if ($caller && !$organizationId) {
	// 	logger('called from ');
    //     logger(json_encode([
    //         'function' => $caller['function'] ?? null,
    //         'class'    => $caller['class'] ?? null,
    //         'type'     => $caller['type'] ?? null,
    //         'file'     => $caller['file'] ?? null,
    //         'line'     => $caller['line'] ?? null,
	// 	]));
    // }
		//   $timezone = Cache::remember(
        //     "org_timezone_{$organizationId}", 
        //     3600, // ساعة واحدة
        //     function() use ($timezone) {
        //        return $timezone;
        //     }
        // );
		// logger('used nn time zone'.$timezone);
	//	logger('used on'.$timezone.'for organization id'.$organizationId);
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
