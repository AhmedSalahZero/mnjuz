<?php

namespace App\Services;

use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\TicketComment;
use Illuminate\Validation\ValidationException;
use DB;
use Validator;

class TicketService
{
    public function store(object $request){
        Ticket::create([
            'reference' => 'SUP-' . sprintf('%06d', Ticket::count() + 1) . '-' . now()->format('ymd'),
            'user_id' => auth()->user()->role === 'user' ? auth()->user()->id : $request->user,
            'category_id' => $request->category,
            'subject' => $request->subject,
            'message' => $request->message,
            'assigned_to' => auth()->user()->role === 'user' ? null : auth()->user()->id,
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now()
        ]);
    }

    public function assignTicket(object $request, $ticketUuid){
        $ticket = Ticket::where('uuid', $ticketUuid)->first();
        $ticket->update([
            'assigned_to' => $request->user,
            'updated_at' => now()
        ]);
    }

    public function changeStatus(object $request, $ticketUuid){
        $ticket = Ticket::where('uuid', $ticketUuid)->first();
        $ticket->update([
            'status' => $request->status,
            'updated_at' => now()
        ]);
    }

    public function changePriority(object $request, $ticketUuid){
        $ticket = Ticket::where('uuid', $ticketUuid)->first();
        $ticket->update([
            'priority' => $request->priority,
            'updated_at' => now()
        ]);
    }

    public function comment(object $request, $ticketUuid){
        $ticket = Ticket::where('uuid', $ticketUuid)->first();
        TicketComment::create([
            'ticket_id' => $ticket->id,
            'user_id' => auth()->user()->id,
            'message' => $request->message,
            'created_at' => now(),
            'updated_at' => now()
        ]);
    }

    public function markAsRead($ticketUuid){
        $ticket = Ticket::where('uuid', $ticketUuid)->first();
        TicketComment::where('ticket_id', $ticket->id)->update([
            'seen' => 1
        ]);
    }
}