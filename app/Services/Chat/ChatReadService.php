<?php

namespace App\Services\Chat;

use Illuminate\Support\Facades\DB;

/**
 * Centralises the "mark inbound conversation as read" logic so every code
 * path (opening a chat, receiving a message while the thread is open, an
 * automated reply, the mobile API, closing a ticket) applies the exact same
 * rule. Diverging implementations previously left conversations showing as
 * unread even after they had effectively been handled.
 */
class ChatReadService
{
    /**
     * Mark every unread inbound message of a contact as read.
     *
     * @param  int       $contactId
     * @param  int|null  $organizationId  When provided, scopes the update to
     *                                     the organization for extra safety.
     * @return int  Number of rows updated.
     */
    public static function markInboundAsRead(int $contactId, ?int $organizationId = null): int
    {
        $query = DB::table('chats')
            ->where('contact_id', $contactId)
            ->where('type', 'inbound')
            ->whereNull('deleted_at')
            ->where('is_read', 0);

        if ($organizationId !== null) {
            $query->where('organization_id', $organizationId);
        }

        return $query->update(['is_read' => 1]);
    }
}
