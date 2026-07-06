<?php

namespace App\Http\Controllers\Admin;

use DB;
use App\Http\Controllers\Controller as BaseController;
use App\Http\Resources\TicketResource;
use App\Http\Requests\StoreTicket;
use App\Http\Requests\StoreTicketComment;
use App\Http\Requests\StoreTicketStatus;
use App\Http\Requests\StoreTicketPriority;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\TicketComment;
use App\Models\User;
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
            return Inertia::render('Admin/Ticket/Index', [
                'title' => __('Support'),
                'allowCreate' => true,
                'rows' => TicketResource::collection(
                    Ticket::whereHas('user', function ($query) {
                        $query->whereNull('deleted_at');
                    })->with(['user', 'agent'])->orderBy('updated_at', 'desc')->paginate(10)
                ),
            ]);
        } else if($uuid === 'create'){
            $data['title'] = __('Create ticket');
            $data['categories'] = TicketCategory::get();
            return Inertia::render('Admin/Ticket/Create', $data);
        } else {
            $ticket = Ticket::with(['commentsWithUser', 'category', 'agent'])->where('uuid', $uuid)->first();
            $users = User::where('role', '!=', 'user')->get();
            return Inertia::render('Admin/Ticket/View', [
                'title' => __('Support'),
                'ticket' => $ticket,
                'users' => $users
            ]);
        }
    }

    public function store(StoreTicket $request){
        $this->ticketService->store($request);

        return Redirect::route('tickets')->with(
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
        $this->ticketService->changePriority($request, $ticketUuid);

        return response()->json(['success' => true]);
    }

    public function assign(Request $request, $ticketUuid){
        $this->ticketService->assignTicket($request, $ticketUuid);

        return response()->json(['success' => true]);
    }
}