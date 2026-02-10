<?php

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


Broadcast::channel('chats.ch{organizationId}', function ($user, $organizationId) {

	return [
		'id' => $user->id,
		// 'name' => $user->name,
		// 'email' => $user->email,
		// 'avatar' => $user->avatar,
		// 'role' => $user->role,
		// 'organizationId' => $organizationId,
	
	];
  //  return (int) $user->id === (int) $organizationId;
});
