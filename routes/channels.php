<?php

use App\Models\Team;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Log;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/

// Broadcast::channel('chats.ch{organizationId}', function ($user, $organizationId) {
// 	return [
// 		'id' => $user->id,
// 	];
// });

/**
 * Presence channel used by NewChatEvent: chats.ch{organizationId}.{userId}
 * User must be subscribing to their own channel and belong to the organization.
 */
Broadcast::channel('chats.ch{organizationId}.{userId}', function ($user, $organizationId, $userId) {
	// الرفض هنا يصل المتصفّح كـ403 عارٍ، والاشتراك يفشل بلا أثر: تُحفظ
	// الرسائل ولا تصل لحظياً. تسجيل السبب يجعل العطل قابلاً للتشخيص بدل
	// الاستدلال عليه من غياب الرسائل.
	$refuse = function (string $reason) use ($user, $organizationId, $userId) {
		Log::warning('Broadcast channel authorization refused', [
			'reason'          => $reason,
			'channel'         => 'chats.ch' . $organizationId . '.' . $userId,
			'auth_user_id'    => $user->id,
			'requested_user'  => $userId,
			'organization_id' => $organizationId,
		]);

		return false;
	};

	if ((int) $user->id !== (int) $userId) {
		return $refuse('requested another user\'s channel');
	}

	$belongsToOrg = Team::where('organization_id', (int) $organizationId)
		->where('user_id', $user->id)
		->whereNull('deleted_at')
		->exists();

	if (!$belongsToOrg) {
		return $refuse('not a member of the organization');
	}

	return [
		'id' => $user->id,
	];
});
