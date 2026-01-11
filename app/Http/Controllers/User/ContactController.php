<?php

namespace App\Http\Controllers\User;

use App\Exports\ContactsExport;
use App\Http\Controllers\Controller as BaseController;
use App\Http\Requests\StoreContact;
use App\Http\Resources\ContactResource;
use App\Imports\ContactsImport;
use App\Models\Contact;
use App\Models\ContactField;
use App\Models\ContactGroup;
use App\Models\Organization;
use App\Models\User;
use App\Services\ContactFieldService;
use App\Services\ContactService;
use DB;
use Excel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Validator;

class ContactController extends BaseController
{
    private function contactService()
    {
        return new ContactService(session()->get('current_organization'));
    }

    private function getCurrentOrganizationId()
    {
        return session()->get('current_organization');
    }

    public function index(Request $request, $uuid = null){
        $organizationId = $this->getCurrentOrganizationId();
        $contactModel = new Contact;

        if($uuid === 'export') {
            $format = $request->query('format', 'xlsx');
            return $this->exportContacts($format);
        } else if($uuid === 'template') {
            return $this->downloadTemplate();
        } else {
            $searchTerm = $request->query('search');
            $uuid = $request->query('id') ? $request->query('id') : $uuid ;
            $editContact = $request->query('edit') === 'true' ? true : false;

            $contacts = $contactModel->getAllContacts($organizationId, $searchTerm);
            $rowCount = $contactModel->countContacts($organizationId);
            $contactGroups = $contactModel->getAllContactGroups($organizationId);
            $contact = Contact::with('contactGroups')->where('uuid', $uuid)->where('deleted_at', null)->first();
            $contactFields = ContactField::where('organization_id', $organizationId)->where('deleted_at', null)->get();
			/**
			 * @var Contact $contact
			 */
			// $isAgent = User::currentUserIsAgent();
			// $encryptContactsForAgents = $this->getTicketSettings();
			// $phoneMustBeEncrypted = $isAgent && $encryptContactsForAgents ;
			$contact  ? $contact->encryptPhoneNumber(Contact::contactPhoneNumberShouldEncrypted())   :'';
			
            //dd($contact);
            return Inertia::render('User/Contact/Index', [
                'title' => __('Contacts'),
                'rows' => ContactResource::collection($contacts),
                'rowCount' => $rowCount,
                'contact' => $contact,
                'fields' => $contactFields,
                'contactGroups' => $contactGroups,
                'filters' => request()->all(),
                'locationSettings' => $this->getLocationSettings(),
                'editContact' => $editContact,
			
				
				
            ]);
        }
    }

    public function exportContacts($format = 'xlsx')
    {
        if ($format === 'csv') {
            return $this->exportContactsAsCsv();
        } else {
            return Excel::download(new ContactsExport, 'contacts.xlsx');
        }
    }

    public function exportContactsAsCsv()
    {
        $organizationId = $this->getCurrentOrganizationId();
        $contacts = Contact::with('contactGroups')
            ->where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->get();
	$organization = Organization::find($organizationId);
        // Get dynamic fields from the contact_fields table
        $dynamicFields = ContactField::where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->get();

        // Extract field names from the dynamic fields
        $fieldNames = $dynamicFields->pluck('name')->toArray();

        // Define headers
        $headers = [
            'First name',
            'Last name',
            'Phone',
            'Email',
            'Group name',
            'Street',
            'City',
            'State',
            'Zip',
            'Country'
        ];

        // Add dynamic field names to headers
        foreach ($fieldNames as $fieldName) {
            $headers[] = $fieldName;
        }

        // Create a temporary file
        $tempFile = tempnam(sys_get_temp_dir(), 'contacts_export_');
        
        // Open file for writing
        $handle = fopen($tempFile, 'w');
        
        // Write headers
        fputcsv($handle, $headers);
        $shouldBeEncrypted = Contact::contactPhoneNumberShouldEncrypted($organization);
        // Write data
        foreach ($contacts as $contact) {
			/**
			 * @var Contact $contact
			 */
			$contact->encryptPhoneNumber($shouldBeEncrypted);
            $address = json_decode($contact->address, true);
            $row = [
                $contact->first_name,
                $contact->last_name,
                $contact->formatted_phone_number,
                $contact->email,
                $contact->contactGroups->pluck('name')->implode('|'),
                $address['street'] ?? null,
                $address['city'] ?? null,
                $address['state'] ?? null,
                $address['zip'] ?? null,
                $address['country'] ?? null,
            ];

            // Add custom field values
            foreach ($fieldNames as $fieldName) {
                $metadata = json_decode($contact->metadata, true);
                $row[] = $metadata[$fieldName] ?? null;
            }

            fputcsv($handle, $row);
        }
        
        fclose($handle);

        // Return the file as download
        return response()->download($tempFile, 'contacts.csv')->deleteFileAfterSend(true);
    }

