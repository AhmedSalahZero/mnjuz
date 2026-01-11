<?php

namespace App\Imports;

use App\Models\Contact;
use App\Models\ContactField;
use App\Models\ContactGroup;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Rules\ContactLimit;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Propaganistas\LaravelPhone\PhoneNumber;

class ContactsImport extends \PhpOffice\PhpSpreadsheet\Cell\StringValueBinder implements ToModel, WithHeadingRow, WithCustomValueBinder, WithChunkReading
{
    protected $totalImports = 0;
    protected $successfulImports = 0;
    protected $failedImports = [];
    protected $failedImportsDueToFormat = 0;
    protected $failedImportsDueToDuplicates = 0;
    protected $failedImportsDueToLimit = 0;

    /**
    * @param array $row
    *
    * @return \Illuminate\Database\Eloquent\Model|null
    */
    public function model(array $row)
    {
        try {
            $this->totalImports++;

            $phoneNumberValue = $row['phone'];

            if (!str_starts_with($phoneNumberValue, '+')) {
                $phoneNumberValue = '+' . $phoneNumberValue;
            }

            // Sequential Validation: First check the first_name field
            $validator = Validator::make($row, [
                'first_name' => 'required', // Make first_name required
            ]);

            // If first_name fails, stop further validation and return the error
            if ($validator->fails()) {
                $this->failedImports[] = [
                    'row' => $row['phone'],
                    'error' => __('First name required!')
                ];
                $this->failedImportsDueToFormat++;
                return null;
            }

            // If first_name passes, proceed to validate the phone field
            $validator = Validator::make($row, [
                'phone' => 'required', // Make phone required
            ]);

            // If phone is invalid, add the error and return
            if ($validator->fails()) {
                $this->failedImports[] = [
                    'row' => $row['phone'],
                    'error' => __('Phone number required!')
                ];
                $this->failedImportsDueToFormat++;
                return null;
            }

            // Now validate the phone number format
            $phoneNumber = new PhoneNumber($phoneNumberValue);

            if (!$phoneNumber->isValid()) {
                $this->failedImports[] = [
                    'row' => $row['phone'],
                    'error' => __('Invalid format!')
                ];
                $this->failedImportsDueToFormat++;
                return null;
            }

            // Check if the phone number already exists in the database
            if (Contact::where('organization_id', session()->get('current_organization'))
                    ->where('phone', $phoneNumber)
                    ->whereNull('deleted_at')
                    ->exists()) {
                $this->failedImports[] = [
                    'row' => $row['phone'],
                    'error' => __('Duplicate phone number!')
                ];
                $this->failedImportsDueToDuplicates++;
                return null;
            }

            // Fetch dynamic fields from contact_fields table
            $organizationId = session()->get('current_organization');
            $existingContactCount = Contact::where('organization_id', $organizationId)->whereNull('deleted_at')->count();
            $contactLimit = $this->contactSubscriptionLimit($organizationId);

            // Check if the total contacts would exceed the limit
            if ($contactLimit != '-1' && ($existingContactCount + 1) > $contactLimit) {
                $this->failedImports[] = [
                    'row' => $row['phone'],
                    'error' => __('Contact limit reached!')
                ];
                $this->failedImportsDueToLimit++;
                return null;
            }

            // Fetch dynamic fields from contact_fields table
            $contactFields = ContactField::where('organization_id', $organizationId)->pluck('name')->toArray();

            $metadata = [];

            foreach ($contactFields as $field) {
                $normalizedField = strtolower($field); 

                if (isset($row[$normalizedField])) {
                    $metadata[$field] = $row[$normalizedField];
                }
            }

            $contact =  Contact::create([
                'organization_id'  => $organizationId,
                'first_name'  => $row['first_name'],
                'last_name'   => $row['last_name'],
                'phone'       => phone($phoneNumberValue)->formatE164(), 
                'email'       => $row['email'],
                'address'     => json_encode([
                    'street'  => $row['street'] ?? null,
                    'city'    => $row['city'] ?? null,
                    'state'   => $row['state'] ?? null,
                    'zip'     => $row['zip'] ?? null,
                    'country' => $row['country'] ?? null
                ]),
                'metadata'    => !empty($metadata) ? json_encode($metadata) : null, 
                'created_by'  => auth()->user()->id,
            ]);

            if($contact){
                $this->successfulImports++;

                // Process contact groups (if provided)
                if (!empty($row['group_name'])) {
                    $groupNames = explode('|', $row['group_name'] ?? ''); // Split by comma
                    $groupNames = array_map('trim', $groupNames); // Trim whitespace

                    foreach ($groupNames as $groupName) {
                        $group = ContactGroup::firstOrCreate([
                            'organization_id' => $organizationId,
                            'name'            => $groupName,
                            'deleted_at'      => null
                        ], [
                            'created_by' => auth()->user()->id,
                        ]);

                        // Attach contact to the group via pivot table
                        $contact->contactGroups()->attach($group->id);
                    }
                }

                return $contact;
            }
        } catch (\Exception $e) {
            $this->failedImports[] = [
                'row' => $row['phone'],
                'error' => __('Invalid format!')
            ];
            $this->failedImportsDueToFormat++;

            return null;
        }
    }

    public function getFailedImportsDueToFormat()
    {
        return $this->failedImportsDueToFormat;
    }

    public function getFailedImportsDueToDuplicatesCount()
    {
        return $this->failedImportsDueToDuplicates;
    }

    public function getFailedImportsDueToLimit()
    {
        return $this->failedImportsDueToLimit;
    }

    public function getSuccessfulImports()
    {
        return $this->successfulImports;
    }

    public function getFailedImports()
    {
        return $this->failedImports;
    }

    public function getTotalImportsCount()
    {
        return $this->totalImports;
    }

    public function chunkSize(): int
    {
        return 1000; // Adjust the chunk size as needed
    }

    private function contactSubscriptionLimit($organizationId)
    {
        $subscription = Subscription::where('organization_id', $organizationId)->first();
        $subscriptionPlan = SubscriptionPlan::find($subscription->plan_id);
        $count = Contact::where('organization_id', $organizationId)->whereNull('deleted_at')->count();

        if($subscription->status === 'trial' && $subscription->valid_until > now()){
            $limit = optional(Setting::where('key', 'trial_limits')->first())->value;
            $usageLimit = $limit ? json_decode($limit, true)['contacts'] ?? '-1' : '-1';
        }

        if ($subscriptionPlan) {
            $subscriptionPlanLimits = json_decode($subscriptionPlan->metadata, true);

            if (!array_key_exists('contacts_limit', $subscriptionPlanLimits)) {
                $usageLimit = 0;
            } else {
                $usageLimit = $subscriptionPlanLimits['contacts_limit'];
            }
        }

        return $usageLimit;
    }
}


