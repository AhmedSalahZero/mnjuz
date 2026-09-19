<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\WazBusinessException;
use App\Http\Controllers\Api\Concerns\MobileApi;
use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\TicketComment;
use App\Services\TicketService;
use App\Services\WazSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * تذاكر الدعم من التطبيق.
 *
 * ليست تذاكر المحادثات. تلك (`chat_tickets`) حالةُ محادثةِ عميلٍ وإسنادُها
 * لموظّف، وهي في MobileTicketController. وهذه (`tickets`) طلبٌ يفتحه صاحب
 * الحساب لفريق دعمنا.
 *
 * والتذكرة تُفتح في مكانين: عندنا، وفي واز أعمال حيث يعمل فريق الدعم فعلاً.
 * فواز مصدر الحقيقة للحالة والردود، ولذلك يُرجع الجلب القائمتين معاً كما
 * تفعل صفحة الويب.
 */
class MobileSupportTicketController extends Controller
{
    use MobileApi;

    private const STATUSES = ['open', 'pending', 'resolved', 'closed'];
    private const PRIORITIES = ['critical', 'high', 'medium', 'low'];

    public function __construct(private TicketService $tickets)
    {
    }

    public function index(Request $request, WazSyncService $waz): JsonResponse
    {
        $organizationId = $this->organizationId($request);

        $wazTickets = [];
        $wazAvailable = $waz->enabled() && $waz->companyId($organizationId) !== null;

        if ($wazAvailable) {
            try {
                $wazTickets = $waz->ticketsFor($organizationId);
            } catch (WazBusinessException $e) {
                // تعذّر الوصول إلى واز لا يُفرغ الشاشة: تذاكرنا المحلّية
                // تُعرض، والراية تُخبر التطبيق أن قائمة الدعم ناقصة الآن.
                $wazAvailable = false;
                Log::error('Waz: failed to load support tickets for mobile', [
                    'organization_id' => $organizationId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $rows = Ticket::with('category')
            ->where('user_id', $request->user()->id)
            ->latest()
            ->paginate($this->perPage($request, 10));

        $rows->getCollection()->transform(fn (Ticket $ticket) => $this->present($ticket));

        $payload = $this->paginated($rows)->getData(true);
        $payload['data']['categories'] = TicketCategory::orderBy('name')->get(['id', 'name'])
            ->map(fn ($category) => ['id' => (int) $category->id, 'name' => $category->name])
            ->all();
        $payload['data']['waz_tickets'] = $wazTickets;
        $payload['data']['waz_available'] = $wazAvailable;
        $payload['data']['statuses'] = self::STATUSES;
        $payload['data']['priorities'] = self::PRIORITIES;

        return response()->json($payload);
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        $ticket = $this->find($request, $uuid);

        if (!$ticket) {
            return $this->fail(404, __('Data not found'));
        }

        $ticket->load(['category', 'commentsWithUser']);

        // فتح التذكرة يعني قراءة ردودها — كما في الويب.
        $this->tickets->markAsRead($uuid);

        return $this->ok($this->present($ticket, true));
    }

    public function store(Request $request, WazSyncService $waz): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'category' => 'required|integer|exists:ticket_categories,id',
            'subject' => 'required|string|max:1024',
            'message' => 'required|string|max:1024',
        ]);

        if ($validator->fails()) {
            return $this->fail(400, __('The provided data is invalid.'), $validator->errors()->toArray());
        }

        $ticket = $this->tickets->store($request);

        // نفتحها في واز أيضاً ليراها فريق الدعم. وفشل المزامنة لا يُلغيها —
        // العميل أرسل طلبه فعلاً — لكن التطبيق يُخبره أن وصولها قد يتأخّر.
        $synced = true;

        if ($waz->enabled()) {
            try {
                $synced = $waz->syncTicket($ticket, $this->organizationId($request));
            } catch (WazBusinessException $e) {
                $synced = false;
                Log::error('Waz: failed to open support ticket from mobile', [
                    'ticket_id' => $ticket->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $data = $this->present($ticket->fresh(['category']));
        $data['synced_with_support'] = $synced;

        return $this->ok(
            $data,
            $synced
                ? __('Ticket created successfully')
                : __('Your ticket was saved, but syncing it with support is delayed.')
        );
    }

    public function comment(Request $request, string $uuid): JsonResponse
    {
        $ticket = $this->find($request, $uuid);

        if (!$ticket) {
            return $this->fail(404, __('Data not found'));
        }

        $validator = Validator::make($request->all(), ['message' => 'required|string|max:1024']);

        if ($validator->fails()) {
            return $this->fail(400, __('The provided data is invalid.'), $validator->errors()->toArray());
        }

        $this->tickets->comment($request, $uuid);

        return $this->ok(
            $this->present($ticket->fresh(['category', 'commentsWithUser']), true),
            __('Comment added successfully')
        );
    }

    public function changeStatus(Request $request, string $uuid): JsonResponse
    {
        return $this->change($request, $uuid, 'status', self::STATUSES);
    }

    public function changePriority(Request $request, string $uuid): JsonResponse
    {
        return $this->change($request, $uuid, 'priority', self::PRIORITIES);
    }

    /**
     * @param  array<int, string>  $allowed
     */
    private function change(Request $request, string $uuid, string $field, array $allowed): JsonResponse
    {
        $ticket = $this->find($request, $uuid);

        if (!$ticket) {
            return $this->fail(404, __('Data not found'));
        }

        $validator = Validator::make($request->all(), [
            $field => 'required|in:' . implode(',', $allowed),
        ]);

        if ($validator->fails()) {
            return $this->fail(400, __('The provided data is invalid.'), $validator->errors()->toArray());
        }

        // الخدمة المشتركة تكتب العمود وحده؛ ونقرأ القيمة من الطلب بعد التحقّق.
        $ticket->update([$field => $request->input($field), 'updated_at' => now()]);

        return $this->ok($this->present($ticket->fresh(['category'])), __('Ticket updated successfully'));
    }

    /**
     * تذكرة صاحبها هو الطالب.
     *
     * القصر على `user_id` مقصود: العنوان يحمل uuid، وبدونه يفتح أيُّ مستخدم
     * تذكرة غيره بمجرّد معرفة المعرّف.
     */
    private function find(Request $request, string $uuid): ?Ticket
    {
        return Ticket::where('uuid', $uuid)
            ->where('user_id', $request->user()->id)
            ->first();
    }

    /** @return array<string, mixed> */
    private function present(Ticket $ticket, bool $withComments = false): array
    {
        $data = [
            'uuid' => (string) $ticket->uuid,
            'reference' => $ticket->reference,
            'subject' => $ticket->subject,
            'message' => $ticket->message,
            'status' => $ticket->status,
            'priority' => $ticket->priority,
            'category' => $ticket->category ? [
                'id' => (int) $ticket->category->id,
                'name' => $ticket->category->name,
            ] : null,
            'created_at' => optional($ticket->created_at)->toDateTimeString(),
            'updated_at' => optional($ticket->updated_at)->toDateTimeString(),
        ];

        if ($withComments) {
            $data['comments'] = $ticket->commentsWithUser
                ->map(fn (TicketComment $comment) => [
                    'id' => (int) $comment->id,
                    'message' => $comment->message,
                    'seen' => (bool) $comment->seen,
                    'created_at' => optional($comment->created_at)->toDateTimeString(),
                    'user' => $comment->user ? [
                        'id' => (int) $comment->user->id,
                        'name' => trim($comment->user->first_name . ' ' . $comment->user->last_name),
                        'is_me' => (int) $comment->user->id === (int) auth()->id(),
                    ] : null,
                ])
                ->values()
                ->all();
        }

        return $data;
    }
}
