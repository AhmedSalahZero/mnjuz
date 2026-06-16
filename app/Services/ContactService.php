<?php

namespace App\Services;

use App\Helpers\DateTimeHelper;
use App\Helpers\WebhookHelper;
use App\Models\Chat;
use App\Models\Contact;
use App\Models\ContactCategory;
use App\Models\ContactContactGroup;
use App\Models\ContactGroup;
use App\Models\Setting;
use App\Services\PhoneService;
use Illuminate\Support\Facades\Storage;

class ContactService
{
    private $organizationId;

    public function __construct($organizationId)
    {
        $this->organizationId = $organizationId;
    }

    /**
     * Find an active contact in the organization by numeric id or uuid.
     */
    public function findInOrganizationByIdOrUuid(int|string $idOrUuid): ?Contact
    {
        $query = Contact::where('organization_id', $this->organizationId)
            ->whereNull('deleted_at');

        if (is_numeric($idOrUuid)) {
            return $query->where('id', (int) $idOrUuid)->first();
        }

        return $query->where('uuid', (string) $idOrUuid)->first();
    }

    /**
     * Find contact by phone within organization (E.164 + digit-normalized fallback).
     */
    public function findByPhoneInOrganization(string $phone): ?Contact
    {
        $e164 = PhoneService::getE164Format(PhoneService::normalize($phone));
        if (!$e164) {
            return null;
        }

        $contact = Contact::where('organization_id', $this->organizationId)
            ->whereNull('deleted_at')
            ->where('phone', $e164)
            ->first();

        if ($contact) {
            return $contact;
        }

        $digitsOnly = preg_replace('/\D+/', '', ltrim($e164, '+')) ?? '';
        if ($digitsOnly === '') {
            return null;
        }

        return Contact::where('organization_id', $this->organizationId)
            ->whereNull('deleted_at')
            ->whereRaw(
                "REPLACE(REPLACE(REPLACE(REPLACE(phone, '+', ''), ' ', ''), '-', ''), '.', '') = ?",
                [$digitsOnly]
            )
            ->first();
    }

    /**
     * يبحث عن contact بالرقم داخل الشركة أو ينشئه.
     * يستخدم نفس تنسيق E.164 المستخدم في webhook الوارد (ProcessIncomingMessageJob)
     * لضمان عدم إنشاء contact مكرر لنفس الرقم.
     */
    public function findOrCreateByPhone(string $phone, array $attributes = []): Contact
    {
        $e164 = PhoneService::getE164Format(PhoneService::normalize($phone));
        if (!$e164) {
            throw new \InvalidArgumentException('Invalid phone number');
        }

        $existing = $this->findByPhoneInOrganization($phone);
        if ($existing) {
            if ($existing->phone !== $e164) {
                $existing->update(['phone' => $e164, 'updated_at' => now()]);
            }

            return $existing;
        }

        $defaults = [
            'created_by' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        try {
            return Contact::create(array_merge($defaults, $attributes, [
                'organization_id' => $this->organizationId,
                'phone' => $e164,
            ]));
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->errorInfo[1] === 1062) {
                return $this->findByPhoneInOrganization($phone)
                    ?? Contact::where('organization_id', $this->organizationId)
                        ->where('phone', $e164)
                        ->firstOrFail();
            }
            throw $e;
        }
    }

