<?php

namespace App\Http\Controllers\User;

use App\Exports\ContactGroupsExport;
use App\Helpers\WebhookHelper;
use App\Imports\ContactGroupsImport;
use App\Http\Controllers\Controller as BaseController;
use App\Models\Contact;
use App\Models\ContactGroup;
use App\Http\Requests\StoreContactGroup;
use App\Http\Resources\ContactGroupResource;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Excel;
use Validator;

class ContactGroupController extends BaseController
{
    private function getCurrentOrganizationId()
    {
        return session()->get('current_organization');
    }

    public function index(Request $request, $uuid = null)
    {
        if($uuid === 'export') {
            return Excel::download(new ContactGroupsExport, 'contact-groups.xlsx');
        } else {
            $organizationId = $this->getCurrentOrganizationId();
            $contactGroupModel = new ContactGroup;

            $searchTerm = $request->query('search');
            $uuid = $request->query('id');

            $rows = $contactGroupModel->getAll($organizationId, $searchTerm);
            $rowCount = $contactGroupModel->countAll($organizationId);
            $group = $contactGroupModel->getRow($uuid, $organizationId);

            return Inertia::render('User/Contact/Group', [
                'title' => __('Groups'),
                'rows' => ContactGroupResource::collection($rows),
                'rowCount' => $rowCount,
                'group' => $group,
                'filters' => request()->all()
            ]);
        }
    }

    public function import(Request $request) 
    {
        $import = new ContactGroupsImport();
        Excel::import($import, $request->file);

       // Get the count of successful imports
       $successfulImports = $import->getsuccessfulImports();
       $totalImports = $import->getTotalImportsCount();
       $failedImports = $totalImports - $successfulImports;

       // Prepare status message based on the import outcome
       if ($successfulImports === 0) {
           $statusType = 'error';
           $statusMessage = __('All rows failed to import. Please check the data format or duplicates.');
       } elseif ($failedImports === 0) {
           $statusType = 'success';
           $statusMessage = __('All rows have been imported successfully!');
       } elseif ($successfulImports > 0 && $failedImports > 0) {
           $statusType = 'warning';
           $statusMessage = __('Some rows have been imported successfully, while others failed. Please check the error logs for details.');
       }

        return redirect('/contact-groups')->with(
            'status', [
                'type' => $statusType, 
                'message' => $statusMessage,
                'import_summary' => array(
                    'total_imports' => $totalImports,
                    'successful_imports' => $successfulImports,
                    'failed_imports' => $failedImports,
                    'duplicate_entries'  => $import->getFailedImportsDueToDuplicatesCount(),
                    'invalid_format_entries' => $import->getFailedImportsDueToFormat(),
                    'failed_rows_details' => $import->getFailedImports(),
                    'failed_limit_entries'  => 0,
                ),
            ]
        );
    }

    public function store(StoreContactGroup $request)
    {
        $contactGroup = new ContactGroup();
        $contactGroup->organization_id = $this->getCurrentOrganizationId();
        $contactGroup->name = $request->name;
        $contactGroup->created_by = auth()->user()->id;
        $contactGroup->created_at = now();
        $contactGroup->updated_at = now();
        $contactGroup->save();

        // Prepare a clean contact object for webhook
        $cleanContactGroup = $contactGroup->makeHidden(['id', 'organization_id', 'created_by']);

        // Trigger webhook
        WebhookHelper::triggerWebhookEvent('group.created', $cleanContactGroup);

        return response()->json(['success' => true, 'message'=> __('Contact group added successfully'), 'data' => $contactGroup]);
    }

    public function update(StoreContactGroup $request, $uuid)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors'=>$validator->messages()->get('*')]);
        }

        $contactGroup = ContactGroup::where('uuid', $uuid)->firstOrFail();
        $contactGroup->name = $request->name;
        $contactGroup->updated_at = now();
        $contactGroup->save();

        // Prepare a clean contact object for webhook
        $cleanContactGroup = $contactGroup->makeHidden(['id', 'organization_id', 'created_by']);

        // Trigger webhook
        WebhookHelper::triggerWebhookEvent('group.created', $cleanContactGroup);

        return response()->json(['success' => true, 'message'=> __('Contact group updated successfully'), 'data' => $contactGroup]);
    }

    public function delete(Request $request)
    {
        $uuids = $request->input('uuids', []);
        $organizationId = session()->get('current_organization');
        $deletedGroups = [];

        if (empty($uuids)) {
            $contactgroups = ContactGroup::where('organization_id', $organizationId)->get();
            // Prepare deleted contacts for the webhook
            foreach ($contactgroups as $group) {
                $group->contacts()->detach();
                $deletedGroups[] = [
                    'uuid' => $group->uuid,
                    'deleted_at' => now()->toISOString(), // Assuming you're using Laravel's Carbon
                ];
            }

            // Delete all groups
            ContactGroup::where('organization_id', $organizationId)->delete();
        } else {
            $contactGroups = ContactGroup::whereIn('uuid', $uuids)->where('organization_id', $organizationId)->get();

            foreach ($contactGroups as $group) {
                $group->contacts()->detach(); // Detach contacts from this group
                $deletedGroups[] = [
                    'uuid' => $group->uuid,
                    'deleted_at' => now()->toISOString(),
                ];
            }

            // Delete only selected groups
            ContactGroup::whereIn('uuid', $uuids)->where('organization_id', $organizationId)->delete();
        }

        // Trigger webhook with deleted contacts
        WebhookHelper::triggerWebhookEvent('group.deleted', [
            'list' => $deletedGroups
        ]);

        return redirect('/contact-groups')->with(
            'status', [
                'type' => 'success',
                'message' => __('Group(s) deleted successfully')
            ]
        );
    }
}