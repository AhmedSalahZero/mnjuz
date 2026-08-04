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
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Services\TicketService;
use App\Services\WazSyncService;
use App\Exceptions\WazBusinessException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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
            $defaultUrl = 'https://business.waz.com.sa/forms/ticket?col=col-md-8+col-md-offset-2';
            $ticketFormUrl = null;
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
            if ($ticketFormUrl === null) {
                $globalUrl = Setting::getValueByKey('support_ticket_form_url');
                $ticketFormUrl = (is_string($globalUrl) && $globalUrl !== '')
                    ? $globalUrl
                    : $defaultUrl;
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

    public function store(StoreTicket $request, WazSyncService $waz){
        $ticket = $this->ticketService->store($request);

        // نفتح التذكرة في واز أعمال أيضاً ليراها فريق الدعم في نظامهم.
        // فشل المزامنة لا يُلغي التذكرة المحلية — العميل أرسل طلبه فعلاً —
        // لكننا ننبّهه أن الدعم قد يتأخر في رؤيتها.
        $synced = true;
        if ($waz->enabled()) {
            try {
                // false = الحساب غير مربوط، فلن يراها الدعم — ننبّه أيضاً.
                $synced = $waz->syncTicket($ticket, (int) session('current_organization'));
            } catch (WazBusinessException $e) {
                $synced = false;
                Log::error('Waz: failed to open support ticket', [
                    'ticket_id' => $ticket->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return Redirect::route('support')->with(
            'status', $synced
                ? ['type' => 'success', 'message' => __('Ticket created successfully')]
                : ['type' => 'warning', 'message' => __('Your ticket was saved, but syncing it with support is delayed.')]
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
