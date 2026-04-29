<?php

namespace App\Models;

use App\Mail\CustomEmailVerification;
use App\Models\UserDevice;
use App\Services\Firebase\FcmNotification;
use App\Traits\Models\HasDeviceTokens;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens, HasFactory, Notifiable;
    use SoftDeletes;
	use HasDeviceTokens;
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'password',
        'avatar',
        'role',
        'phone',
        'address',
        'language',
        'verification_enabled',
        'is_verified',
        'deleted_at'
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'verification_enabled' => 'boolean',
        'is_verified' => 'boolean',
    ];

    protected $appends = ['full_name'];

    public function getFullNameAttribute()
    {
        return "{$this->first_name} {$this->last_name}";
    }
    
    public function listAll($role, $searchTerm, $organizationId = null)
    {
        $query = $this->where(function ($query) use ($role) {
                if ($role === 'user') {
                    $query->where('users.role', '=', 'user');
                } else {
                    $query->where('users.role', '!=', 'user');
                }
            })
            ->where(function ($query) use ($searchTerm) {
                $query->where('first_name', 'like', '%' . $searchTerm . '%')
                    ->orWhere('last_name', 'like', '%' . $searchTerm . '%')
                    ->orWhere('email', 'like', '%' . $searchTerm . '%')
                    ->orWhere('phone', 'like', '%' . $searchTerm . '%');
            })
            ->latest('users.created_at');

        if ($organizationId !== null) {
            $query->join('teams', 'teams.user_id', '=', 'users.id')
                ->where('teams.organization_id', '=', $organizationId)
                ->select('users.*', 'teams.role');
        }

        return $query->paginate(10);
    }

    public function teams()
    {
        return $this->hasMany(Team::class);
    }

    public function teamsWithOrganizations(){
        return $this->teams()->with('organization');
    }

    public function role(){
        return $this->belongsTo(Role::class, 'role', 'name');
    }

    public function sendEmailVerificationNotification(){
        try {
            \Mail::to($this->email)->send(new CustomEmailVerification($this));
        } catch (\Exception $e) {
            \Log::error('Failed to send verification email: ' . $e->getMessage());
        }
    }
	public function getActiveOrganizations()
	{
		return Team::with('organization')->whereHas('organization',function($q){
			$q->where('is_banned',false);
		})->where('user_id', $this->id);
	}	
	public function canNotAccessDashboard()
	{
		return $this->role == 'user' && $this->getActiveOrganizations()->count() == 0;
	}
	public function hasOrganization($organizationId)
	{
		return $this->teams()->where('organization_id', $organizationId)->exists();
	}
	public function getRoleNameForOrganization($organizationId): string
	{
		$team = $this->teams()->where('organization_id', $organizationId)->first();

		return $team ? (string) $team->role : '';
	}

    public function device()
    {
        return $this->hasOne(UserDevice::class);
    }
	// public static function sendNewMessageReceivedToFirestore(int $contactId,array $additionalDataToBeSent = []){
	// 	try{
	// 		$firestore = new Firestore;
	// 	$firestore->setNewMessageReceived($contactId,$additionalDataToBeSent);
	// 	}
	// 	catch(\Exception $e){
	// 		Log::error('Error sending new message received to firestore: '.$e->getMessage());
	// 	}
	// }
	
	
	 /**
     * * هي الاشعارات اللي بتتبعت للعميل في الموبايل ابلكيشن
     */
    public function sendAppNotification(string $titleEn, string $titleAr, string $messageEn, string $messageAr, array $additionalData  )
    {
		
        // $this->notify(new DriverNotification($titleEn, $titleAr, $messageEn, $messageAr, formatForView(now()), $secondaryType,$modelId,$mainType));
		$firebaseService = new FcmNotification;
		$title = [
			'en'=>$titleEn,
			'ar'=>$titleAr
		][getApiLang()];
		$message = [
			'en'=>$messageEn,
			'ar'=>$messageAr
		][getApiLang()];
		// $additionalData = [
		// 	'main_type'=>$mainType,
		// 	'secondary_type'=>$secondaryType ,
		// ];
		// if(count($this->getDeviceTokens()) == 0){
		// 	logger('no device tokens found for user ' . $this->id);
		// }else{
		// 	logger('device tokens found for user ' . $this->id);
		// }
		try{
			foreach($this->getDeviceTokens() as $fcmToken){
				$firebaseService->send($title,$message,$fcmToken,$additionalData);
			}
		}
		catch(\Exception $e){
			logger('Can Not Send Firebase Message To User ' . $e->getMessage() );
		}
		
    }
	
}
	