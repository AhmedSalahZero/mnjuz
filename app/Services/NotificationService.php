<?php

namespace App\Services;

use App\Http\Resources\NotificationsResource;
use App\Models\Notification;

class NotificationService
{
    /**
     * Get all notifications based on the provided request filters.
     *
     * @param Request $request
     * @return mixed
     */
    public function get(object $request)
    {
        $notifications = (new Notification)->listAll($request->query('search'));

        return NotificationsResource::collection($notifications);
    }
}