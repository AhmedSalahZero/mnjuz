<?php

namespace App\Models;

use App\Http\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Organization extends Model {
    use HasFactory;
    use HasUuid;
    use SoftDeletes;

    protected $guarded = [];
    public $timestamps = true;

    public function listAll($searchTerm, $userId = null)
    {
        $query = $this->with(['teams.user', 'owner.user', 'subscription.plan'])
            ->when($userId !== null, function ($query) use ($userId) {
                $query->whereHas('teams', function ($teamsQuery) use ($userId) {
                    $teamsQuery->where('user_id', $userId);
                });
            })
            ->where(function ($query) use ($searchTerm) {
                $query->where('name', 'like', '%' . $searchTerm . '%');
            })
            ->withCount('teams')
            ->latest()
            ->paginate(10);

        return $query;
    }

    public function teams()
    {
        return $this->hasMany(Team::class, 'organization_id');
    }

    public function owner()
    {
        return $this->belongsTo(Team::class, 'id', 'organization_id')->where('role', 'owner');
    }

    public function subscription()
    {
        return $this->belongsTo(Subscription::class, 'id', 'organization_id');
    }
	public function contacts()
	{
		return $this->hasMany(Contact::class, 'organization_id', 'id')->orderBy('latest_chat_created_at', 'desc');
	}
	public function getTicketingActive():bool
	{
		$ticketingActive = false;
		if ($this->metadata != null) {
			$settings = json_decode($this->metadata);
			if (isset($settings->tickets) && $settings->tickets->active === true) {
				$ticketingActive = true;
			}
		}
		return $ticketingActive;
	}
	public function getAllowAgentsToViewAllChats():bool
	{
		$allowAgentsToViewAllChats = true;
		$settings = json_decode($this->metadata);
		$ticketingActive = $this->getTicketingActive();
		if($ticketingActive){
			$allowAgentsToViewAllChats =  $settings->tickets->allow_agents_to_view_all_chats;
			// في الوضع "المشترك" تظهر كل المحادثات لجميع الموظفين.
			if ($this->getTicketAssignmentMode() === 'shared') {
				$allowAgentsToViewAllChats = true;
			}
		}
		return $allowAgentsToViewAllChats;
		
	}

	/**
	 * وضع إسناد التذاكر: manual | auto | shared.
	 * نستنتج القيمة من الإعداد القديم auto_assignment عند غياب assignment_mode
	 * لضمان التوافق مع البيانات الحالية.
	 */
	public function getTicketAssignmentMode(): string
	{
		$settings = $this->metadata ? json_decode($this->metadata) : null;
		if (!is_object($settings) || !isset($settings->tickets)) {
			return 'manual';
		}
		$mode = $settings->tickets->assignment_mode ?? null;
		if (in_array($mode, ['manual', 'auto', 'shared'], true)) {
			return $mode;
		}
		$autoAssignment = $settings->tickets->auto_assignment ?? false;
		return $autoAssignment ? 'auto' : 'manual';
	}
}
