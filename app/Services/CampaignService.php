<?php

namespace App\Services;

use App\Helpers\DateTimeHelper;
use App\Jobs\SendCampaignJob;
use App\Models\Campaign;
use App\Models\CampaignLog;
use App\Models\ChatMedia;
use App\Models\Contact;
use App\Models\ContactGroup;
use App\Models\Organization;
use App\Models\Setting;
use App\Models\Template;
use App\Services\WhatsappService;
use App\Traits\TemplateTrait;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Validator;

class CampaignService
{
    use TemplateTrait;

    public function store(object $request){
        $organizationId = session()->get('current_organization');

        $timezone = Setting::where('key', 'timezone')->value('value');
        $organization = Organization::find($organizationId);
        $organizationMetadata = json_decode($organization->metadata ?? '{}', true);
        $timezone = $organizationMetadata['timezone'] ?? $timezone;

        $template = Template::where('uuid', $request->template)->first();
        $contactGroup = ContactGroup::where('uuid', $request->contacts)->first();

        try {
            DB::transaction(function () use ($request, $organizationId, $template, $contactGroup, $timezone) {
                //Request metadata
                $mediaId = null;
                if(in_array($request->header['format'], ['IMAGE', 'DOCUMENT', 'VIDEO'])){
                    $header = $request->header;
                    
                    if ($request->header['parameters']) {
                        $metadata['header']['format'] = $header['format'];
                        $metadata['header']['parameters'] = [];
                
                        foreach ($request->header['parameters'] as $key => $parameter) {
                            if ($parameter['selection'] === 'upload') {
                                //$path = $parameter['value']->store('public');
                                //$imageUrl = config('app.url') . '/media/' . $path;

                                $storage = Setting::where('key', 'storage_system')->first()->value;
                                $fileName = $parameter['value']->getClientOriginalName();
                                $fileContent = $parameter['value'];

                                if($storage === 'local'){
                                    $file = Storage::disk('local')->put('public', $fileContent);
                                    $mediaFilePath = $file;
                    
                                    $mediaUrl = rtrim(config('app.url'), '/') . '/media/' . ltrim($mediaFilePath, '/');
                                } else if($storage === 'aws') {
                                    $file = $parameter['value'];
                                    $uploadedFile = $file->store('uploads/media/sent/' . $organizationId, 's3');
                                    $mediaFilePath = Storage::disk('s3')->url($uploadedFile);
                    
                                    $mediaUrl = $mediaFilePath;
                                }

                                $contentType = $this->getContentTypeFromUrl($mediaUrl);
                                $mediaSize = $this->getMediaSizeInBytesFromUrl($mediaUrl);

                                //save media
                                $chatMedia = new ChatMedia;
                                $chatMedia->name = $fileName;
                                $chatMedia->path = $mediaUrl;
                                $chatMedia->type = $contentType;
                                $chatMedia->size = $mediaSize;
                                $chatMedia->created_at =DateTimeHelper::convertToOrganizationTimezone(now(),$organizationId);
                                $chatMedia->save();

                                $mediaId = $chatMedia->id;
                            } else {
                                $mediaUrl = $parameter['value'];
                            }
                
                            $metadata['header']['parameters'][] = [
                                'type' => $parameter['type'],
                                'selection' => $parameter['selection'],
                                'value' => $mediaUrl,
                            ];
                        }
                    }
                } else {
                    $metadata['header'] = $request->header;
                }

                $metadata['body'] = $request->body;
                $metadata['footer'] = $request->footer;
                $metadata['buttons'] = $request->buttons;
                $metadata['media'] = $mediaId;

                // Convert $request->time from organization's timezone to UTC
                $scheduledAt = $request->skip_schedule ? Carbon::now('UTC') : Carbon::parse($request->time, $timezone)->setTimezone('UTC');

                //Create campaign
                $campaign = new Campaign;
                $campaign['organization_id'] = $organizationId;
                $campaign['name'] = $request->name;
                $campaign['template_id'] = $template->id;
                $campaign['contact_group_id'] = $request->contacts === 'all' ? 0 : $contactGroup->id;
                $campaign['metadata'] = json_encode($metadata);
                $campaign['created_by'] = auth()->user()->id;
                $campaign['status'] = 'scheduled';
                $campaign['scheduled_at'] = $scheduledAt;
                $campaign->save();
            });
        } catch (\Exception $e) {
            // Handle the exception here if needed.
            // The transaction has already been rolled back automatically.
            Log::error('Failed to store campaign', [
                'error_message' => $e->getMessage(),
                'organization_id' => $organizationId,
                'template' => $request->template,
                'contacts' => $request->contacts,
                'user_id' => auth()->user()->id,
                'stack_trace' => $e->getTraceAsString(),
            ]);
        }
    }

    private function getMediaInfo($path)
    {
        $fullPath = storage_path('app/public/' . $path);

        return [
            'name' => pathinfo($fullPath, PATHINFO_FILENAME),
            'type' => File::extension($fullPath),
            'size' => Storage::size($path), // Size in bytes
        ];
    }

    public function sendCampaign(){
        //Laravel jobs implementation
        SendCampaignJob::dispatch();
    }

    public function destroy($uuid)
    {
        Campaign::where('uuid', $uuid)->update([
            'deleted_by' => auth()->user()->id,
            'deleted_at' => now()
        ]);
    }

    private function getContentTypeFromUrl($url) {
        try {
            // Make a HEAD request to fetch headers only
            $response = Http::head($url);
    
            // Check if the Content-Type header is present
            if ($response->hasHeader('Content-Type')) {
                return $response->header('Content-Type');
            }
    
            return null;
        } catch (\Exception $e) {
            // Log the error for debugging
            Log::error('Error fetching headers: ' . $e->getMessage());
            return null;
        }
    }

    private function getMediaSizeInBytesFromUrl($url) {
        $url = ltrim($url, '/');
        $imageContent = file_get_contents($url);
    
        if ($imageContent !== false) {
            return strlen($imageContent);
        }
    
        return null;
    }
}
