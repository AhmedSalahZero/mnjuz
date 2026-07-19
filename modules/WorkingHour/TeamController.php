<?php

namespace App\Services;

use App\Helpers\CustomHelper;
use App\Helpers\Email;
use App\Models\Team;
use App\Models\TeamInvite;
use App\Models\TeamWorkingHour;
use App\Models\User;
use Auth;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redirect;
use Str;

class TeamService
{
    public function update(object $request, $uuid)
    {
        $team = Team::where('uuid', $uuid)
            ->where('organization_id', CustomHelper::getOrganization())
            ->firstOrFail();

        abort_if($team->role === 'owner', Response::HTTP_FORBIDDEN, 'You can\'t modify the main admin account!');

        $team->update(['has_working_hours' => $request->boolean('has_working_hours')]);

        // Delete existing working hours
        $team->workingHours()->delete();

        if (! $request->boolean('has_working_hours')) {
            return;
        }

        // Insert new working hours
        $workingHours = collect($request->working_hours)->map(fn ($shift) => [
            'team_id'         => $team->id,
            'organization_id' => $team->organization_id,
            'day_of_week'     => strtoupper($shift['day']),
            'start_time'      => $shift['start_time'],
            'end_time'        => $shift['end_time'],
            'is_active'       => $shift['active'],
        ]);

        TeamWorkingHour::insert($workingHours->toArray());
    }
}
