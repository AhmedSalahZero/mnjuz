<?php

namespace Modules\FlowBuilder\Controllers;

use App\Http\Controllers\Controller as BaseController;
use App\Helpers\CustomHelper;
use App\Models\Addon;
use Modules\FlowBuilder\Models\Flow;
use App\Models\Organization;
use App\Models\Setting;
use Modules\FlowBuilder\Services\FlowService;
use Modules\FlowBuilder\Support\FlowSecrets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;

class FlowController extends BaseController
{
    protected $flowService;

    public function __construct(FlowService $flowService)
    {
        $this->flowService = $flowService;
    }

    /**
     * Display a listing of flows.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        if(!CustomHelper::isModuleEnabled('Flow builder')){
            abort(404);
        }

        $data['title'] = __('Settings');
        $data['aimodule'] = CustomHelper::isModuleEnabled('AI Assistant');
        $data['fbmodule'] = CustomHelper::isModuleEnabled('Flow builder');
        $data['rows'] = $this->flowService->getRows($request);
        $data['filters'] = request()->all();

        return Inertia::render('FlowBuilder::User/Index', $data);
    }

    public function view(Request $request, $uuid)
    {
        $data['uuid'] = $uuid;
        $flow = Flow::ownedOrAbort($uuid);
        // لا يُحفظ: النسخة المرسلة إلى المتصفح وحدها بلا كلمات مرور SMTP.
        $flow->metadata = FlowSecrets::redact($flow->metadata);
        $data['flow'] = $flow;
        
        // Get contact groups for the current organization
        $organizationId = session()->get('current_organization');
        $contactGroups = \App\Models\ContactGroup::where('organization_id', $organizationId)
            ->select('id', 'name')
            ->orderBy('name')
            ->get()
            ->map(function($group) {
                return [
                    'value' => $group->id,
                    'label' => $group->name
                ];
            });

        // Get contact fields for the current organization
        $contactFields = \App\Models\ContactField::where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->orderBy('position')
            ->get()
            ->map(function($field) {
                return [
                    'id' => $field->id,
                    'name' => $field->name,
                    'type' => $field->type,
                    'value' => $field->value,
                    'required' => $field->required
                ];
            });

        // Get available actions from config file
        $actionsConfig = config('FlowBuilder.actions', []);
        $availableActions = [];
        foreach ($actionsConfig as $action => $enabled) {
            if ($enabled === true) {
                $availableActions[] = $action;
            }
        }

        $data['contactGroups'] = $contactGroups;
        $data['contactFields'] = $contactFields;
        $data['availableActions'] = $availableActions;
        // ما تختار منه عقدة البداية قنوات الـ flow.
        $data['automationChannels'] = \App\Services\Messaging\AutomationChannels::options();

        // خطوة «تحويل إلى موظف»: الأعضاء النشطون بالمعرّف والاسم فقط — لا
        // نقطة /team التي تُرجع صفوف users كاملة. الأتمتة نفسها تُعيد الفحص
        // عند كل تشغيل، فمن يغادر بعد الحفظ لا يُسند إليه.
        $data['teamMembers'] = \App\Models\Team::query()
            ->where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->where('status', 'active')
            ->whereHas('user')
            ->with('user:id,first_name,last_name')
            ->get()
            ->map(fn ($member) => [
                'value' => (int) $member->user_id,
                'label' => trim(($member->user->first_name ?? '') . ' ' . ($member->user->last_name ?? ''))
                    ?: __('Team member') . ' #' . $member->user_id,
            ])
            ->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        // بلا تذاكر لا إسناد: الخطوة تنبّه التاجر في المحرّر.
        $data['ticketingActive'] = (bool) \App\Models\Organization::find($organizationId)?->getTicketingActive();

        return Inertia::render('FlowBuilder::User/View', $data);
    }

    /**
     * Store a newly created flow in storage.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string'
        ]);

        $flow = $this->flowService->createFlow($data);

        return redirect('/automation/flows/'.$flow->uuid)->with(
            'status', [
                'type' => 'success', 
                'message' => __('Flow automation created successfully!')
            ]
        );
    }

    public function update(Request $request, $uuid)
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'metadata' => 'nullable|json', // Assuming metadata is an array; adjust if necessary.
            'publish' => 'sometimes|boolean'
        ]);

        $flow = Flow::ownedOrAbort($uuid);

        $data = [
            'name' => $data['name'] ?? $flow->name,
            // array_key_exists لا ??: الوصف المُفرَّغ يصل null، و?? كان يُبقي القديم.
            'description' => $request->exists('description') ? ($data['description'] ?? null) : $flow->description,
            'metadata' => $data['metadata'] ?? $flow->metadata,
        ];

        $response = $this->flowService->updateFlow($uuid, $data, $request->publish);

        // Check if the request expects JSON response
        if (request()->expectsJson()) {
            return $response;
        }

        // If it's an Inertia request, redirect back
        $result = $response->getData(true);

        return Redirect::back()->with(
            'status', [
                'type' => $result['success'] ? 'success' : 'error',
                'message' => $result['success'] ? __('Flow automation updated successfully!') : $result['message'],
            ]
        );
    }

    public function duplicate(Request $request, $uuid)
    {
        $flow = $this->flowService->duplicateFlow($uuid);

        return Redirect::back()->with(
            'status', [
                'type' => 'success', 
                'message' => __('Flow copied successfully!')
            ]
        );
    }

    public function uploadMedia(Request $request, $uuid, $stepId)
    {
        // المسار أوّلاً: كان الملف يُخزَّن قبل التحقق من وجود المسار وملكيته.
        $flow = Flow::ownedOrAbort($uuid);

        // ردّ 422 صريح: معالج الأخطاء يحوّل ValidationException في طلبات JSON
        // إلى 500، فلا يعرف المحرّر سبب الرفض.
        $type = $request->input('type');
        $validator = Validator::make($request->all(), [
            'type' => 'nullable|in:' . implode(',', array_keys(FlowService::MEDIA_RULES)),
            'file' => ['required', 'file', 'max:' . FlowService::mediaMaxKilobytes($type)],
        ]);

        $reason = $validator->fails()
            ? $validator->errors()->first()
            : FlowService::mediaRejection($type, $request->file('file'));

        if ($reason !== null) {
            return response()->json(['success' => false, 'message' => $reason], 422);
        }

        $media = $this->flowService->uploadMedia($request, $flow, $stepId);

        return response()->json($media);
    }

    /**
     * Remove the specified flow from storage.
     *
     * @param Flow $flow
     * @return \Illuminate\Http\Response
     */
    public function destroy($uuid)
    {
        $this->flowService->deleteFlow($uuid);
        
        return Redirect::back()->with(
            'status', [
                'type' => 'success', 
                'message' => __('Flow automation deleted successfully!')
            ]
        );
    }
}
