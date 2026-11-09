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
use App\Services\CampaignMediaHistoryService;
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

    /**
     * @param int|null $organizationId صراحةً من تطبيق الجوال (لا جلسة هناك)؛ الويب يتركه للجلسة.
     */
    public function store(object $request, ?int $organizationId = null): Campaign
    {
        $organizationId = $organizationId ?? session()->get('current_organization');

        $timezone = Setting::where('key', 'timezone')->value('value');
        $organization = Organization::find($organizationId);
        $organizationMetadata = json_decode($organization->metadata ?? '{}', true);
        $timezone = $organizationMetadata['timezone'] ?? $timezone;

        $channel = \App\Services\Messaging\Channel::normalize($request->channel ?? null)
            ?? \App\Services\Messaging\Channel::DEFAULT;

        // بنطاق المنشأة: معرّف قالب أو مجموعة من منشأة أخرى كان يُقبل فتُرسل
        // الحملة بقالب غيرها أو إلى جمهور غيرها.
        $contactGroup = ContactGroup::where('uuid', $request->contacts)->where('organization_id', $organizationId)->whereNull('deleted_at')->first();

        if ($request->contacts !== 'all' && !$contactGroup) {
            throw ValidationException::withMessages([
                'contacts' => __('The selected contact group is no longer available.'),
            ]);
        }

        // حملة قناة أخرى: رسالة حرّة بلا قالب ولا ترويسة. مسار مستقلّ
        // لأن ما تحته كلّه بناء قالب واتساب — معاملات الترويسة ورفع
        // وسائطها وتسجيلها في سجلّ الوسائط.
        if ($channel !== \App\Services\Messaging\Channel::WHATSAPP) {
            return $this->storeChannelCampaign($request, (int) $organizationId, $channel, $contactGroup, $timezone);
        }

        $template = Template::where('uuid', $request->template)->where('organization_id', $organizationId)->first();

        // القالب المحذوف يُخفيه الموديل الآن، فكانت السطور التالية تقرأ
        // خاصّية من null. ورسالة التحقّق أوضح من خطأ فادح.
        if (!$template) {
            throw ValidationException::withMessages([
                'template' => __('The selected template is no longer available.'),
            ]);
        }

        try {
            return DB::transaction(function () use ($request, $organizationId, $template, $contactGroup, $timezone) {
                $historyService = app(CampaignMediaHistoryService::class);
                //Request metadata
                $mediaId = null;
                if(in_array($request->header['format'], ['IMAGE', 'DOCUMENT', 'VIDEO'])){
                    $header = $request->header;

                    // الصيغة تُسجَّل ولو لم تصل معاملات: الحملة بلا مفتاح
                    // header كانت تصل إلى buildTemplate فتُرسَل بلا مكوّن
                    // ترويسة، وMeta تملؤها من مثال القالب نفسه.
                    $metadata['header']['format'] = $header['format'];
                    $metadata['header']['parameters'] = [];

                    if ($request->header['parameters']) {
                
                        foreach ($request->header['parameters'] as $key => $parameter) {
                            if ($parameter['selection'] === 'upload') {
                                $storage = Setting::where('key', 'storage_system')->first()->value;
                                $fileName = $parameter['value']->getClientOriginalName();
                                $fileContent = $parameter['value'];
                                $location = 'local';

                                if($storage === 'local'){
                                    $file = Storage::disk('local')->put('public', $fileContent);
                                    $mediaFilePath = $file;
                    
                                    $mediaUrl = rtrim(config('app.url'), '/') . '/media/' . ltrim($mediaFilePath, '/');
                                } else if($storage === 'aws') {
                                    $location = 'amazon';
                                    $file = $parameter['value'];
                                    $uploadedFile = $file->store('uploads/media/sent/' . $organizationId, 's3');
                                    
                                    if (empty($uploadedFile)) {
                                        throw new \Exception('Failed to upload file to S3 storage');
                                    }
                                    
                                    $mediaFilePath = Storage::disk('s3')->url($uploadedFile);
                    
                                    $mediaUrl = $mediaFilePath;
                                } else {
                                    throw new \Exception('Unsupported storage system');
                                }

                                // النوع والحجم من الملف المرفوع نفسه لا من رابطه: جلب
                                // الرابط طلبٌ من الخادم إلى نفسه، يفشل حيث لا يصل
                                // عنوانه (localhost، شبكة مغلقة) فيصل الحجم null
                                // ويسقط الحفظ — chat_media.size لا يقبل null.
                                $contentType = $fileContent->getMimeType() ?: null;
                                $mediaSize = $fileContent->getSize() ?: null;

                                if (!empty($mediaUrl) && ($contentType === null || $mediaSize === null)) {
                                    $contentType ??= $this->getContentTypeFromUrl($mediaUrl);
                                    $mediaSize ??= $this->getMediaSizeInBytesFromUrl($mediaUrl);
                                }

                                $chatMedia = new ChatMedia;
                                $chatMedia->name = $fileName;
                                $chatMedia->path = $mediaUrl;
                                $chatMedia->location = $location;
                                $chatMedia->type = $contentType;
                                $chatMedia->size = $mediaSize;
                                $chatMedia->created_at = now();
                                $chatMedia->save();

                                $mediaId = $chatMedia->id;

                                $historyService->record(
                                    $organizationId,
                                    auth()->id(),
                                    $parameter['type'],
                                    $fileName,
                                    $mediaUrl,
                                    $location,
                                    $contentType,
                                    $mediaSize !== null ? (string) $mediaSize : null,
                                    $chatMedia->id,
                                );
                            } elseif ($parameter['selection'] === 'history') {
                                $historyItem = $historyService->findByReferenceForOrganization(
                                    $organizationId,
                                    (string) $parameter['value']
                                );

                                if (!$historyItem) {
                                    throw ValidationException::withMessages([
                                        'header.parameters.0.value' => __('The selected media file is no longer available.'),
                                    ]);
                                }

                                $mediaUrl = $historyItem->path;
                                $mediaId = $historyItem->chat_media_id;
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

                return $campaign;
            });
        } catch (\Exception $e) {
            Log::error('Failed to store campaign', [
                'error_message' => $e->getMessage(),
                'organization_id' => $organizationId,
                'template' => $request->template,
                'contacts' => $request->contacts,
                'user_id' => auth()->user()->id,
                'stack_trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * حفظ حملة على قناة غير واتساب.
     *
     * الرسالة الحرّة تُخزَّن في campaigns.message_payload، و template_id
     * يبقى NULL — ولذلك أُرخي القيد في الترحيلة 100400.
     *
     * الوسائط تُرفع مرّة واحدة هنا ويُخزَّن رابطها: المنصّة تُنزّله بنفسها
     * لكل مستلم، فلا نرفعه ألف مرّة.
     */
    private function storeChannelCampaign(object $request, int $organizationId, string $channel, $contactGroup, $timezone)
    {
        $manager = app(\App\Services\Messaging\ChannelManager::class);
        $account = $manager->activeAccount($organizationId, $channel);

        if (!$account) {
            throw ValidationException::withMessages([
                'channel' => $manager->connectionError($organizationId, $channel),
            ]);
        }

        $text = trim((string) ($request->message_text ?? ''));

        if ($text === '') {
            throw ValidationException::withMessages([
                'message_text' => __('Message cannot be empty.'),
            ]);
        }

        $media = null;
        $file = method_exists($request, 'file') ? $request->file('message_media') : null;

        if ($file) {
            $capabilities = $manager->capabilities($channel);
            $type = $this->mediaTypeFor($file->getMimeType());

            if (!$capabilities->supportsMedia($type)) {
                throw ValidationException::withMessages([
                    'message_media' => __(':feature is not available on :channel.', [
                        'feature' => __(':type attachments', ['type' => $type]),
                        'channel' => \App\Services\Messaging\Channel::label($channel),
                    ]),
                ]);
            }

            $limit = $capabilities->mediaLimit($type);

            if ($limit !== null && $file->getSize() > $limit) {
                throw ValidationException::withMessages([
                    'message_media' => __('This file is larger than :channel allows (:limit MB).', [
                        'channel' => \App\Services\Messaging\Channel::label($channel),
                        'limit' => round($limit / 1024 / 1024, 1),
                    ]),
                ]);
            }

            $media = $this->storeCampaignMedia($file, $organizationId, $type);
        }

        $scheduledAt = $request->skip_schedule
            ? Carbon::now('UTC')
            : Carbon::parse($request->time, $timezone)->setTimezone('UTC');

        $campaign = new Campaign;
        $campaign['organization_id'] = $organizationId;
        $campaign['name'] = $request->name;
        $campaign['channel'] = $channel;
        $campaign['channel_account_id'] = $account->id;
        $campaign['template_id'] = null;
        $campaign['contact_group_id'] = $request->contacts === 'all' ? 0 : $contactGroup->id;
        $campaign['metadata'] = json_encode([]);
        $campaign['message_payload'] = \App\Support\JsonText::encode([
            'text' => $text,
            'media' => $media,
        ]);
        $campaign['created_by'] = auth()->user()->id;
        $campaign['status'] = 'scheduled';
        $campaign['scheduled_at'] = $scheduledAt;
        $campaign->save();

        return $campaign;
    }

    /** نوع الوسائط كما يفهمه النظام من نوع MIME. */
    private function mediaTypeFor(?string $mimeType): string
    {
        $mimeType = (string) $mimeType;

        return match (true) {
            str_starts_with($mimeType, 'image/') => 'image',
            str_starts_with($mimeType, 'video/') => 'video',
            str_starts_with($mimeType, 'audio/') => 'audio',
            default => 'document',
        };
    }

    /**
     * @return array{url: string, name: string, type: string, mime_type: string, size: int, location: string}
     */
    private function storeCampaignMedia($file, int $organizationId, string $type): array
    {
        $contents = file_get_contents($file->getRealPath());
        $fileName = $file->getClientOriginalName();
        $safeName = uniqid() . '_' . sanitize_filename_for_storage($fileName);
        $storage = Setting::where('key', 'storage_system')->first()?->value;

        if ($storage === 'aws') {
            $path = 'uploads/media/campaigns/' . $organizationId . '/' . $safeName;
            Storage::disk('s3')->put($path, $contents, ['ContentType' => $file->getMimeType()]);
            $url = Storage::disk('s3')->url($path);
            $location = 'amazon';
        } else {
            $stored = Storage::disk('local')->put('public/' . $safeName, $contents);
            $url = rtrim(config('app.url'), '/') . '/media/' . ltrim($stored, '/');
            $location = 'local';
        }

        return [
            'url' => $url,
            'name' => $fileName,
            'type' => $type,
            'mime_type' => $file->getMimeType(),
            'size' => (int) $file->getSize(),
            'location' => $location,
        ];
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
        if (empty($url)) {
            return null;
        }
        
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
        if (empty($url)) {
            return null;
        }
        
        try {
            $url = ltrim($url, '/');
            $imageContent = file_get_contents($url);
        
            if ($imageContent !== false) {
                return strlen($imageContent);
            }
        } catch (\Exception $e) {
            Log::error('Error getting media size from URL: ' . $e->getMessage());
        }
    
        return null;
    }
}
