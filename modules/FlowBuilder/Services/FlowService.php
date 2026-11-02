<?php

namespace Modules\FlowBuilder\Services;

use App\Models\Setting;
use Modules\FlowBuilder\Models\Flow;
use Modules\FlowBuilder\Models\FlowMedia;
use Modules\FlowBuilder\Models\FlowUserData;
use Modules\FlowBuilder\Resources\FlowResource;
use Modules\FlowBuilder\Support\FlowSecrets;
use Modules\FlowBuilder\Support\KeywordMatcher;
use Modules\FlowBuilder\Validators\FlowValidator;

class FlowService
{
    public function getRows(object $request)
    {
        $organizationId = session()->get('current_organization');
        $model = new Flow;
        $searchTerm = $request->query('search');

        return FlowResource::collection($model->listAll($organizationId, $searchTerm));
    }

    /**
     * Create a new flow.
     *
     * @param array $data
     * @return Flow
     */
    public function createFlow(array $data): Flow
    {
        $organizationId = session()->get('current_organization');

        return Flow::create([
            'organization_id' => $organizationId,
            'name' => $data['name'],
            'description' => $data['description'],
            'status' => 'inactive',
        ]);
    }

    /**
     * Update an existing flow.
     *
     * @param string $uuid
     * @param array $data
     * @return Flow
     */
    public function updateFlow($uuid, array $data, $publish)
    {
        $validator = new FlowValidator();

        $flow = Flow::ownedOrAbort($uuid);

        // المتصفح لا يرى كلمات مرور SMTP (FlowSecrets::redact)، فيعيدها الحفظ
        // من النسخة المخزّنة بدل أن يمسحها.
        if (array_key_exists('metadata', $data)) {
            $data['metadata'] = FlowSecrets::restore($data['metadata'], $flow->metadata);
        }

        if(isset($flow->metadata)){
            $metadataArray = json_decode($flow->metadata, true);
            $result = $validator->validateMessageNodes($metadataArray);

            if(is_array($result)){
                $data['status'] = 'inactive';
            }
        }

        $flow->update($data);

        $flow->refresh();
        
        if(isset($flow->metadata)){
            $metadataArray = json_decode($flow->metadata, true);
            $data2['trigger'] = \Arr::get($metadataArray, 'nodes.0.data.metadata.fields.type', null);
            // «hi, hello» و«مرحبا، عرض» تُخزَّن «hi,hello» و«مرحبا,عرض»: انظر KeywordMatcher.
            $data2['keywords'] = KeywordMatcher::normalize(\Arr::get($metadataArray, 'nodes.0.data.metadata.fields.keywords', null));

            // المهلة بالدقائق: الفراغ غيابٌ لا صفر — و(int)'' يساوي صفراً
            // فتُحفظ مهلةً معطّلة بدل ألّا تُحفظ.
            $timeout = \Arr::get($metadataArray, 'nodes.0.data.metadata.fields.timeout', null);
            $timeout = is_numeric($timeout) ? (int) $timeout : null;
            $data2['trigger_timeout'] = ($timeout !== null && $timeout > 0) ? $timeout : null;

            $result = $validator->validateMessageNodes($metadataArray);

            if(is_array($result)){
                $data2['status'] = 'inactive';
            }

            $flow->update($data2);
        }

        if(isset($publish)){
            $validator = new FlowValidator();
            $metadataArray = json_decode($flow->metadata, true);
            $status = $publish == 1 ? 'active' : 'inactive';

            if($publish == 1){
                $result = $validator->validateMessageNodes($metadataArray);

                if (!is_array($result)) {
                    $flow->update(['status' => $status]);

                    return response()->json([
                        'success' => true,
                        'message' => __('Flow saved & published successfully!'),
                        'status' => 'active'
                    ]);
                } else {
                    // Return the errors in JSON format with a 422 Unprocessable Entity status code
                    return response()->json([
                        'success' => false,
                        'errors' => $result,
                        'status' => 'inactive'
                    ], 200);
                }
            } else {
                $flow->update(['status' => $status]);

                return response()->json([
                    'success' => true,
                    'message' => __('Flow saved & unpublished successfully!'),
                    'status' => 'inactive'
                ], 200);
            }
        }

        return response()->json([
            'success' => true,
            'message' => __('Flow saved successfully!'),
            'status' => $flow->status,
        ], 200);

        return $flow;
    }

    public function duplicateFlow($uuid): void
    {
        $flow = Flow::ownedOrAbort($uuid);

        if (!$flow) {
            return; // Exit early if the flow doesn't exist
        }

        // Get the base name without any existing (number) suffix
        $baseName = preg_replace('/\(\d+\)$/', '', trim($flow->name));

        // Find existing duplicates
        $count = Flow::where('name', 'LIKE', "{$baseName} (%)")
            ->orWhere('name', $baseName)
            ->count();

        // Set the new name with an incremented number
        $newName = $count ? "{$baseName} ({$count})" : "{$baseName} (1)";

        // Duplicate the flow and assign the new name
        $duplicate = $flow->replicate(['uuid']);
        $duplicate->name = $newName;
        $duplicate->save();
    }

