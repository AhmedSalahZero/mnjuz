<?php

namespace App\Services;

use App\Helpers\DateTimeHelper;
use App\Helpers\WebhookHelper;
use App\Models\Chat;
use App\Models\Contact;
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

    public function store(object $request, $uuid = null){
        $contact = $uuid === null ? new Contact() : Contact::where('uuid', $uuid)->firstOrFail();
        $contact->first_name = $request->first_name;
        $contact->last_name = $request->last_name;
        $contact->email = $request->email;

        $contact->phone = PhoneService::getE164Format($request->phone);

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
        
        $contact->address = $address;
        $contact->metadata = json_encode($request->metadata);
        $contact->updated_at =now();
		if($request->has('is_blocked')){
			$contact->is_blocked = $request->boolean('is_blocked');
		}
        $contact->save();

        if($request->group){
            $groupUuids = array_map('trim', $request->group);
            $groupIds = ContactGroup::whereIn('uuid', $groupUuids)->pluck('id')->toArray();
            $contact->contactGroups()->sync($groupIds);
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
