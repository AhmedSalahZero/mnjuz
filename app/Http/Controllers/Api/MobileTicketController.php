<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\MobileApi;
use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Services\Messaging\Channel;
use App\Services\PhoneService;
use App\Support\OrganizationRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * تذاكر الدعم: قائمة المحادثات المفتوحة والمغلقة.
 *
 * التذكرة في هذا النظام ليست كياناً مستقلاً يكتبه العميل، بل حالة المحادثة:
 * كل جهة اتصال لها تذكرة واحدة «أحدث» (is_latest) تحمل حالتها ومن أُسندت
 * إليه. ولذلك تُقرأ من chat_tickets مقرونةً بجهة الاتصال.
 *
 * تغيير الحالة والإسناد لهما نقطتاهما منذ إصدار سابق:
 *   POST /api/v1/toggle-ticket-status/{contactId}
 *   POST /api/v1/assign-ticket
 */
class MobileTicketController extends Controller
{
    use MobileApi;

    public function index(Request $request): JsonResponse
    {
        $organizationId = $this->organizationId($request);

        $query = DB::table('chat_tickets')
            ->join('contacts', 'contacts.id', '=', 'chat_tickets.contact_id')
            ->leftJoin('users', 'users.id', '=', 'chat_tickets.assigned_to')
            ->where('contacts.organization_id', $organizationId)
            ->whereNull('contacts.deleted_at')
            ->where('chat_tickets.is_latest', true);

        if ($request->filled('status')) {
            $query->where('chat_tickets.status', (string) $request->input('status'));
        }

        // قناة واحدة؛ والمجهولة تُتجاهل فيُرجَع الكلّ، كفلتر قائمة المحادثات.
        if ($channel = $this->channelFilter($request)) {
            $query->where('contacts.channel', $channel);
        }

        if ($request->filled('assigned_to')) {
            $assigned = $request->input('assigned_to');

            // «غير مُسندة» حالة يسأل عنها الموظّفون كثيراً، فلها قيمة صريحة.
            $assigned === 'unassigned'
                ? $query->whereNull('chat_tickets.assigned_to')
                : $query->where('chat_tickets.assigned_to', (int) $assigned);
        }

        // الموظّف لا يرى إلّا ما أُسند إليه — نفس ما يحكم قائمة المحادثات.
        if (OrganizationRole::isAgent($this->role($request))) {
            $query->where('chat_tickets.assigned_to', $request->user()->id);
        }

        if ($request->filled('search')) {
            $term = '%' . $request->input('search') . '%';
            $query->where(function ($q) use ($term) {
                $q->where('contacts.first_name', 'like', $term)
                    ->orWhere('contacts.last_name', 'like', $term)
                    ->orWhere('contacts.phone', 'like', $term)
                    // رقم زائر المحادثة المباشرة للعرض وحده، لا في phone.
                    ->orWhere('contacts.formatted_phone', 'like', $term);
            });
        }

        $rows = $query
            ->orderByDesc('chat_tickets.updated_at')
            ->paginate($this->perPage($request), [
                'chat_tickets.id',
                'chat_tickets.status',
                'chat_tickets.assigned_to',
                'chat_tickets.assigned_seen',
                'chat_tickets.updated_at',
                'contacts.id as contact_id',
                'contacts.uuid as contact_uuid',
                'contacts.first_name',
                'contacts.last_name',
                'contacts.phone',
                'contacts.formatted_phone',
                'contacts.channel',
                'contacts.channel_identifier',
                'contacts.latest_chat_created_at',
                'users.first_name as agent_first_name',
                'users.last_name as agent_last_name',
            ]);

        $maskPhones = Contact::mobileMasksPhonesFor($organizationId);

        $rows->getCollection()->transform(fn ($row) => [
            'id' => (int) $row->id,
            'status' => $row->status,
            'contact_id' => (int) $row->contact_id,
            'contact_uuid' => $row->contact_uuid,
            'contact_name' => trim(($row->first_name ?? '') . ' ' . ($row->last_name ?? '')) ?: null,
            'phone' => $maskPhones && $row->phone ? Str::mask($row->phone, '*', 4) : $row->phone,
            // إضافية: القناة، ومعرّف العميل عليها، والرقم للعرض (زائر المحادثة
            // المباشرة رقمه هنا وحده، وphone له null).
            'channel' => $row->channel ?: Channel::DEFAULT,
            'channel_identifier' => $row->channel_identifier,
            'formatted_phone_number' => $this->displayPhone($row, $maskPhones),
            'assigned_to' => $row->assigned_to ? (int) $row->assigned_to : null,
            'assigned_to_name' => $row->assigned_to
                ? (trim(($row->agent_first_name ?? '') . ' ' . ($row->agent_last_name ?? '')) ?: null)
                : null,
            'assigned_seen' => (bool) $row->assigned_seen,
            'last_message_at' => $row->latest_chat_created_at,
            'updated_at' => $row->updated_at,
        ]);

        return $this->paginated($rows);
    }

    /** عدّادات الشاشة: مفتوحة ومغلقة وغير مُسندة. */
    public function summary(Request $request): JsonResponse
    {
        $organizationId = $this->organizationId($request);

        $base = fn () => DB::table('chat_tickets')
            ->join('contacts', 'contacts.id', '=', 'chat_tickets.contact_id')
            ->where('contacts.organization_id', $organizationId)
            ->whereNull('contacts.deleted_at')
            ->where('chat_tickets.is_latest', true);

        $channel = $this->channelFilter($request);

        $scope = function ($query) use ($request, $channel) {
            if (OrganizationRole::isAgent($this->role($request))) {
                $query->where('chat_tickets.assigned_to', $request->user()->id);
            }

            if ($channel) {
                $query->where('contacts.channel', $channel);
            }

            return $query;
        };

        return $this->ok([
            'open' => $scope($base())->where('chat_tickets.status', 'open')->count(),
            'closed' => $scope($base())->where('chat_tickets.status', 'closed')->count(),
            'unassigned' => $scope($base())->whereNull('chat_tickets.assigned_to')->count(),
        ]);
    }

    private function channelFilter(Request $request): ?string
    {
        $channel = $request->input('channel');

        return Channel::normalize(is_string($channel) ? $channel : null);
    }

    /** الرقم المعروض: العمود المحفوظ، وإلا صياغة phone — كما في Contact. */
    private function displayPhone(object $row, bool $mask): ?string
    {
        $display = $row->formatted_phone;

        if ($display === null || $display === '') {
            $display = $row->phone ? PhoneService::formatForDisplay($row->phone) : null;
        }

        if ($display === null || $display === '') {
            return null;
        }

        return $mask ? Contact::maskDisplayPhone($display) : $display;
    }
}
