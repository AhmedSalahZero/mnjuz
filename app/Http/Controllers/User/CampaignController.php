<?php

namespace App\Http\Controllers\User;

use App\Exports\CampaignDetailsExport;
use App\Http\Controllers\Controller as BaseController;
use App\Http\Requests\StoreCampaign;
use App\Http\Resources\CampaignLogResource;
use App\Http\Resources\CampaignResource;
use App\Jobs\ProcessCampaignMessagesJob;
use App\Jobs\RetryCampaignLogJob;
use App\Models\Campaign;
use App\Models\CampaignLog;
use App\Models\ContactGroup;
use App\Models\Organization;
use App\Models\Template;
use App\Services\CampaignMediaHistoryService;
use App\Services\CampaignRetryService;
use App\Services\CampaignService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;

class CampaignController extends BaseController
{
    private $campaignService;

    public function __construct(CampaignService $campaignService)
    {
        $this->campaignService = $campaignService;
    }

    public function index(Request $request, $uuid = null){
        $organizationId = session()->get('current_organization');
        if($uuid == null){
            $searchTerm = $request->query('search');
            $settings = Organization::where('id', $organizationId)->first();
            $rows = CampaignResource::collection(
                Campaign::with(['template', 'campaignLogs'])
                    ->where('organization_id', $organizationId)
                    ->where('deleted_at', null)
                    ->where(function ($query) use ($searchTerm) {
                        $query->where('name', 'like', '%' . $searchTerm . '%')
                              ->orWhereHas('template', function ($templateQuery) use ($searchTerm) {
                                  $templateQuery->where('name', 'like', '%' . $searchTerm . '%');
                              });
                    })
                    ->latest()
                    ->paginate(10)
            );

            return Inertia::render('User/Campaign/Index', [ 'title'=> __('Campaigns'), 'allowCreate' => true, 'rows' => $rows, 'filters' => request()->all(['search']), 'settings' => $settings ]);
        } else if($uuid == 'create'){
            $data['settings'] = Organization::where('id', $organizationId)->first();
            $data['templates'] = Template::where('organization_id', $organizationId)
                ->where('deleted_at', null)
                ->where('status', 'APPROVED')
                ->get();

            $data['contactGroups'] = ContactGroup::where('organization_id', $organizationId)
                ->where('deleted_at', null)
                ->get();

            $data['title'] = __('Create campaign');

            return Inertia::render('User/Campaign/Create', $data);
        } else {
            $campaign = Campaign::with('contactGroup', 'template')->where('uuid', $uuid)->first();

            if ($campaign) {
                $counts = $campaign->getCounts();
                $campaign['total_message_count'] = $counts->total_message_count ?? 0;
                $campaign['total_sent_count']     = $counts->total_sent_count ?? 0;
                $campaign['total_delivered_count']= $counts->total_delivered_count ?? 0;
                $campaign['total_failed_count']   = $counts->total_failed_count ?? 0;
                $campaign['total_read_count']     = $counts->total_read_count ?? 0;
            }

            $data['campaign'] = $campaign;

            // عدد ما سيُرسَل فعلاً لو ضُغط زر إعادة الإرسال — يُعرَض على الزر
            // نفسه وفي تأكيد الإرسال، فلا يُفاجأ العميل بعدد لم يتوقّعه.
            $data['resendableCount'] = $campaign
                ? $this->resendableLogIds($campaign->id)->count()
                : 0;

            $data['filters'] = request()->all(['search']);

            $searchTerm = $request->query('search');
            $data['rows'] = CampaignLogResource::collection(
                $campaign
                    ? CampaignLog::with('contact', 'chat.logs', 'attempts')
                        ->where('campaign_id', $campaign->id)
                        ->where(function ($query) use ($searchTerm) {
                            $query->whereHas('contact', function ($contactQuery) use ($searchTerm) {
                                $contactQuery->where('first_name', 'like', '%' . $searchTerm . '%')
                                             ->orWhere('last_name', 'like', '%' . $searchTerm . '%')
                                             ->orWhere('phone', 'like', '%' . $searchTerm . '%');
                            });
                        })
                        ->orderBy('id')
                        ->paginate(10)
                    : CampaignLog::whereRaw('0')->paginate(10)
            );
            $data['title'] = __('View campaign');

            return Inertia::render('User/Campaign/View', $data);
        }
    }

    public function store(StoreCampaign $request){
        $this->campaignService->store($request);

        return Redirect::route('campaigns')->with(
            'status', [
                'type' => 'success', 
                'message' => __('Campaign created successfully!')
            ]
        );
    }

    public function mediaHistory(Request $request)
    {
        $validated = $request->validate([
            'type' => 'required|in:IMAGE,DOCUMENT,VIDEO',
        ]);

        $organizationId = (int) session()->get('current_organization');

        return response()->json([
            'data' => app(CampaignMediaHistoryService::class)->listForOrganization(
                $organizationId,
                $validated['type']
            ),
        ]);
    }

