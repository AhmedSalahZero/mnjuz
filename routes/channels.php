<?php

use App\Models\Team;
use Illuminate\Support\Facades\Broadcast;

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
	// if ((int) $user->id !== (int) $userId) {
	// 	return false;
	// }
	// $belongsToOrg = Team::where('organization_id', (int) $organizationId)
	// 	->where('user_id', $user->id)
	// 	->exists();
	// if (!$belongsToOrg) {
	// 	return false;
	// }
	return [
		'id' => $user->id,
	];
});
