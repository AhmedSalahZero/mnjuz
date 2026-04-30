<?php

namespace App\Services\Chat;

use App\Models\ChatTicket;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;

/**
 * Resolves which user ids inside an organization should receive a chat
 * event (both as Pusher presence-channel listeners and as FCM push
 * notification recipients).
 *
 * Centralising this here so the realtime event and the push notification
 * job apply the EXACT same visibility rules — diverging here means agents
 * could see a chat in real time but never receive a push, or vice-versa.
 *
 * Visibility rules (matching {@see \App\Models\Contact} listing logic):
 *  - Owners / admins (any non-`agent` role) always receive the event.
 *  - If ticketing is OFF or "agents see all chats" is enabled, all team
 *    members receive the event.
 *  - Otherwise, the agent assigned to the latest open ticket of this
 *    contact also receives it. No agent receives it when the contact has
 *    no ticket or the ticket is unassigned.
 */
class ChatChannelRecipients
{
    /**
     * @return array<int, int>  Distinct user ids, ordered as discovered.
     */
    public function resolveUserIds(int $organizationId, ?int $contactId): array
    {
        $organization = Organization::find($organizationId);
        if (!$organization) {
            return [];
        }

        $ticketingActive = $organization->getTicketingActive();
        $allowAgentsViewAll = $organization->getAllowAgentsToViewAllChats();

        $teams = DB::table('teams')
            ->where('organization_id', $organizationId)
            ->get(['user_id', 'role']);

        if (!$ticketingActive || $allowAgentsViewAll) {
            return $teams->pluck('user_id')
                ->map(static fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();
        }

        // Tickets enabled + agents do NOT see all chats. Non-agent roles
        // always receive; agents only receive if they are the assignee of
        // the contact's latest ticket.
        $userIds = $teams->where('role', '!=', 'agent')
            ->pluck('user_id')
            ->map(static fn ($id) => (int) $id)
            ->all();

        if ($contactId) {
            $assignedAgentId = $this->assignedAgentForContact($contactId, $organizationId);
            if ($assignedAgentId !== null) {
                $assignedTeam = $teams->firstWhere('user_id', $assignedAgentId);
                if ($assignedTeam && $assignedTeam->role === 'agent') {
                    $userIds[] = $assignedAgentId;
                }
            }
        }

        return array_values(array_unique(array_map('intval', $userIds)));
    }

    /**
     * Look up the agent currently assigned to the latest ticket for this
     * contact, restricted to the broadcasting organization to prevent any
     * cross-tenant leakage. The {@see ChatTicket} model has no direct
     * organization relation, so we constrain via the contacts table.
     */
    private function assignedAgentForContact(int $contactId, int $organizationId): ?int
    {
        $ticket = ChatTicket::query()
            ->where('contact_id', $contactId)
            ->where('is_latest', true)
            ->whereExists(function ($q) use ($contactId, $organizationId) {
                $q->select(DB::raw(1))
                    ->from('contacts')
                    ->whereColumn('contacts.id', 'chat_tickets.contact_id')
                    ->where('contacts.id', $contactId)
                    ->where('contacts.organization_id', $organizationId);
            })
            ->first(['assigned_to']);

        if (!$ticket || !$ticket->assigned_to) {
            return null;
        }

        return (int) $ticket->assigned_to;
    }
}
