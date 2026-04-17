<?php

namespace App\Services;

use App\Helpers\DateTimeHelper;
use App\Http\Resources\ChatNoteResource;
use App\Models\ChatLog;
use App\Models\ChatNote;
use App\Models\Contact;
use App\Models\ChatTicket;
use App\Models\Organization;
use App\Support\OrganizationRole;

class ChatNoteService
{
    private function canAccessConversation(Contact $contact): bool
    {
        $organizationId = (int) session()->get('current_organization');
        if ((int) $contact->organization_id !== $organizationId) {
            return false;
        }

        $user = auth()->user();
        if (!$user) {
            return false;
        }

        $role = $user->getRoleNameForOrganization($organizationId);
        if ($role === '') {
            $role = OrganizationRole::OWNER;
        }

        if ($role !== 'agent') {
            return true;
        }

        $organization = Organization::find($organizationId);
        if (!$organization || !$organization->getTicketingActive() || $organization->getAllowAgentsToViewAllChats()) {
            return true;
        }

        $ticket = ChatTicket::where('contact_id', $contact->id)
            ->where('is_latest', true)
            ->first();

        return $ticket && (int) $ticket->assigned_to === (int) $user->id;
    }

    private function authorizeConversation(Contact $contact): void
    {
        if (!$this->canAccessConversation($contact)) {
            abort(403, __('You are not allowed to access this conversation.'));
        }
    }

    public function get(object $request)
    {
        $rows = (new ChatNote)->listAll($request->query('search'));

        return ChatNoteResource::collection($rows);
    }

    public function getByUuid($uuid = null)
    {
        return ChatNote::where('id', $uuid)->first();
    }

    public function store(object $request, $uuid = NULL)
    {
        $contact = Contact::where('uuid', $request->contact)->firstOrFail();
        $this->authorizeConversation($contact);

        $note = $uuid === null ? new ChatNote() : ChatNote::where('uuid', $uuid)->firstOrFail();
        $note->contact_id = $contact->id;
        $note->content = $request->notes;
        $note->created_by = auth()->user()->id;
        $note->save();

        ChatLog::insert([
            'contact_id' => $contact->id,
            'entity_type' => 'notes',
            'entity_id' => $note->id,
            'created_at' => now()
        ]);

        return $note;
    }

    public function delete($uuid)
    {
        $note = ChatNote::where('uuid', $uuid)->firstOrFail();
        $contact = Contact::where('id', $note->contact_id)->firstOrFail();
        $this->authorizeConversation($contact);
        $note->deleted_at = date('Y-m-d H:i:s');
        $note->deleted_by = auth()->user()->id;
        $note->save();
    } 
}