    /**
     * ما يقبله واتساب لكل نوع وسائط: الامتدادات وحدّ الحجم بالكيلوبايت.
     * https://developers.facebook.com/docs/whatsapp/cloud-api/reference/media#supported-media-types
     */
    public const MEDIA_RULES = [
        'image' => ['extensions' => ['jpg', 'jpeg', 'png'], 'max' => 5 * 1024],
        'video' => ['extensions' => ['mp4', '3gp'], 'max' => 16 * 1024],
        'audio' => ['extensions' => ['aac', 'amr', 'mp3', 'm4a', 'ogg'], 'max' => 16 * 1024],
        'document' => ['extensions' => ['pdf', 'txt', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'], 'max' => 100 * 1024],
    ];

    /**
     * أنواع المحتوى التي تُخدَم كما هي من نطاق التطبيق نفسه وتُشغّل سكربت
     * (html/svg/xml…). امتداد الملف يكذب، فالفحص على المحتوى المكتشف.
     */
    private const DANGEROUS_MIME = '/html|xml|svg|javascript|ecmascript|x-php|x-httpd|x-sh|x-msdownload|x-executable/i';

    public static function mediaMaxKilobytes(?string $type): int
    {
        return self::MEDIA_RULES[$type]['max'] ?? max(array_column(self::MEDIA_RULES, 'max'));
    }

    /** سبب رفض الملف، أو null إن كان مقبولاً لهذا النوع. */
    public static function mediaRejection(?string $type, \Illuminate\Http\UploadedFile $file): ?string
    {
        $allowed = isset(self::MEDIA_RULES[$type])
            ? self::MEDIA_RULES[$type]['extensions']
            : array_merge(...array_column(self::MEDIA_RULES, 'extensions'));

        $extension = strtolower($file->getClientOriginalExtension());

        if (!in_array($extension, $allowed, true) || preg_match(self::DANGEROUS_MIME, (string) $file->getMimeType())) {
            return __('This file type is not supported by WhatsApp.');
        }

        return null;
    }

    public function uploadMedia($request, Flow $flow, $stepId)
    {
        $fileName = $request->file('file')->getClientOriginalName();
        $fileContent = $request->file('file');
        $storage = Setting::where('key', 'storage_system')->first()->value;

        // Get file extension or MIME type
        $fileExtension = strtolower($fileContent->getClientOriginalExtension());
        $fileMimeType = strtolower($fileContent->getMimeType());

        if($storage === 'local'){
            $location = 'local';
            // الامتداد من القائمة المسموحة لا من تخمين النوع: ملف html باسم
            // .pdf كان يُخزَّن .html ويُخدَم صفحةً من نطاق التطبيق.
            $file = \Storage::disk('local')->putFileAs('public', $fileContent, \Illuminate\Support\Str::random(40) . '.' . $fileExtension);
            $mediaFilePath = $file;
            $mediaUrl = rtrim(config('app.url'), '/') . '/media/' . ltrim($mediaFilePath, '/');
        } else if($storage === 'aws') {
            $organizationId = session()->get('current_organization');
            $location = 'amazon';
            $file = $request->file('file');
            $filePath = 'uploads/media/received/'  . $organizationId . '/' . $fileName;
            $uploadedFile = $file->store('uploads/media/sent/' . $organizationId, 's3');
            $mediaFilePath = \Storage::disk('s3')->url($uploadedFile);
            $mediaUrl = $mediaFilePath;
        }

        $flowMedia = FlowMedia::create([
            'flow_id' => $flow->id,
            'step_id' => $stepId,
            'path' => $mediaUrl,
            'location' => $storage,
            'metadata' => json_encode([
                'name' => $fileName,
                'extension' => $fileMimeType,
                'size' => $fileContent->getSize()
            ])
        ]);

        return $flowMedia;
    }

    /**
     * Delete a flow and its related steps.
     *
     * @param Flow $flow
     * @return void
     */
    public function deleteFlow($uuid): void
    {
        $flow = Flow::ownedOrAbort($uuid);

        $flow->delete(); // Cascade will delete related steps
    }

    public function handleUserReply($contactId)
    {
        // Get user progress or start at step 1
        $userProgress = FlowUserData::firstOrCreate(
            ['contact_id' => $contactId],
            ['current_step' => 1]
        );

        $currentStep = 1;

        $flow = Flow::where('flow_id', $userProgress->flow_id)->first();

        $nextStep = $this->getNextStep($currentStep, $flow->metadata);

        if ($nextStep) {
            // Send message for the current step
            $this->sendMessage($userId, $nextStep);
    
            // Update user progress
            $userProgress->incrementStep();
        }
    }

    private function checkSteps($metadata){

    }

    function getNextStep($currentNodeId, $metadata)
    {
        $flowMetadata = json_decode($metadata, true);
        $nodes = $flowMetadata['nodes'];
        $edges = $flowMetadata['edges'];

        // Find the edge where the source is the current node
        foreach ($edges as $edge) {
            if ($edge['source'] === $currentNodeId) {
                $nextNodeId = $edge['target'];

                // Find and return the next node details
                foreach ($nodes as $node) {
                    if ($node['id'] === $nextNodeId) {
                        return $node;
                    }
                }
            }
        }

        // Return null if there are no more steps
        return null;
    }
}