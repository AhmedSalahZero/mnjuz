<?php

namespace App\Http\Controllers\User;

use DB;
use App\Http\Controllers\Controller as BaseController;
use App\Http\Resources\TicketResource;
use App\Http\Requests\StoreTicket;
use App\Http\Requests\StoreTicketComment;
use App\Http\Requests\StoreTicketStatus;
use App\Http\Requests\StoreTicketPriority;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Services\TicketService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;

class TicketController extends BaseController
{
    private $ticketService;

    public function __construct(TicketService $ticketService)
    {
        $this->ticketService = $ticketService;
    }

    public function index(Request $request, $uuid = null){
        if($uuid === null){
            $ticketFormUrl = 'https://business.waz.com.sa/forms/ticket?col=col-md-8+col-md-offset-2';
            $organizationId = session('current_organization');
            if ($organizationId) {
                $org = Organization::where('id', $organizationId)->first();
                if ($org && $org->metadata) {
                    $meta = json_decode($org->metadata, true) ?: [];
                    $custom = $meta['support']['ticket_form_url'] ?? null;
                    if (is_string($custom) && $custom !== '') {
                        $ticketFormUrl = $custom;
                    }
                }
            }

            return Inertia::render('User/Support/Index', [
                'title' => __('Support'),
                'allowCreate' => true,
                'ticketFormUrl' => $ticketFormUrl,
                'rows' => TicketResource::collection(
                    Ticket::where('user_id', auth()->user()->id)
                        ->latest()->paginate(10)
                ),
            ]);
        } else if ($uuid === 'create') {
            return Redirect::to('/support');
        } else {
            $ticket = Ticket::with(['commentsWithUser', 'category'])->where('uuid', $uuid)->first();
            return Inertia::render('User/Support/View', [
                'title' => __('View ticket'),
                'ticket' => $ticket
            ]);
        }
    }

    public function store(StoreTicket $request){
        $this->ticketService->store($request);

        return Redirect::route('support')->with(
            'status', [
                'type' => 'success', 
                'message' => __('Ticket created successfully')
            ]
        );
    }

    public function comment(StoreTicketComment $request, $ticketUuid){
        $this->ticketService->comment($request, $ticketUuid);

        return Redirect::back()->with(
            'status', [
                'type' => 'success', 
                'message' => __('Comment added successfully')
            ]
        );
    }

    public function changeStatus(StoreTicketStatus $request, $ticketUuid){
        $this->ticketService->changeStatus($request, $ticketUuid);

        return Redirect::back()->with(
            'status', [
                'type' => 'success', 
                'message' => __('Ticket updated successfully')
            ]
        );
    }

    public function changePriority(StoreTicketPriority $request, $ticketUuid){
        $this->ticketService->changeStatus($request, $ticketUuid);

        return Redirect::back()->with(
            'status', [
                'type' => 'success', 
                'message' => __('Ticket updated successfully')
            ]
        );
    }
}