<?php

namespace Modules\FlowBuilder\Controllers;

use App\Http\Controllers\Controller as BaseController;
use App\Helpers\CustomHelper;
use App\Models\Addon;
use Modules\FlowBuilder\Models\Flow;
use App\Models\Organization;
use App\Models\Setting;
use Modules\FlowBuilder\Services\FlowService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
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
        $data['flow'] = Flow::where('uuid', $uuid)->firstOrFail();
        
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

        $flow = Flow::where('uuid', $uuid)->firstOrFail();

        $data = [
            'name' => $data['name'] ?? $flow->name,
            'description' => $data['description'] ?? $flow->description,
            'metadata' => $data['metadata'] ?? $flow->metadata,
        ];

        $flow = $this->flowService->updateFlow($uuid, $data, $request->publish);

        // Check if the request expects JSON response
        if (request()->expectsJson()) {
            return response()->json($flow);
        }

        // If it's an Inertia request, redirect back
        return Redirect::back()->with(
            'status', [
                'type' => 'success', 
                'message' => __('Flow automation updated successfully!')
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
        $flow = $this->flowService->uploadMedia($request, $uuid, $stepId);

        return response()->json($flow);
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
