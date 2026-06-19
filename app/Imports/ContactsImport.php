<?php

namespace App\Imports;

use App\Models\Contact;
use App\Models\ContactField;
use App\Models\ContactGroup;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Services\PhoneService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class ContactsImport extends \PhpOffice\PhpSpreadsheet\Cell\StringValueBinder implements ToModel, WithHeadingRow, WithCustomValueBinder, WithChunkReading
{
    private const MAX_FAILED_DETAILS = 500;

    protected int $organizationId;

    protected int $userId;

    /** @var array<int, string> */
    protected array $contactFields = [];

    protected int $contactLimit = 0;

    protected int $currentContactCount = 0;

    protected $totalImports = 0;

    protected $successfulImports = 0;

    protected $updatedImports = 0;

    protected $failedImports = [];

    protected $failedImportsDueToFormat = 0;

    protected $failedImportsDueToDuplicates = 0;

    protected $failedImportsDueToLimit = 0;

    protected bool $failedImportsTruncated = false;

    public function __construct(?int $organizationId = null, ?int $userId = null)
    {
        $this->organizationId = (int) ($organizationId ?? session()->get('current_organization'));
        $this->userId = (int) ($userId ?? auth()->id() ?? 0);
        $this->contactFields = ContactField::where('organization_id', $this->organizationId)
            ->pluck('name')
            ->toArray();
        $this->contactLimit = $this->contactSubscriptionLimit($this->organizationId);

        if ($this->contactLimit > 0) {
            $this->currentContactCount = Contact::where('organization_id', $this->organizationId)
                ->whereNull('deleted_at')
                ->count();
        }
    }

    /**
     * @param array $row
     *
     * @return \Illuminate\Database\Eloquent\Model|null
     */
    public function model(array $row)
    {
        try {
            $this->totalImports++;

            if ($this->organizationId <= 0) {
                $this->recordFailedImport(
                    '-',
                    __('No organization selected. Please select an organization and try again.'),
                    'format'
                );

                return null;
            }

            $row = $this->normalizeRow($row);

            $firstName = trim((string) ($row['first_name'] ?? ''));
            $phoneNumberValue = $this->normalizePhoneFromRow($row['phone'] ?? null);

            if ($firstName === '') {
                $this->recordFailedImport(
                    $this->rowIdentifier($row, $phoneNumberValue),
                    __('First name required!'),
                    'format'
                );

                return null;
            }

            if ($phoneNumberValue === '') {
                $this->recordFailedImport(
                    $this->rowIdentifier($row, $phoneNumberValue),
                    __('Phone number required!'),
                    'format'
                );

                return null;
            }

            if (!PhoneService::isValid($phoneNumberValue)) {
                $this->recordFailedImport(
                    $this->rowIdentifier($row, $phoneNumberValue),
                    __('Invalid phone number format!'),
                    'format'
                );

                return null;
            }

            $formattedPhone = PhoneService::getE164Format($phoneNumberValue);

            if ($formattedPhone === null) {
                $this->recordFailedImport(
                    $this->rowIdentifier($row, $phoneNumberValue),
                    __('Invalid phone number format!'),
                    'format'
                );

                return null;
            }

            $existingContact = $this->findExistingContact($this->organizationId, $formattedPhone);

            $metadata = $this->buildMetadataFromRow($row, $this->contactFields);
            $address = json_encode([
                'street'  => $this->nullableString($row['street'] ?? null),
                'city'    => $this->nullableString($row['city'] ?? null),
                'state'   => $this->nullableString($row['state'] ?? null),
                'zip'     => $this->nullableString($row['zip'] ?? null),
                'country' => $this->nullableString($row['country'] ?? null),
            ]);

            if ($existingContact) {
                if ($existingContact->trashed()) {
                    $existingContact->restore();
                }

                $existingMetadata = is_string($existingContact->metadata)
                    ? (json_decode($existingContact->metadata, true) ?? [])
                    : [];

                $existingContact->update([
                    'first_name' => $firstName,
                    'last_name'  => $this->nullableString($row['last_name'] ?? null),
                    'phone'      => $formattedPhone,
                    'email'      => $this->nullableString($row['email'] ?? null),
                    'address'    => $address,
                    'metadata'   => !empty(array_merge($existingMetadata, $metadata))
                        ? json_encode(array_merge($existingMetadata, $metadata))
                        : null,
                    'updated_at' => now(),
                ]);

                if ($this->rowHasGroupName($row)) {
                    $this->syncContactGroups($existingContact, $row['group_name'], $this->organizationId);
                }

                $this->successfulImports++;
                $this->updatedImports++;

                return $existingContact;
            }

            $contactLimit = $this->contactLimit;

            if ($contactLimit > 0) {
                if (($this->currentContactCount + 1) > $contactLimit) {
                    $this->recordFailedImport(
                        $this->rowIdentifier($row, $phoneNumberValue),
                        __('Contact limit reached!'),
                        'limit'
                    );

                    return null;
                }
            }

            $contact = Contact::create([
                'organization_id' => $this->organizationId,
                'first_name'      => $firstName,
                'last_name'       => $this->nullableString($row['last_name'] ?? null),
                'phone'           => $formattedPhone,
                'email'           => $this->nullableString($row['email'] ?? null),
                'address'         => $address,
                'metadata'        => !empty($metadata) ? json_encode($metadata) : null,
                'created_by'      => $this->userId,
            ]);

            if ($contact) {
                $this->successfulImports++;
                $this->currentContactCount++;

                if ($this->rowHasGroupName($row)) {
                    $this->syncContactGroups($contact, $row['group_name'], $this->organizationId);
                }

                return $contact;
            }
        } catch (\Throwable $e) {
            Log::warning('ContactsImport row failed', [
                'organization_id' => $this->organizationId,
                'error' => $e->getMessage(),
            ]);

            $this->recordFailedImport(
                $this->rowIdentifier($row ?? [], $phoneNumberValue ?? ''),
                __('Import failed for this row. Please verify the data format.'),
                'format'
            );

            return null;
        }

        return null;
    }

    private function recordFailedImport(string $row, string $error, string $reason): void
    {
        if (count($this->failedImports) < self::MAX_FAILED_DETAILS) {
            $this->failedImports[] = [
                'row' => $row,
                'error' => $error,
            ];
        } else {
            $this->failedImportsTruncated = true;
        }

        match ($reason) {
            'limit' => $this->failedImportsDueToLimit++,
            'duplicate' => $this->failedImportsDueToDuplicates++,
            default => $this->failedImportsDueToFormat++,
        };
    }

    /**
     * Map Excel heading variants (slug / spaces / dashes) to canonical column names.
     */
    private function normalizeRow(array $row): array
    {
        $canonicalKeys = [
            'first_name' => ['first_name', 'firstname', 'first'],
            'last_name'  => ['last_name', 'lastname', 'last'],
            'phone'      => ['phone', 'mobile', 'phone_number', 'phonenumber', 'tel', 'telephone'],
            'email'      => ['email', 'e_mail', 'mail'],
            'group_name' => ['group_name', 'groupname', 'group', 'groups', 'contact_group'],
            'street'     => ['street', 'address', 'address_street'],
            'city'       => ['city'],
            'state'      => ['state', 'province', 'region'],
            'zip'        => ['zip', 'zip_code', 'postal_code', 'postcode'],
            'country'    => ['country'],
        ];

        $normalized = [];

        foreach ($row as $key => $value) {
            $slug = str_replace(['-', ' '], '_', strtolower(trim((string) $key)));

            foreach ($canonicalKeys as $canonical => $aliases) {
                if (in_array($slug, $aliases, true)) {
                    $normalized[$canonical] = $value;
                    continue 2;
                }
            }

            $normalized[$slug] = $value;
        }

        return $normalized;
    }

    private function normalizePhoneFromRow(mixed $rawPhone): string
    {
        if ($rawPhone === null || $rawPhone === '') {
            return '';
        }

        if (is_float($rawPhone)) {
            $rawPhone = sprintf('%.0f', $rawPhone);
        } elseif (is_int($rawPhone)) {
            $rawPhone = (string) $rawPhone;
        } else {
            $rawPhone = trim((string) $rawPhone);

            if (preg_match('/^[\d.]+E\+?\d+$/i', $rawPhone)) {
                $rawPhone = sprintf('%.0f', (float) $rawPhone);
            }
        }

        $rawPhone = preg_replace('/[^\d+]/', '', $rawPhone) ?? '';

        if ($rawPhone !== '' && !str_starts_with($rawPhone, '+')) {
            $rawPhone = '+' . $rawPhone;
        }

        return $rawPhone;
    }

    private function findExistingContact(int $organizationId, string $formattedPhone): ?Contact
    {
        $digitsOnly = preg_replace('/\D+/', '', ltrim($formattedPhone, '+')) ?? '';

        $variants = array_unique(array_filter([
            $formattedPhone,
            ltrim($formattedPhone, '+'),
            '+' . $digitsOnly,
            $digitsOnly,
        ]));

        $contact = Contact::withTrashed()
            ->where('organization_id', $organizationId)
            ->where(function ($query) use ($variants, $digitsOnly) {
                $query->whereIn('phone', $variants);

                if ($digitsOnly !== '') {
                    $query->orWhereRaw(
                        "REPLACE(REPLACE(REPLACE(REPLACE(phone, '+', ''), ' ', ''), '-', ''), '.', '') = ?",
                        [$digitsOnly]
                    );
                }
            })
            ->first();

        return $contact;
    }

    private function rowHasGroupName(array $row): bool
    {
        return array_key_exists('group_name', $row);
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }

    private function rowIdentifier(array $row, string $phone = ''): string
    {
        if ($phone !== '') {
            return $phone;
        }

        $phone = trim((string) ($row['phone'] ?? ''));
        if ($phone !== '') {
            return $phone;
        }

        $firstName = trim((string) ($row['first_name'] ?? ''));
        if ($firstName !== '') {
            return $firstName;
        }

        $email = trim((string) ($row['email'] ?? ''));
        if ($email !== '') {
            return $email;
        }

        return '-';
    }

    private function buildMetadataFromRow(array $row, array $contactFields): array
    {
        $metadata = [];

        foreach ($contactFields as $field) {
            $normalizedField = strtolower(str_replace([' ', '-'], '_', $field));

            if (isset($row[$normalizedField])) {
                $metadata[$field] = $row[$normalizedField];
            }
        }

        return $metadata;
    }

    /**
     * Sync contact groups to match the import row (add missing, remove extras).
     * Group names are pipe-separated in group_name column.
     */
    private function syncContactGroups(Contact $contact, mixed $groupNameValue, int $organizationId): void
    {
        $groupIds = $this->resolveGroupIdsFromImport($groupNameValue, $organizationId);
        $contact->contactGroups()->sync($groupIds);
    }

    private function resolveGroupIdsFromImport(mixed $groupNameValue, int $organizationId): array
    {
        $groupNames = array_filter(array_map('trim', explode('|', (string) $groupNameValue)));

        $groupIds = [];

        foreach ($groupNames as $groupName) {
            $group = ContactGroup::withTrashed()
                ->where('organization_id', $organizationId)
                ->where('name', $groupName)
                ->first();

            if ($group) {
                if ($group->trashed()) {
                    $group->restore();
                }
            } else {
                $group = ContactGroup::create([
                    'organization_id' => $organizationId,
                    'name'            => $groupName,
                    'created_by'      => $this->userId,
                ]);
            }

            $groupIds[] = $group->id;
        }

        return array_values(array_unique($groupIds));
    }

    public function getUpdatedImports()
    {
        return $this->updatedImports;
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

    public function getFailedImportsTruncated(): bool
    {
        return $this->failedImportsTruncated;
    }

    public function getTotalImportsCount()
    {
        return $this->totalImports;
    }

    public function chunkSize(): int
    {
        return 500;
    }

    private function contactSubscriptionLimit($organizationId)
    {
        $subscription = Subscription::where('organization_id', $organizationId)->first();

        if (!$subscription) {
            return 0;
        }

        $usageLimit = 0;

        if ($subscription->status === 'trial' && $subscription->valid_until > now()) {
            $limit = optional(Setting::where('key', 'trial_limits')->first())->value;
            $usageLimit = $limit ? json_decode($limit, true)['contacts'] ?? 0 : 0;
        } else {
            $subscriptionPlan = SubscriptionPlan::find($subscription->plan_id);

            if ($subscriptionPlan) {
                $subscriptionPlanLimits = json_decode($subscriptionPlan->metadata, true);
                $usageLimit = $subscriptionPlanLimits['contacts_limit'] ?? 0;
            }
        }

        return $usageLimit;
    }
}
