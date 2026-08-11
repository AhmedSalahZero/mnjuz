<?php

namespace App\Http\Controllers\User;

use App\Helpers\DateTimeHelper;
use App\Http\Controllers\Controller as BaseController;
use App\Models\AutoReply;
use App\Models\Chat;
use App\Models\ChatLog;
use App\Models\ChatTicket;
use App\Models\ChatTicketLog;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use App\Support\OrganizationRole;
use Illuminate\Http\Request;
use Redirect;
use App\Services\ActivityLogger;

class ChatTicketController extends BaseController
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

    public function index(Request $request, $uuid = null)
    {
        //
    }

    public function update(Request $request, $uuid)
    { 
        $contact = Contact::where('uuid', $uuid)->firstOrFail();
        $this->authorizeConversation($contact);

		$contact->toggleTicketStatus($request->status);

        ActivityLogger::log(
            $request->status === 'closed' ? ActivityLogger::TICKET_CLOSED : ActivityLogger::TICKET_REOPENED,
            trim(($contact->first_name ?? '') . ' ' . ($contact->last_name ?? '')) ?: $contact->phone,
            'contact',
            $contact->id,
            ['status' => $request->status]
        );

        return Redirect::back()->with(
            'status', [
                'type' => 'success', 
                'message' => __('Status updated successfully!')
            ]
        );
    }

    public function assign(Request $request, $uuid)
    { 
		/**
		 * @var Contact $contact
		 */
        $contact = Contact::where('uuid', $uuid)->firstOrFail();
        $this->authorizeConversation($contact);
        $team = Team::where('organization_id', session()->get('current_organization'))->where('user_id', $request->id)->first();
        $user = User::where('id', $request->id)->first();
        
        if($team && $user){
            $contact->assignToUserThroughTicket($user);

            ActivityLogger::log(
                ActivityLogger::TICKET_ASSIGNED,
                trim(($contact->first_name ?? '') . ' ' . ($contact->last_name ?? '')) ?: $contact->phone,
                'contact',
                $contact->id,
                ['assigned_to' => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: $user->email]
            );

            return Redirect::back()->with(
                'status', [
                    'type' => 'success', 
                    'message' => __('Ticket assigned successfully!')
                ]
            );
        }
    }
}