    public function deleteMediaHistory(string $uuid)
    {
        $organizationId = (int) session()->get('current_organization');
        $deleted = app(CampaignMediaHistoryService::class)->deleteForOrganization($organizationId, $uuid);

        if (!$deleted) {
            return response()->json(['message' => __('Not found')], 404);
        }

        return response()->json(['success' => true]);
    }

    public function export($uuid = null){
        return Excel::download(new CampaignDetailsExport($uuid), 'campaign.csv');
    }

    public function delete($uuid){
        $this->campaignService->destroy($uuid);

        return Redirect::back()->with(
            'status', [
                'type' => 'success', 
                'message' => __('Row deleted successfully!')
            ]
        );
    }
    /**
     * إعادة إرسال الرسائل الفاشلة **لحملة واحدة** يحدّدها المستخدم.
     *
     * كان الزر داخل صفحة حملة بعينها لكنه ينادي مساراً يعيد إرسال فشل كل
     * حملات المنظمة، فضغطة واحدة أطلقت آلاف الرسائل وأدّت إلى حظر رقم العميل.
     * صار النطاق حملة واحدة صراحةً — والمسار الشامل أُزيل بالكامل.
     */
    public function resendFailed($uuid)
    {
        $organizationId = session()->get('current_organization');

        // نقتصر على الحملات التي يمكن أن تُستأنف فعلاً: الحملة المجدولة لا
        // يلتقطها ProcessCampaignMessagesJob (يشترط ongoing) فتبقى سجلّاتها
        // عالقة في pending بلا إرسال ولا رسالة خطأ.
        $campaign = Campaign::where('uuid', $uuid)
            ->where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->whereIn('status', ['completed', 'ongoing'])
            ->first();

        if (!$campaign) {
            return Redirect::back()->with('status', [
                'type' => 'error',
                'message' => __('This campaign cannot be resent.'),
            ]);
        }

        $resendable = $this->resendableLogIds($campaign->id);

        if ($resendable->isEmpty()) {
            return Redirect::back()->with('status', [
                'type' => 'info',
                'message' => __('No failed campaign messages to resend.'),
            ]);
        }

        DB::transaction(function () use ($campaign, $resendable) {
            CampaignLog::whereIn('id', $resendable)->update([
                'status' => 'pending',
                'metadata' => null,
                'chat_id' => null,
                'updated_at' => now(),
            ]);

            if ($campaign->status === 'completed') {
                $campaign->update(['status' => 'ongoing']);
            }
        });

        ProcessCampaignMessagesJob::dispatch()
            ->onQueue('campaign-messages')
            ->afterCommit();

        return Redirect::back()->with('status', [
            'type' => 'success',
            'message' => __(':count failed campaign message(s) queued for resending.', [
                'count' => $resendable->count(),
            ]),
        ]);
    }

    /**
     * معرّفات السجلات الفاشلة التي **يصحّ** إعادة إرسالها لهذه الحملة.
     *
     * نستبعد الفشل النهائي (الرقم ليس على واتساب، المستلم رفض الاستقبال…):
     * إعادة الإرسال إليه لن تنجح أبداً، وتكرارها هو ما يخفض تقييم جودة الرقم
     * حتى الحظر — وهو ما وقع فعلاً عند إعادة الإرسال الشاملة.
     *
     * @return \Illuminate\Support\Collection<int, int>
     */
    private function resendableLogIds(int $campaignId)
    {
        $logs = CampaignLog::where('campaign_id', $campaignId)
            ->where('status', 'failed')
            ->pluck('chat_id', 'id');

        if ($logs->isEmpty()) {
            return collect();
        }

        $retryService = app(CampaignRetryService::class);

        // آخر بلاغ فشل لكل محادثة يحمل كود الخطأ الذي يحدّد قابلية الإعادة.
        $errorsByChat = [];
        $chatIds = $logs->filter()->values();
        if ($chatIds->isNotEmpty()) {
            DB::table('chat_status_logs')
                ->whereIn('chat_id', $chatIds)
                ->where('metadata', 'like', '%"status":"failed"%')
                ->orderBy('id')
                ->select('chat_id', 'metadata')
                ->get()
                ->each(function ($row) use (&$errorsByChat) {
                    $decoded = json_decode($row->metadata, true);
                    if (($decoded['status'] ?? null) === 'failed') {
                        $errorsByChat[$row->chat_id] = $decoded['errors'] ?? [];
                    }
                });
        }

        return $logs->filter(function ($chatId, $logId) use ($errorsByChat, $retryService) {
            // بلا محادثة = رُفض الطلب قبل الإرسال، فلا كود خطأ نهائي هنا.
            if (!$chatId || !array_key_exists($chatId, $errorsByChat)) {
                return true;
            }

            return $retryService->isRetryableFailure($errorsByChat[$chatId]);
        })->keys();
    }
}
