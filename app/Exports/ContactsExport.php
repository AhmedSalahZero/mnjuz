<?php

namespace App\Exports;

use App\Models\Contact;
use App\Models\ContactField;
use App\Models\Organization;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class ContactsExport implements FromCollection, WithHeadings
{
    /**
    * @return \Illuminate\Support\Collection
    */
    public function collection()
    {
        $contacts = Contact::with('contactGroups')
            ->where('organization_id', session()->get('current_organization'))
            ->whereNull('deleted_at')
            ->get();

        // Get dynamic fields from the contact_fields table
        $dynamicFields = ContactField::where('organization_id', session()->get('current_organization'))
            ->whereNull('deleted_at')
            ->get();
	$organization = Organization::find(session()->get('current_organization'));
        // Extract field names from the dynamic fields
        $fieldNames = $dynamicFields->pluck('name')->toArray();
$shouldBeEncrypted = Contact::contactPhoneNumberShouldEncrypted($organization);
        // Modify the collection to include formatted phone numbers and group names
        return $contacts->map(function ($contact) use ($fieldNames,$shouldBeEncrypted)  {
            $address = json_decode($contact->address, true);
			/**
			 * @var Contact $contact
			 */
			$contact->encryptPhoneNumber($shouldBeEncrypted);
            $row = [
                'first_name' => $contact->first_name,
                'last_name' => $contact->last_name,
                'phone' => $contact->formatted_phone_number, // Assuming formatted_phone_number is defined
                'email' => $contact->email,
                'group_name' => $contact->contactGroups->pluck('name')->implode('|'),
                'street' => $address['street'] ?? null,
                'city' => $address['city'] ?? null,
                'state' => $address['state'] ?? null,
                'zip' => $address['zip'] ?? null,
                'country' => $address['country'] ?? null,
            ];

            foreach ($fieldNames as $fieldName) {
                $metadata = json_decode($contact->metadata, true);

                if (isset($metadata[$fieldName])) {
                    $row[$fieldName] = $metadata[$fieldName];
                } else {
                    $row[$fieldName] = null;
                }
            }

            return $row;
        });
    }

    public function headings(): array
    {
        $dynamicFields = ContactField::where('organization_id', session()->get('current_organization'))
            ->whereNull('deleted_at')
            ->get();

        $fieldNames = $dynamicFields->pluck('name')->toArray();

        // Define your headers here
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

        return $headers;
    }
}