    public function downloadTemplate()
    {
        $organizationId = $this->getCurrentOrganizationId();
        
        // Get custom contact fields for this organization
        $customFields = ContactField::where('organization_id', $organizationId)
            ->where('deleted_at', null)
            ->pluck('name')
            ->toArray();

        // Define the standard columns in the order specified
        $standardColumns = [
            'first_name',
            'last_name', 
            'phone',
            'email',
            'group_name',
            'street',
            'city',
            'state',
            'zip',
            'country'
        ];

        // Add custom fields after the standard columns
        $allColumns = array_merge($standardColumns, $customFields);

        // Create sample data
        $sampleData = [
            [
                'first_name' => 'John',
                'last_name' => 'Doe',
                'phone' => '+1234567890',
                'email' => 'john.doe@example.com',
                'group_name' => 'Customers',
                'street' => '123 Main St',
                'city' => 'New York',
                'state' => 'NY',
                'zip' => '10001',
                'country' => 'United States'
            ]
        ];

        // Add custom field values to sample data
        foreach ($customFields as $field) {
            $sampleData[0][strtolower(str_replace(' ', '_', $field))] = 'Sample ' . $field;
        }

        // Create a temporary file
        $tempFile = tempnam(sys_get_temp_dir(), 'contacts_template_');
        
        // Open file for writing
        $handle = fopen($tempFile, 'w');
        
        // Write headers
        fputcsv($handle, $allColumns);
        
        // Write sample data
        foreach ($sampleData as $row) {
            $csvRow = [];
            foreach ($allColumns as $column) {
                $csvRow[] = $row[$column] ?? '';
            }
            fputcsv($handle, $csvRow);
        }
        
        fclose($handle);

        // Return the file as download
        return response()->download($tempFile, 'contacts_template.csv')->deleteFileAfterSend(true);
    }

    public function import(Request $request) {
        // Initialize the import process
        $import = new ContactsImport();

        // Handle file import
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

        return redirect('/contacts')->with(
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
                    'failed_limit_entries'  => $import->getFailedImportsDueToLimit(),
                ),
            ]
        );
    }

    public function store(StoreContact $request){
        $contact = $this->contactService()->store($request);
        
        return redirect('/contacts?id=' . $contact->uuid)->with(
            'status', [
                'type' => 'success', 
                'message' => __('Contact added successfully!')
            ]
        );
    }

    public function update(StoreContact $request, $uuid)
    {
        $contact = $this->contactService()->store($request, $uuid);

        return redirect('/contacts/' . $contact->uuid)->with(
            'status', [
                'type' => 'success', 
                'message' => __('Contact updated successfully!')
            ]
        );
    }

    public function favorite(Request $request, $uuid)
    {
        $this->contactService()->favorite($request, $uuid);

        return redirect('/contacts/' . $uuid)->with(
            'status', [
                'type' => 'success', 
                'message' => __('Contact updated successfully!')
            ]
        );
    }

    public function delete(Request $request)
    {
        $uuids = $request->input('uuids', []);
        $this->contactService()->delete($uuids);

        return redirect('/contacts')->with(
            'status', [
                'type' => 'success', 
                'message' => __('Contact(s) deleted successfully')
            ]
        );
    }

    private function getLocationSettings(){
        // Retrieve the settings for the current organization
        $settings = Organization::where('id', session()->get('current_organization'))->first();

        if ($settings) {
            // Decode the JSON metadata column into an associative array
            $metadata = json_decode($settings->metadata, true);

            if (isset($metadata['contacts'])) {
                // If the 'contacts' key exists, retrieve the 'location' value
                $location = $metadata['contacts']['location'];

                // Now, you have the location value available
                return $location;
            } else {
                return null;
            }
        } else {
            return null;
        }
    }
	
	
}
