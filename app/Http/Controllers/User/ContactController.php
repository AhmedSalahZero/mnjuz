<?php

namespace App\Http\Controllers\User;

use App\Exports\ContactsExport;
use App\Http\Controllers\Controller as BaseController;
use App\Http\Requests\StoreContact;
use App\Http\Resources\ContactResource;
use App\Jobs\ProcessContactsImportJob;
use App\Models\Contact;
use App\Models\ContactCategory;
use App\Models\ContactField;
use App\Models\ContactGroup;
use App\Models\Organization;
use App\Services\SubscriptionService;
use App\Models\User;
use App\Services\ContactFieldService;
use App\Services\ContactImportService;
use App\Services\ContactService;
use DB;
use Excel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Validator;
use App\Services\ActivityLogger;

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

            // التصدير كان يتجاهل التحديد ويُخرج كل شيء دائماً، فمن حدّد بضع
            // جهات ثم صدّر وجد الملف يحوي القائمة كاملة.
            return $this->exportContacts($format, $this->resolveExportUuids($request));
        } else if($uuid === 'template') {
            return $this->downloadTemplate();
        } else {
            $searchTerm = $request->query('search');
            $uuid = $request->query('id') ? $request->query('id') : $uuid ;
            $editContact = $request->query('edit') === 'true' ? true : false;

            $contacts = $contactModel->getAllContacts($organizationId, $searchTerm);
            $rowCount = $contactModel->countContacts($organizationId);
            $contactGroups = $contactModel->getAllContactGroups($organizationId);
            $contactCategoriesEnabled = SubscriptionService::isSubscriptionFeatureEnabled((string) $organizationId, 'contact_categories_enabled');
            $contactCategories = $contactCategoriesEnabled
                ? ContactCategory::where('organization_id', $organizationId)->orderBy('name')->get(['id', 'uuid', 'name'])
                : [];
            $with = ['contactGroups'];
            if ($contactCategoriesEnabled) {
                $with[] = 'contactCategories';
            }
            $contact = Contact::with($with)->where('uuid', $uuid)->where('deleted_at', null)->first();
            $contactFields = ContactField::where('organization_id', $organizationId)->where('deleted_at', null)->get();
			/**
			 * @var Contact $contact
			 */
			// $isAgent = User::currentUserIsAgent();
			// $encryptContactsForAgents = $this->getTicketSettings();
			// $phoneMustBeEncrypted = $isAgent && $encryptContactsForAgents ;
			$contact  ? $contact->encryptPhoneNumber(Contact::contactPhoneNumberShouldEncrypted())   :'';
			
            return Inertia::render('User/Contact/Index', [
                'title' => __('Contacts'),
                'rows' => ContactResource::collection($contacts),
                'rowCount' => $rowCount,
                'contact' => $contact,
                'fields' => $contactFields,
                'contactGroups' => $contactGroups,
                'contactCategories' => $contactCategories,
                'contactCategoriesEnabled' => $contactCategoriesEnabled,
                'filters' => request()->all(),
                'locationSettings' => $this->getLocationSettings(),
                'editContact' => $editContact,
            ]);
        }
    }

    /**
     * معرّفات ما يُصدَّر، أو null حين يريد المستخدم القائمة كاملة.
     *
     * @return array<int, string>|null
     */
    private function resolveExportUuids(Request $request): ?array
    {
        // «حدّد الكل» بلا استثناءات = القائمة كاملة، فلا داعي لتعداد المعرّفات.
        if ($request->boolean('select_all')) {
            $excluded = array_filter((array) $request->query('excluded', []));
            $search = $request->query('search');

            if ($excluded === [] && ($search === null || $search === '')) {
                return null;
            }

            return $this->uuidsMatchingFilter($search, $excluded);
        }

        $uuids = array_filter((array) $request->query('uuids', []));

        return $uuids === [] ? null : array_values($uuids);
    }

    /**
     * @param  array<int, string>|null  $uuids  null = كل جهات الاتصال.
     */
    public function exportContacts($format = 'xlsx', ?array $uuids = null)
    {
        if ($format === 'csv') {
            return $this->exportContactsAsCsv($uuids);
        } else {
            return Excel::download(new ContactsExport($uuids), 'contacts.xlsx');
        }
    }

    /**
     * @param  array<int, string>|null  $uuids  null = كل جهات الاتصال.
     */
    public function exportContactsAsCsv(?array $uuids = null)
    {
        $organizationId = $this->getCurrentOrganizationId();
        $contacts = Contact::with('contactGroups', 'contactCategories')
            ->where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->when($uuids !== null, fn ($query) => $query->whereIn('uuid', $uuids))
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
            // كان عمود التصنيف مفقوداً من CSV وموجوداً في Excel، فمن يُصدّر
            // CSV ثم يُعيد استيراده كان يفقد تصنيفات جهات اتصاله كلّها.
            'Category',
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

        // UTF-8 BOM so Excel preserves Arabic characters when editing CSV
        fwrite($handle, "\xEF\xBB\xBF");
        
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
                $contact->contactCategories->pluck('name')->implode('، '),
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
            'category',
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
                // مثال بتصنيفين: العمود اختياري، ويُترك فارغاً عند عدم الرغبة.
                'category' => 'عميل محتمل، عميل خاص',
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

        // UTF-8 BOM so Excel preserves Arabic characters when editing CSV
        fwrite($handle, "\xEF\xBB\xBF");
        
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
        $request->validate([
            'file' => 'required|file|mimes:csv,txt,xlsx',
        ]);

        $organizationId = (int) $this->getCurrentOrganizationId();
        $userId = (int) auth()->id();

        $existing = ContactImportService::getStatus($organizationId, $userId);
        if ($existing && in_array($existing['state'] ?? '', ['queued', 'processing'], true)) {
            return response()->json([
                'state' => $existing['state'],
                'message' => __('A contact import is already in progress. Please wait for it to finish.'),
            ], 409);
        }

        $path = $request->file('file')->store('contact-imports');

        ContactImportService::putStatus($organizationId, $userId, [
            'state' => 'queued',
            'queued_at' => now()->toIso8601String(),
            'file_name' => $request->file('file')->getClientOriginalName(),
        ]);

        ProcessContactsImportJob::dispatch($organizationId, $userId, $path)->afterResponse();

        // بعد القبول والجدولة لا قبلهما: الطلب المرفوض بـ 409 ليس استيراداً.
        ActivityLogger::log(
            ActivityLogger::CONTACT_IMPORTED,
            $request->file('file')->getClientOriginalName(),
            'contact_import',
            null,
            [],
            $organizationId
        );

        return response()->json([
            'state' => 'queued',
            'message' => __('Your contacts are being imported in the background. You can close this window and continue working.'),
        ]);
    }

    public function importStatus(Request $request) {
        $organizationId = (int) $this->getCurrentOrganizationId();
        $userId = (int) auth()->id();

        $status = ContactImportService::getStatus($organizationId, $userId);

        if (!$status) {
            return response()->json(['state' => 'idle']);
        }

        return response()->json($status);
    }

    public function store(StoreContact $request){
        $contact = $this->contactService()->store($request);

        ActivityLogger::log(
            ActivityLogger::CONTACT_CREATED,
            trim(($contact->first_name ?? '') . ' ' . ($contact->last_name ?? '')) ?: $contact->phone,
            'contact',
            $contact->id
        );
        
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

        ActivityLogger::log(
            ActivityLogger::CONTACT_UPDATED,
            trim(($contact->first_name ?? '') . ' ' . ($contact->last_name ?? '')) ?: $contact->phone,
            'contact',
            $contact->id
        );

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
        $request->validate([
            'uuids' => 'array',
            'uuids.*' => 'string',
            'select_all' => 'sometimes|boolean',
            'search' => 'nullable|string|max:255',
            'excluded' => 'array',
            'excluded.*' => 'string',
        ]);

        // «حدّد الكل» لا يمرّر عشرات الآلاف من المعرّفات: أكبر منشأة لديها
        // 63,361 جهة اتصال، وحمولة بهذا الحجم تتجاوز حدّ الطلب وسعة التخزين
        // المحلّي في المتصفّح. تُمرَّر النيّة مع المرشّح، ويُحلّها الخادم.
        if ($request->boolean('select_all')) {
            $uuids = $this->uuidsMatchingFilter(
                $request->input('search'),
                (array) $request->input('excluded', [])
            );
        } else {
            $uuids = (array) $request->input('uuids', []);

            // مصفوفة فارغة كانت تعني «احذف كل شيء» في الخدمة، فأي طلب فقد
            // معرّفاته في الطريق كان يمسح جهات اتصال المنشأة كلّها. النيّة
            // صارت تُطلب صراحةً، والفراغ صار لا يفعل شيئاً.
            if (empty($uuids)) {
                return redirect('/contacts')->with('status', [
                    'type' => 'info',
                    'message' => __('No contacts selected.'),
                ]);
            }
        }

        // نقرأ الأسماء قبل الحذف: بعده لا يبقى ما يُسمّى في السجلّ.
        $deleted = \App\Models\Contact::where('organization_id', $this->getCurrentOrganizationId())
            ->whereIn('uuid', $uuids)
            ->get(['id', 'first_name', 'last_name', 'phone']);

        $this->contactService()->delete($uuids);

        foreach ($deleted as $contact) {
            ActivityLogger::log(
                ActivityLogger::CONTACT_DELETED,
                trim(($contact->first_name ?? '') . ' ' . ($contact->last_name ?? '')) ?: $contact->phone,
                'contact',
                $contact->id
            );
        }

        return redirect('/contacts')->with(
            'status', [
                'type' => 'success', 
                'message' => __('Contact(s) deleted successfully')
            ]
        );
    }

    /**
     * معرّفات جهات الاتصال المطابقة للبحث الحالي داخل المنشأة، ناقص ما استثناه
     * المستخدم بعد «حدّد الكل».
     *
     * نفس مرشّح القائمة حرفياً (searchTerm) كي يطابق ما يراه المستخدم على
     * الشاشة ما يقع عليه الإجراء — ولا يُحذف أو يُصدَّر ما لا يراه.
     *
     * @param  array<int, string>  $excluded
     * @return array<int, string>
     */
    private function uuidsMatchingFilter(?string $search, array $excluded = []): array
    {
        return \App\Models\Contact::where('organization_id', $this->getCurrentOrganizationId())
            ->whereNull('deleted_at')
            ->searchTerm($search)
            ->when($excluded !== [], fn ($query) => $query->whereNotIn('uuid', $excluded))
            ->pluck('uuid')
            ->all();
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
