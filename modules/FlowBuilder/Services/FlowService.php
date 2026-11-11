<?php

namespace Modules\FlowBuilder\Services;

use App\Models\Setting;
use App\Services\Messaging\AutomationChannels;
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
     * مسارٌ منشور لا يُستبدل بنسخةٍ معطوبة: كان «حفظ» يُلغي النشر بصمت حين
     * تنقص خطوةٌ إعداداً، ويُظهر «تم الحفظ بنجاح»، فيتوقّف البوت عن الردّ على
     * كل العملاء. الآن يُرفض الحفظ ويبقى المسار يعمل بآخر نسخة سليمة، مع قائمة
     * الأخطاء لكل خطوة. والمسار غير المنشور يُحفظ كما هو مع الأخطاء تنبيهاً.
     *
     * @param string $uuid
     * @param array $data
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateFlow($uuid, array $data, $publish)
    {
        $flow = Flow::ownedOrAbort($uuid);

        // المتصفح لا يرى كلمات مرور SMTP (FlowSecrets::redact)، فيعيدها الحفظ
        // من النسخة المخزّنة بدل أن يمسحها.
        if (array_key_exists('metadata', $data)) {
            $data['metadata'] = FlowSecrets::restore($data['metadata'], $flow->metadata);
        }

        $metadata = $data['metadata'] ?? $flow->metadata;
        $graph = $metadata ? (json_decode($metadata, true) ?: []) : [];
        $result = $graph !== [] ? (new FlowValidator())->validateMessageNodes($graph) : true;
        $errors = $result === true ? [] : $result;

        $publishing = isset($publish) && (int) $publish === 1;
        $unpublishing = isset($publish) && (int) $publish === 0;

        if ($errors !== [] && $flow->status === 'active' && !$unpublishing) {
            return response()->json([
                'success' => false,
                'message' => __('The flow is live, so these changes were not saved. Fix the problems below, or unpublish the flow first.'),
                'errors' => $errors,
                'status' => 'active',
            ], 200);
        }

        if ($graph !== []) {
            $data = array_merge($data, $this->triggerColumns($graph));
        }

        if ($publishing) {
            $data['status'] = $errors === [] ? 'active' : 'inactive';
        } elseif ($unpublishing) {
            $data['status'] = 'inactive';
        }

        $flow->update($data);

        if ($publishing && $errors !== []) {
            return response()->json([
                'success' => false,
                'message' => __('The flow was saved, but it cannot be published until these problems are fixed.'),
                'errors' => $errors,
                'status' => 'inactive',
            ], 200);
        }

        return response()->json([
            'success' => true,
            'message' => match (true) {
                $publishing => __('Flow saved & published successfully!'),
                $unpublishing => __('Flow saved & unpublished successfully!'),
                $errors !== [] => __('Flow saved. Fix the problems below before publishing.'),
                default => __('Flow saved successfully!'),
            },
            'errors' => $errors,
            'status' => $flow->status,
        ], 200);
    }

    /**
     * أعمدة المحفّز من عقدة البداية — بنوعها لا بموضعها. «nodes.0» كان يقرأ
     * أوّل عقدة أيّاً كانت، فإن حُذفت البداية صار المحفّز «text» ويرفضه
     * عمود enum فيسقط الحفظ بخطأ 500.
     */
    private function triggerColumns(array $graph): array
    {
        $fields = [];
        foreach ($graph['nodes'] ?? [] as $node) {
            if (($node['type'] ?? null) === 'start') {
                $fields = $node['data']['metadata']['fields'] ?? [];
                break;
            }
        }
        $fields = is_array($fields) ? $fields : [];

        $trigger = $fields['type'] ?? null;
        $trigger = in_array($trigger, ['new_contact', 'keywords', 'first_message'], true) ? $trigger : null;

        // المهلة بالدقائق: الفراغ غيابٌ لا صفر — و(int)'' يساوي صفراً
        // فتُحفظ مهلةً معطّلة بدل ألّا تُحفظ.
        $timeout = $fields['timeout'] ?? null;
        $timeout = is_numeric($timeout) ? (int) $timeout : null;

        $columns = [
            'trigger' => $trigger,
            // «hi, hello» و«مرحبا، عرض» تُخزَّن «hi,hello» و«مرحبا,عرض»: انظر KeywordMatcher.
            'keywords' => KeywordMatcher::normalize($fields['keywords'] ?? null),
            'trigger_timeout' => ($timeout !== null && $timeout > 0) ? $timeout : null,
        ];

        // القنوات من عقدة البداية كبقيّة المحفّز. مسارٌ حُفظ قبل اختيارها لا
        // يحمل المفتاح، فيبقى ما عُبّئ في العمود بدل أن يُمحى.
        if (array_key_exists('channels', $fields)) {
            $columns['channels'] = AutomationChannels::normalize($fields['channels']);
        }

        return $columns;
    }

    /**
     * النسخة غير منشورة: كانت تُنسخ «نشطة» بالمحفّز نفسه فتبدو تعمل ولا تعمل
     * (الأقدم يفوز). والترقيم يعدّ مسارات المنشأة وحدها.
     */
    public function duplicateFlow($uuid): void
    {
        $flow = Flow::ownedOrAbort($uuid);

        // Get the base name without any existing (number) suffix
        $baseName = trim(preg_replace('/\(\d+\)$/', '', trim($flow->name)));
        $escaped = addcslashes($baseName, '\\%_');

        // Find existing duplicates
        $count = Flow::where('organization_id', $flow->organization_id)
            ->where(function ($query) use ($escaped, $baseName) {
                $query->where('name', 'LIKE', "{$escaped} (%)")->orWhere('name', $baseName);
            })
            ->count();

        // Set the new name with an incremented number
        $newName = $count ? "{$baseName} ({$count})" : "{$baseName} (1)";

        // Duplicate the flow and assign the new name
        $duplicate = $flow->replicate(['uuid']);
        $duplicate->name = $newName;
        $duplicate->status = 'inactive';
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