    public function store(object $request, $uuid = null){
        $contact = $uuid === null ? new Contact() : Contact::where('uuid', $uuid)->firstOrFail();
		if($request->has('first_name')){
			$contact->first_name = $request->first_name;
			
		}
		if($request->has('last_name')){
			$contact->last_name = $request->last_name;
		}	
		if($request->has('email')){
			$contact->email = $request->email;
		}
		if($request->has('phone')){
			$contact->phone = PhoneService::getE164Format($request->phone);
		}

        if($request->hasFile('file')){
            $storage = Setting::where('key', 'storage_system')->first()->value;
            $fileName = $request->file('file')->getClientOriginalName();
            $fileContent = $request->file('file');

            if($storage === 'local'){
                $file = Storage::disk('local')->put('public', $fileContent);
                $mediaFilePath = $file;

                $contact->avatar = '/media/' . ltrim($mediaFilePath, '/');
            } else if($storage === 'aws') {
                $file = $request->file('file');
                $uploadedFile = $file->store('uploads/media/contacts/' . $this->organizationId, 's3');
                
                if (empty($uploadedFile)) {
                    throw new \Exception('Failed to upload file to S3 storage');
                }
                
                $mediaFilePath = Storage::disk('s3')->url($uploadedFile);

                $contact->avatar = $mediaFilePath;
            }
        }

        if($uuid === null){
            $contact->organization_id = $this->organizationId;
            $contact->created_by = auth()->user() ? auth()->user()->id : 0;
            $contact->created_at =now();
        }
		
        $address = json_encode([
            'street' => $request->street,
            'city' => $request->city,
            'state' => $request->state,
            'zip' => $request->zip,
            'country' => $request->country,
        ]);
        if($request->street || $request->city || $request->state || $request->zip || $request->country){
			$contact->address = $address;
		}
		if($request->metadata){
			$contact->metadata = json_encode($request->metadata);
		}
		if($request->has('is_blocked')){
			$contact->is_blocked = $request->boolean('is_blocked');
		}
		$contact->updated_at =now();
		$contact->save();
		
        if($request->has('group')){
            $groupUuids = array_map('trim', (array) $request->group);
			$columnName = $request->is('api/v1/*') ? 'id' : 'uuid';
            $groupIds = ContactGroup::whereIn($columnName, $groupUuids)->pluck('id')->toArray();
            $contact->contactGroups()->sync($groupIds);
        }
        if ($request->has('categories')) {
            $categoryUuids = array_map('trim', (array) $request->categories);
            $columnName = $request->is('api/v1/*') ? 'id' : 'uuid';
            $categoryIds = ContactCategory::where('organization_id', $this->organizationId)
                ->whereIn($columnName, $categoryUuids)
                ->pluck('id')
                ->toArray();
            $contact->contactCategories()->sync($categoryIds);
        }

        // Prepare a clean contact object for webhook
        $cleanContact = $contact->makeHidden(['id', 'organization_id', 'created_by']);

        // Trigger webhook
        WebhookHelper::triggerWebhookEvent($uuid === null ? 'contact.created' : 'contact.updated', $cleanContact, $this->organizationId);

        return $contact;
    }

    public function favorite(object $request, $uuid){
        $contact = Contact::where('uuid', $uuid)->firstOrFail();
        $contact->is_favorite = $request->favorite;
        $contact->updated_at = date('Y-m-d H:i:s');
        $contact->save();
    }

    public function delete($uuids){
        $deletedContacts = [];

        if (empty($uuids)) {
            // Delete all contacts (soft delete)
            $contacts = Contact::where('organization_id', $this->organizationId)->get();
            Contact::whereNotNull('id')->where('organization_id', $this->organizationId)->delete();

            // Prepare deleted contacts for the webhook
            foreach ($contacts as $contact) {
                $deletedContacts[] = [
                    'uuid' => $contact->uuid,
                    'deleted_at' => now()->toISOString(), // Assuming you're using Laravel's Carbon
                ];
            }

            //Mark all unread chats as read
            Chat::where('organization_id', $this->organizationId)
                ->where('type', 'inbound')
                ->whereNull('deleted_at')
                ->where('is_read', 0)
                ->update([
                    'is_read' => 1
                ]);
        } else {
            // Delete contacts by UUIDs (soft delete)
            foreach($uuids as $uuid){
                $contact = Contact::where('uuid', $uuid)->firstOrFail();

                // Prepare deleted contact for the webhook
                $deletedContacts[] = [
                    'uuid' => $contact->uuid,
                    'deleted_at' => now()->toISOString(),
                ];

                //Mark all unread chats as read
                Chat::where('contact_id', $contact->id)
                    ->where('type', 'inbound')
                    ->whereNull('deleted_at')
                    ->where('is_read', 0)
                    ->update([
                        'is_read' => 1
                    ]);
            }

            Contact::whereIn('uuid', $uuids)->where('organization_id', $this->organizationId)->delete();
        }

        // Trigger webhook with deleted contacts
        WebhookHelper::triggerWebhookEvent('contact.deleted', [
            'list' => $deletedContacts
        ], $this->organizationId);
    }
}
