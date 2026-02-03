<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Defines the minimal Chat payload for broadcasting (only fields used by the frontend).
 */
class ChatBroadcastValueResource extends JsonResource
{
    /**
     * Transform the chat value into an array for broadcast.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $arr = $this->resource instanceof \Illuminate\Database\Eloquent\Model
            ? $this->resource->toArray()
            : (array) $this->resource;
			logger(json_encode([
				'contact_id' => $arr['contact_id'] ?? null,
				'created_at' => $arr['created_at'] ?? null,
				'deleted_at' => $arr['deleted_at'] ?? null,
				'metadata' => $arr['metadata'] ?? null,
				'type' => $arr['type'] ?? null,
				'wam_id' => $arr['wam_id'] ?? null,
				'media' => $this->when(isset($arr['media']), fn () => $this->filterMedia($arr['media'])),
				'logs' => $this->when(isset($arr['logs']), fn () => $this->filterLogs($arr['logs'])),
				'user' => $this->when(isset($arr['user']), fn () => $this->filterUser($arr['user'])),
			]));
        return [
            'contact_id' => $arr['contact_id'] ?? null,
            'created_at' => $arr['created_at'] ?? null,
            'deleted_at' => $arr['deleted_at'] ?? null,
            'metadata' => $arr['metadata'] ?? null,
            'type' => $arr['type'] ?? null,
            'wam_id' => $arr['wam_id'] ?? null,
            'media' => $this->when(isset($arr['media']), fn () => $this->filterMedia($arr['media'])),
            'logs' => $this->when(isset($arr['logs']), fn () => $this->filterLogs($arr['logs'])),
            'user' => $this->when(isset($arr['user']), fn () => $this->filterUser($arr['user'])),
        ];
    }

    protected function filterUser(mixed $user): array
    {
        $arr = is_array($user) ? $user : (array) $user;
		logger('user');
		logger(json_encode(array_intersect_key($arr, array_flip(['first_name', 'last_name']))));
        return array_intersect_key($arr, array_flip(['first_name', 'last_name']));
    }

    protected function filterMedia(mixed $media): ?array
    {
        if ($media === null) {
            return null;
        }
        $arr = is_array($media) ? $media : (array) $media;
        return array_intersect_key($arr, array_flip(['path', 'name', 'type', 'size']));
    }

    protected function filterLogs(mixed $logs): array
    {
        if (! is_array($logs)) {
            return [];
        }
        return array_map(function ($log) {
            $logArr = is_array($log) ? $log : (array) $log;
            return array_intersect_key($logArr, array_flip(['metadata']));
        }, $logs);
    }
}
