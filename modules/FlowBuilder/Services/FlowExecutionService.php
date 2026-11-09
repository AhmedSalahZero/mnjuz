<?php

namespace Modules\FlowBuilder\Services;

use App\Helpers\CustomHelper;
use App\Models\Contact;
use App\Models\Organization;
use App\Services\WhatsappService;
use App\Services\Messaging\AutomationTransport;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\FlowBuilder\Models\Flow;
use Modules\FlowBuilder\Models\FlowLog;
use Modules\FlowBuilder\Models\FlowMedia;
use Modules\FlowBuilder\Models\FlowUserData;
use Modules\FlowBuilder\Services\ActionExecutionService;
use Modules\FlowBuilder\Support\KeywordMatcher;
use App\Services\ContactPlaceholderService;

class FlowExecutionService
{
    private $whatsappService;
    private $organizationId;

    public function __construct($organizationId)
    {
        $this->organizationId = $organizationId;
        $this->initializeWhatsappService();
    }

    private function initializeWhatsappService()
    {
        $config = Organization::where('id', $this->organizationId)->first()->metadata;
        $config = $config ? json_decode($config, true) : [];

        $accessToken = $config['whatsapp']['access_token'] ?? null;
        $apiVersion = config('graph.api_version');
        $appId = $config['whatsapp']['app_id'] ?? null;
        $phoneNumberId = $config['whatsapp']['phone_number_id'] ?? null;
        $wabaId = $config['whatsapp']['waba_id'] ?? null;

        $this->whatsappService = new WhatsappService($accessToken, $apiVersion, $appId, $phoneNumberId, $wabaId, $this->organizationId);
    }
    
    /**
     * Execute a flow for a user based on their input.
     *
     * يُرجع true إن تولّى الـ flow الرسالة: أرسل ردّاً، أو نفّذ إجراءً، أو أعاد
     * السؤال، أو بدأ تأخيراً. وfalse إن لم يفعل شيئاً — فيمرّرها AutoReplyService
     * إلى بقية الردود الآلية والمساعد الذكي. كانت النتيجة false في الحالين،
     * والجلسة القائمة تُغلق بقية الردود، فتضيع رسائل العميل بلا جواب.
     *
     * @param $chat
     * @param boolean $isNewContact
     * @param string $message
     * @return bool
     */
    public function executeFlow($chat, $isNewContact, $message)
    {
        if (!CustomHelper::isModuleEnabled('Flow builder', $chat->organization_id)) {
            return false;
        }

        // النصّ كما كتبه العميل: يُصغَّر داخل المطابقة وحدها. التصغير هنا كان
        // يحفظ «John Smith» في update_contact «john smith» ويُرسله للـ webhook.
        $message = trim((string) $message);

        // Find the current step for the user in the flow
        $flowData = FlowUserData::where('contact_id', $chat->contact_id)->first();

        // جلسة ركدت أطول من مهلة الـ flow تُهجر.
        //
        // العميل الذي سُئل «اختر 1 أو 2» ثم اختفى تبقى جلسته مفتوحة —
        // فكلمته بعد ثلاثة أيام تُقرأ إجابةً على سؤال قديم. المهلة تُنهي
        // الجلسة فيبدأ محادثةً جديدة.
        if ($flowData && $this->sessionHasExpired($flowData, $chat)) {
            FlowUserData::where('contact_id', $chat->contact_id)->delete();
            $flowData = null;
        }

        if ($flowData && !Flow::whereKey($flowData->flow_id)->exists()) {
            // Flow has been deleted, remove FlowUserData and proceed as if it doesn't exist
            Log::warning("DELETION POINT 1: Flow not found, deleting FlowUserData for contact {$chat->contact_id}");
            FlowUserData::where('contact_id', $chat->contact_id)->delete();
            $flowData = null;
        }

        if ($flowData) {
            if ($this->processFlow($chat, $isNewContact, $flowData, $chat->contact_id, $message)) {
                return true;
            }

            // The flow did not take the message: a keyword may switch to (or restart) a flow.
            $keywordFlow = $this->findFlowByKeyword($message, (int) $chat->organization_id);

            return $keywordFlow ? $this->startFlow($chat, $isNewContact, $keywordFlow->id, $message) : false;
        }

        $flow = $this->resolveTriggeredFlow($chat, $isNewContact, $message);

        return $flow ? $this->startFlow($chat, $isNewContact, $flow->id, $message) : false;
    }

    private function startFlow($chat, $isNewContact, $flowId, string $message): bool
    {
        FlowUserData::where('contact_id', $chat->contact_id)->delete();

        $flowData = new FlowUserData();
        $flowData->fill([
            'contact_id' => $chat->contact_id,
            'flow_id' => $flowId,
            'current_step' => 1,
        ])->save();

        return (bool) $this->processFlow($chat, $isNewContact, $flowData, $chat->contact_id, $message);
    }

    /**
     * اختيار الـ flow الذي تُشغّله هذه الرسالة.
     *
     * الترتيب مقصود:
     *   1. first_message — أول رسالة يكتبها العميل في عمره
     *   2. new_contact   — صفّ جهة الاتصال أُنشئ بهذه الرسالة
     *   3. keywords      — الرسالة تطابق كلمة مفتاحية
     *
     * first_message يسبق new_contact لأنه أدقّ: new_contact يسأل «هل الرقم
     * جديد في قاعدة البيانات؟» لا «هل هذه أول مرة يكلّمنا فيها؟». والرقم
     * يدخل النظام من الاستيراد والحملات والإضافة اليدوية قبل أن يكتب صاحبه
     * حرفاً، فيفوت الترحيب على أكثر العملاء.
     *
     * @param  object  $chat
     * @param  bool    $isNewContact
     * @param  string  $message
     * @return object|null
     */
    public function resolveTriggeredFlow($chat, $isNewContact, $message)
    {
        $flowQuery = Flow::where('organization_id', $chat->organization_id)
            ->where('status', 'active');

        if ($this->isFirstInboundMessage($chat)) {
            $flow = (clone $flowQuery)->where('trigger', 'first_message')
                ->orderBy('id')
                ->first();

            if ($flow) {
                return $flow;
            }
        }

        // جهة اتصال جديدة بلا flow ترحيب تمضي إلى الكلمات المفتاحية. الإرجاع
        // المبكّر كان يُسقط أوّل «menu» من رقمٍ جديد (إعلان click-to-WhatsApp)
        // فلا يعمل الـ flow إلا من الرسالة الثانية.
        if ($isNewContact) {
            $flow = (clone $flowQuery)->where('trigger', 'new_contact')
                ->orderBy('id')
                ->first();

            if ($flow) {
                return $flow;
            }
        }

        $flow = $this->findFlowByKeyword((string) $message, (int) $chat->organization_id);

        if ($flow) {
            return $flow;
        }

        // آخر ما يُجرَّب: هل بدأ العميل محادثةً جديدة بعد صمت طال؟
        //
        // موضعها بعد الكلمات المفتاحية مقصود: من كتب كلمةً لها flow خاص
        // يريد ذلك الـ flow لا الترحيب. وهكذا لا يتغيّر شيء ممّا كان يعمل،
        // وإنما يُملأ الفراغ الذي كان لا يحدث فيه شيء إطلاقاً.
        return $this->findTimedOutFlow($chat);
    }

    /**
     * flow انقضت مهلته: العميل عاد بعد صمت أطول من `trigger_timeout`.
     *
     * الفجوة تُقاس بين هذه الرسالة والواردة التي سبقتها — وهو ما يعرّف
     * «محادثة جديدة» عملياً بلا تخزين إضافي.
     *
     * المهلة تخصّ المحفّزين اللذين يشتغلان مرّة واحدة (first_message
     * وnew_contact). أمّا keywords فيعيد نفسه بالكلمة أصلاً.
     *
     * «سبقتها» بالوقت لا بالمعرّف: سجلّ المحادثات المستورد من منصّة أخرى
     * يُكتب بعد الرسائل الحيّة فيحمل معرّفات أكبر وتواريخ أقدم. بالمعرّف
     * كانت أوّل رسالة حقيقية بعد الاستيراد تُقاس من رسالة مستوردة عمرها
     * أشهر، فيبدأ الترحيب لعميلٍ يتحدّث الآن.
     */
    private function findTimedOutFlow($chat)
    {
        $currentAt = \DB::table('chats')->where('id', $chat->id)->value('created_at');

        if (empty($currentAt)) {
            return null;
        }

        $previousAt = \DB::table('chats')
            ->where('contact_id', $chat->contact_id)
            ->where('type', 'inbound')
            // رسالتان في الثانية نفسها: الأسبق معرّفاً هي السابقة، وإلا رأت كلٌّ
            // منهما الأخرى قبلها بصفر دقائق فلا يبدأ الترحيب لأيّ منهما.
            ->where(function ($q) use ($currentAt, $chat) {
                $q->where('created_at', '<', $currentAt)
                    ->orWhere(function ($same) use ($currentAt, $chat) {
                        $same->where('created_at', $currentAt)->where('id', '<', $chat->id);
                    });
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->value('created_at');

        if ($previousAt === null) {
            return null; // لا رسالة سابقة — يغطّيها first_message
        }

        $minutes = $this->minutesSince($previousAt, $chat);

        if ($minutes === null) {
            return null;
        }

        return Flow::where('organization_id', $chat->organization_id)
            ->where('status', 'active')
            ->whereIn('trigger', ['first_message', 'new_contact'])
            ->whereNotNull('trigger_timeout')
            ->where('trigger_timeout', '>', 0)
            ->where('trigger_timeout', '<=', $minutes)
            ->orderBy('id')
            ->first();
    }

    /**
     * هل ركدت الجلسة أطول من مهلة الـ flow الذي تخصّه؟
     *
     * flow بلا مهلة لا تنتهي جلسته — السلوك القديم يبقى لمن لم يضبط شيئاً.
     */
    private function sessionHasExpired($flowData, $chat): bool
    {
        $timeout = (int) Flow::where('id', $flowData->flow_id)->value('trigger_timeout');

        if ($timeout <= 0) {
            return false;
        }

        $minutes = $this->minutesSince($flowData->updated_at, $chat);

        return $minutes !== null && $minutes >= $timeout;
    }

    /**
     * الدقائق بين وقتٍ ماضٍ ووقت هذه الرسالة.
     *
     * وقت الرسالة يُقرأ من القاعدة لا من الموديل: خاصية created_at تمرّ
     * بمحوّل يُخرجها بتوقيت المنشأة نصّاً، فطرحها من طابع UTC يُنتج فرقاً
     * بمقدار إزاحة المنطقة الزمنية.
     */
    private function minutesSince($since, $chat): ?int
    {
        if (empty($since)) {
            return null;
        }

        $currentAt = \DB::table('chats')->where('id', $chat->id)->value('created_at');

        if (empty($currentAt)) {
            return null;
        }

        try {
            $from = \Illuminate\Support\Carbon::parse($since, 'UTC');
            $to = \Illuminate\Support\Carbon::parse($currentAt, 'UTC');
        } catch (\Throwable) {
            return null;
        }

        return (int) $from->diffInMinutes($to, true);
    }

    /**
     * هل هذه أوّل رسالة واردة من هذا العميل على الإطلاق؟
     *
     * تُقاس بالمعرّف لا بالوقت: رسالتان في الثانية نفسها تحملان الطابع
     * الزمني نفسه، فالأقدم وحدها تُعدّ الأولى.
     *
     * والمحذوفة تُحسب: حذف موظّفٍ للمحادثة لا يجعل العميل جديداً، وإلا
     * تكرّرت رسالة الترحيب مع كل حذف عابر. لذلك نستعلم بـ DB لا بالموديل —
     * الأخير يستبعد المحذوف تلقائياً.
     */
    public function isFirstInboundMessage($chat): bool
    {
        return !\DB::table('chats')
            ->where('contact_id', $chat->contact_id)
            ->where('type', 'inbound')
            ->where('id', '<', $chat->id)
            ->exists();
    }

    public function hasActiveFlow($chat){
        $activeFlow = FlowUserData::where('contact_id', $chat->contact_id)->first();

        if (!$activeFlow) {
            return false;
        }

        // الوحدة معطّلة (انتهت التجربة مثلاً): executeFlow لا يفعل شيئاً، والجلسة
        // القائمة كانت تُبقي الردود الآلية والمساعد الذكي مغلقة عن العميل.
        if (!CustomHelper::isModuleEnabled('Flow builder', $chat->organization_id)) {
            FlowUserData::where('contact_id', $chat->contact_id)->delete();
            return false;
        }

        $flow = Flow::where('id', $activeFlow->flow_id)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->first();

        if (!$flow) {
            FlowUserData::where('contact_id', $chat->contact_id)->delete();
            return false;
        }

        return true;
    }

    /**
     * Continue a flow that was paused due to a delay action
     *
     * @param int $contactId
     * @param int $flowId
     * @param int $currentStep عقدة التأخير نفسها: يُكمَل بما بعدها.
     * @return bool
     */
    public function continueDelayedFlow($contactId, $flowId, $currentStep)
    {
        // Find the flow user data
        $flowData = FlowUserData::where('contact_id', $contactId)
            ->where('flow_id', $flowId)
            ->first();

        if (!$flowData) {
            Log::warning("FlowUserData not found for delayed flow continuation: contact {$contactId}, flow {$flowId}");
            return false;
        }

        // العميل غادر التأخير (اختار فرعاً آخر، أو بدأ مساراً بكلمة): لا يُعاد
        // إلى هنا ولا يُرسل له ما بعد التأخير.
        if ((string) $flowData->current_step !== (string) $currentStep) {
            Log::info("Delayed flow skipped - contact moved on", [
                'contact_id' => $contactId,
                'flow_id' => $flowId,
                'expected_step' => $currentStep,
                'current_step' => $flowData->current_step,
            ]);
            return false;
        }

        // Get the contact
        $contact = Contact::find($contactId);
        if (!$contact) {
            Log::warning("Contact not found for delayed flow continuation: {$contactId}");
            return false;
        }

        // Create a mock chat object for processing
        $chat = (object) [
            'contact_id' => $contactId,
            'organization_id' => $this->organizationId
        ];

        // Continue processing the flow from the current step
        return $this->processFlow($chat, false, $flowData, $contactId, '', true);
    }

    /**
     * @param bool $resumingDelay المهمة المؤجّلة تُكمل من عقدة التأخير نفسها.
     * @return bool|string true/'validation_failed'/'delayed' إن تولّى الـ flow
     *                     الرسالة، وfalse إن لم يفعل شيئاً.
     */
    public function processFlow($chat, $isNewContact, $flowData, $contactId, $message, bool $resumingDelay = false){
        $flow = Flow::find($flowData->flow_id);

        if (!$flow || empty($flow->metadata)) {
            Log::warning("processFlow: flow not found or empty metadata", ['flow_id' => $flowData->flow_id]);
            FlowUserData::where('contact_id', $contactId)->delete();
            return false;
        }

        $contact = Contact::find($contactId);
        if (!$contact) {
            return false;
        }

        $message = (string) $message;
        $metadata = json_decode($flow->metadata, true) ?: [];
        $edges = \Arr::get($metadata, "edges", []) ?? [];

        // العميل ينتظر انقضاء تأخير: رسالته ليست جواباً لما بعده. كانت تُعيد
        // تشغيل التأخير فتُسقطها، ويضيع معها أيّ كلمة مفتاحية. الآن تُترك
        // لبقية الردود (أو لكلمة تبدأ مساراً آخر) والمهمة المؤجّلة تُكمل في موعدها.
        if (!$resumingDelay && $this->isDelayNode($this->findNodeById($metadata, $flowData->current_step))) {
            return false;
        }

        // Process nodes continuously until we encounter a message node
        $maxIterations = 50; // Prevent infinite loops
        $iteration = 0;
        $jumpedBack = false; // Track if we already attempted a backward jump (prevent re-looping)
        $startStep = (string) $flowData->current_step;
        $handled = false; // نُفّذ إجراءٌ في هذه الجولة

        while ($iteration < $maxIterations) {
            $iteration++;
            // Get the current node metadata
            $metadataArray = $this->findEdgesBySource($edges, $flowData->current_step, $message);

            if (empty($metadataArray)) {
                $edgesFromCurrentStep = 0;
                foreach ($edges as $edge) {
                    if (isset($edge['source']) && (string)$edge['source'] === (string)$flowData->current_step) {
                        $edgesFromCurrentStep++;
                    }
                }

                // الرجوع إلى قائمة سابقة بكتابة أحد خياراتها، من الخطوة التي كان
                // عندها العميل فقط. بعد إجراءٍ في هذه الجولة كان الرجوع يجد الزرّ
                // نفسه فيُنفَّذ الإجراء مرّتين (webhook، بريد، إضافة لمجموعة).
                if (!$jumpedBack && (string) $flowData->current_step === $startStep) {
                    $previousStep = $this->findPreviousMatchingStep($edges, $flowData->current_step, $message);
                    if ($previousStep !== null) {
                        FlowUserData::where('contact_id', $contactId)->update(['current_step' => $previousStep]);
                        $flowData = FlowUserData::where('contact_id', $contactId)->first();
                        $jumpedBack = true;
                        continue; // Re-process with the previous matching step
                    }
                }

                if ($edgesFromCurrentStep > 0 && !$handled) {
                    return $this->handleUnmatchedReply($metadata, $flowData, $contact, $contactId, $message);
                }

                if ($edgesFromCurrentStep == 0) {
                    // Terminal node with no match anywhere — flow is truly done
                    FlowUserData::where('contact_id', $contactId)->delete();
                }
                return $handled;
            }

            // Check if this is an action node
            $nodeType = \Arr::get($metadataArray, "type", null);
            if ($nodeType === 'action') {
                $result = $this->processActionNode($metadataArray, $contact, $message, $flowData, $contactId);

                if ($result === false) {
                    // Action failed, stop the flow
                    return $handled;
                }

                if ($result === 'validation_failed') {
                    // Validation failed (e.g., invalid email), stay on the same node
                    return 'validation_failed'; // Return special value to indicate validation failure
                }

                if ($result === 'delayed') {
                    // Flow is paused due to delay action, don't proceed to next step
                    return 'delayed'; // Return special value to indicate the flow is paused
                }

                $handled = true;

                // Action completed successfully, refresh flowData and continue to next node
                $flowData = FlowUserData::where('contact_id', $contactId)->first();
                if (!$flowData) {
                    Log::warning("DELETION POINT 7: FlowUserData not found after action, ending flow");
                    return $handled;
                }
                continue;
            }

            // This is a message node (text, media, interactive, etc.) - process it and stop
            return $this->processMessageNode($metadataArray, $contact, $flowData, $contactId) || $handled;
        }

        Log::warning("DELETION POINT 3: Maximum iterations reached for contact {$contactId}, ending flow");
        FlowUserData::where('contact_id', $contactId)->delete();
        return $handled;
    }

    /**
     * ردٌّ لا يطابق أيّ زرّ أو صفّ في العقدة الحالية.
     *
     * كان العميل يبقى في الـ flow بلا ردّ، والردود الآلية والمساعد الذكي مغلقة
     * عنه ما دامت جلسته قائمة. الآن يُعاد عليه السؤال مرّة، وفي الثانية تنتهي
     * الجلسة فتُجيبه بقية الردود. والكلمة المفتاحية تسبق الإعادة: من كتبها
     * يريد مسارها لا السؤال نفسه.
     */
    private function handleUnmatchedReply(array $metadata, $flowData, $contact, $contactId, string $message)
    {
        if ($this->findFlowByKeyword($message, (int) $contact->organization_id)) {
            return false;
        }

        $node = $this->findNodeById($metadata, $flowData->current_step);
        $fields = $node['data']['metadata']['fields'] ?? [];
        $asksForChoice = $node !== null && $this->choiceHandle($fields, $message) !== false;

        if ($asksForChoice && (int) ($flowData->unmatched_replies ?? 0) < 1
            && $this->processMessageNode($node, $contact, $flowData, $contactId)) {
            FlowUserData::where('contact_id', $contactId)->update(['unmatched_replies' => 1]);

            return true;
        }

        FlowUserData::where('contact_id', $contactId)->delete();

        return false;
    }

    /**
     * Process message nodes (text, media, interactive buttons, etc.)
     */
    private function processMessageNode($metadataArray, $contact, $flowData, $contactId)
    {
        // WhatsApp requires a valid phone number in the "to" field.
        // A missing/empty phone produces error_code 100 "The parameter to is required".
        if (AutomationTransport::isWhatsapp($contact) && empty($contact->phone)) {
            Log::error('Flow message skipped: contact has no phone number', [
                'flow_id'    => $flowData->flow_id,
                'contact_id' => $contactId,
                'node_id'    => $metadataArray['id'] ?? null,
            ]);

            return false;
        }

        // القنوات الأخرى (المحادثة المباشرة): الجهة بلا رقم، ويكفي أن تصلها قناتها.
        if (!AutomationTransport::isWhatsapp($contact) && !AutomationTransport::canReach($contact)) {
            Log::error('Flow message skipped: contact cannot be reached on its channel', [
                'flow_id'    => $flowData->flow_id,
                'contact_id' => $contactId,
                'channel'    => $contact->channel,
                'node_id'    => $metadataArray['id'] ?? null,
            ]);

            return false;
        }

        $sender = $this->senderFor($contact);

        $fieldsArray = \Arr::get($metadataArray, "data.metadata.fields", null);
        $type = $fieldsArray['type'] ?? null;

        // حدود واتساب: 4096 للنصّ، و1024 لمتن الرسالة التفاعلية. قصّ النصّ
        // عند 1024 كان يحذف آخر الرسائل الطويلة بصمت.
        $message = mb_substr(
            $this->replacePlaceholders($contact->uuid, $fieldsArray['body'] ?? ''),
            0,
            $type === 'text' ? 4096 : 1024
        );

        // التذييل الفارغ يُحذف لا يُرسَل «""»: Meta ترفض footer.text الفارغ، فكانت
        // كل رسالة أزرار أو قائمة بلا تذييل تفشل ويعلق العميل قبلها.
        $footer = trim((string) ($fieldsArray['footer'] ?? ''));
        $footer = $footer !== ''
            ? mb_substr($this->replacePlaceholders($contact->uuid, $footer), 0, 60)
            : null;

        // Initialize the header array if needed for interactive messages
        $header = $this->prepareHeader($fieldsArray ?? [], $contact->uuid);
        $buttonArray = [];
        $buttonType = null;
        $buttonLabel = null;

        if($type == 'text'){
            $buttonType = 'text';
        } elseif ($type === 'interactive buttons') {
            $buttonType = ($fieldsArray['buttonType'] ?? '') === 'buttons'
                ? 'interactive buttons'
                : 'interactive call to action url';
            $buttonArray = $this->prepareButtonArray($fieldsArray ?? [], $buttonType);

            if ($buttonType === 'interactive call to action url' && !empty($buttonArray['url'])) {
                $buttonArray['url'] = $this->replacePlaceholders($contact->uuid, $buttonArray['url']);
            }

            if ($buttonType === 'interactive buttons' && $buttonArray === []) {
                Log::error('Flow interactive buttons node has no valid buttons', [
                    'flow_id' => $flowData->flow_id,
                    'contact_id' => $contactId,
                    'node_id' => $metadataArray['id'] ?? null,
                ]);

                return false;
            }

            if ($buttonType === 'interactive call to action url'
                && (empty($buttonArray['url']) || empty($buttonArray['display_text']))) {
                Log::error('Flow interactive CTA node is missing button text or URL', [
                    'flow_id' => $flowData->flow_id,
                    'contact_id' => $contactId,
                    'node_id' => $metadataArray['id'] ?? null,
                ]);

                return false;
            }
    
        } elseif ($type === 'interactive list') {
            $buttonType = 'interactive list';
            $buttonArray = $this->prepareButtonArray($fieldsArray ?? [], $buttonType);
            $buttonLabel = $fieldsArray['buttonLabel'] ?? '';
            // WhatsApp: list open-button text max 20 characters
            if ($buttonLabel !== '') {
                $buttonLabel = mb_substr($buttonLabel, 0, 20);
            }
        }

        if (in_array($type, ['interactive buttons', 'interactive list'], true) && trim($message) === '') {
            Log::error('Flow interactive node is missing body text', [
                'flow_id' => $flowData->flow_id,
                'contact_id' => $contactId,
                'node_id' => $metadataArray['id'] ?? null,
                'node_type' => $type,
            ]);

            return false;
        }

        // قبل الإرسال لا داخله: نصٌّ فارغ لا تُصلحه إعادة المحاولة.
        if ($type === 'text' && trim($message) === '') {
            Log::error('Flow text node is missing body text', [
                'flow_id' => $flowData->flow_id,
                'contact_id' => $contactId,
                'node_id' => $metadataArray['id'] ?? null,
            ]);

            return false;
        }

        $processedNodeId = $metadataArray['id'] ?? null;

        // الإرسال مُغلَّف بإعادة المحاولة: 131000 «Something went wrong» وأخواتها
        // أعطال مؤقتة عند Meta توصي هي نفسها بإعادتها. كانت المحاولة الأولى
        // الأخيرة، فعميلٌ جديد لا يصله الترحيب أبداً ولا يعلم أحد.
        [$response, $attempts] = $this->sendWithRetry(function () use ($sender, $type, $contact, $message, $buttonType, $buttonArray, $header, $footer, $buttonLabel, $fieldsArray) {
            $response = null;

            switch ($type) {
                case 'text':
                    $response = $sender->sendMessage($contact->uuid, $message, 0, $buttonType);
                    break;
    
                case 'media':
                    $mediaInfo = json_decode(($fieldsArray['media']['metadata'] ?? '{}'), true);
                    $mediaLocation = $fieldsArray['media']['location'] ?? '';
                    $mediaLocation = ($mediaLocation === 'aws') ? 'amazon' : $mediaLocation;

                    $response = $sender->sendMedia(
                        $contact->uuid,
                        $fieldsArray['mediaType'] ?? '',
                        $mediaInfo['name'] ?? '',
                        $fieldsArray['media']['path'] ?? '',
                        $fieldsArray['media']['path'] ?? '',
                        $mediaLocation,
                        // المحرّر يعرض «إضافة متغيّر» للتعليق، فكانت الرموز تصل حرفياً.
                        mb_substr($this->replacePlaceholders($contact->uuid, $fieldsArray['caption'] ?? ''), 0, 1024)
                    );
                    break;
    
                case 'interactive buttons':
                case 'interactive list':
                    $response = $sender->sendMessage(
                        $contact->uuid,
                        $message,
                        0,
                        $buttonType,
                        $buttonArray,
                        $header,
                        $footer,
                        $buttonLabel
                    );
                    break;
            }

            return $response;
        }, [
            'flow_id' => $flowData->flow_id,
            'contact_id' => $contactId,
            'node_id' => $processedNodeId,
            'node_type' => $type,
        ]);

        $sendSuccess = $response && isset($response->success) && $response->success === true;

        if (!$sendSuccess) {
            $errorMsg = $response->message ?? $response->data->error->message ?? 'unknown';
            Log::error("Flow message failed to send", [
                'flow_id' => $flowData->flow_id,
                'contact_id' => $contactId,
                'node_id' => $processedNodeId,
                'node_type' => $type,
                'button_type' => $buttonType,
                'button_count' => is_array($buttonArray) ? count($buttonArray) : 0,
                'error' => $errorMsg,
                'error_code' => $response->data->error->code ?? null,
                'error_subcode' => $response->data->error->error_subcode ?? null,
                'fbtrace_id' => $response->data->error->fbtrace_id ?? null,
                'attempts' => $attempts,
            ]);
            return false;
        }

        $this->proceedToNextStep($flowData, $contactId, $processedNodeId);

        if (isset($response->data->chat->id)) {
            FlowLog::create([
                'flow_id' => $flowData->flow_id,
                'chat_id' => $response->data->chat->id
            ]);
        }

        return true;
    }

    /** واتساب: الخدمة التي بُنيت مع المنفّذ كما كانت. وغيرها: ناقل القنوات. */
    private function senderFor($contact): \App\Services\Messaging\Contracts\AutomationSender
    {
        return AutomationTransport::for($contact, fn () => $this->whatsappService);
    }

    /**
     * أكواد Meta المؤقتة التي تُعاد معها المحاولة.
     *
     * 131000 «Something went wrong»، و1 و2 عطل في الواجهة نفسها، و131016
     * خدمة مُثقلة، و130429 تجاوز معدّل الإرسال. ما سواها خطأ في الطلب أو في
     * المستلم (131026 رقم ليس على واتساب، 100 معامل خاطئ) لا تُصلحه الإعادة.
     */
    private const TRANSIENT_SEND_ERRORS = [131000, 1, 2, 131016, 130429];

    /**
     * يرسل ويعيد المحاولة على الأعطال المؤقتة وحدها.
     *
     * الإعادة آمنة: حين ترفض Meta الطلب لا يُنشأ صفّ محادثة ولا تصل رسالة،
     * فلا تكرار. ولا إعادة مع خطأ الشبكة (بلا كود): قد تكون الرسالة وصلت
     * وضاع الردّ وحده، فإعادتها تُكرّرها عند العميل.
     *
     * @param  callable(): mixed  $send
     * @return array{0: mixed, 1: int} الردّ الأخير وعدد المحاولات
     */
    private function sendWithRetry(callable $send, array $logContext): array
    {
        $delays = (array) config('flow_builder.send_retry_delays', [2, 5]);
        $attempt = 0;

        while (true) {
            $attempt++;
            $response = $send();

            if ($response && isset($response->success) && $response->success === true) {
                return [$response, $attempt];
            }

            $code = isset($response->data->error->code) ? (int) $response->data->error->code : null;

            if ($code === null || !in_array($code, self::TRANSIENT_SEND_ERRORS, true) || $attempt > count($delays)) {
                return [$response, $attempt];
            }

            $delay = max(0, (int) $delays[$attempt - 1]);

            Log::warning('Flow message send retry', $logContext + [
                'attempt' => $attempt,
                'error_code' => $code,
                'retry_in_seconds' => $delay,
            ]);

            if ($delay > 0) {
                sleep($delay);
            }
        }
    }

    /**
     * Process action nodes in the flow
     */
    private function processActionNode($metadataArray, $contact, $message, $flowData, $contactId)
    {
				//   logger('inside action node');
        $actionType = \Arr::get($metadataArray, "data.actionType", null);
        $config = \Arr::get($metadataArray, "data.config", []);
        $isActive = \Arr::get($metadataArray, "data.is_active", true);

        if (!$actionType || !$isActive) {
            $this->proceedToNextStep($flowData, $contactId, $metadataArray['id'] ?? null);
            return true;
        }

        // Normalize action type: convert hyphens to underscores
        $actionType = str_replace('-', '_', $actionType);

        // العميل يقف على عقدة التأخير نفسها أثناء الانتظار، فيُكمَل من حافّتها
        // هي. كان يقف على ما قبلها، فيأخذ التأخير أوّل حافّة خارجة منها — وبعد
        // أزرارٍ هي غالباً فرعُ زرٍّ آخر.
        if ($actionType === 'delay' && isset($metadataArray['id'])) {
            FlowUserData::where('contact_id', $contactId)->update(['current_step' => $metadataArray['id'], 'unmatched_replies' => 0]);
            $flowData = FlowUserData::where('contact_id', $contactId)->first() ?? $flowData;
        }

        // Initialize action execution service
        $actionService = new ActionExecutionService($this->organizationId);

        // Execute the action
        $result = $actionService->executeAction($actionType, $config, $contact, $message, $flowData, $contactId);

		if ($result === 'delayed') {
   			//  logger('Delay action executed - flow paused');
  		  return 'delayed';
			}

        // Handle conditional actions specially
        if ($actionType === 'conditional') {
					//  logger('inside conditional');
            // Set current_step to this conditional node's ID before processing
            $conditionalNodeId = $metadataArray['id'] ?? null;
            if ($conditionalNodeId && $flowData->current_step != $conditionalNodeId) {
                FlowUserData::where('contact_id', $contactId)->update(['current_step' => $conditionalNodeId]);
                $flowData = FlowUserData::where('contact_id', $contactId)->first();
            }
            return $this->handleConditionalAction($result, $flowData, $contactId, $metadataArray);
        }

        // For update_contact action, check if it failed due to validation BEFORE checking general failure
        if ($actionType === 'update_contact' && $result === false) {
		//	logger('inside update contact');
            // Check if there's an invalid email message configured, which indicates validation failure
            $invalidMessage = $config['invalid_email_message'] ?? '';
            //Log::info("Update contact validation failed, staying on same node for contact {$contactId}");
            return 'validation_failed'; // Special return value to indicate validation failure
        }

        if ($result === false) {
            // Action failed, stop the flow
            Log::warning("DELETION POINT 4: Action failed, deleting FlowUserData for contact {$contactId}");
            FlowUserData::where('contact_id', $contactId)->delete();
            return false;
        }
        $this->proceedToNextStep($flowData, $contactId, $metadataArray['id'] ?? null);
        return true;
    }

    /**
     * Handle conditional action branching
     */
    private function handleConditionalAction($conditionResult, $flowData, $contactId, $metadataArray)
    {
        $flow = Flow::find($flowData->flow_id);
        if (!$flow || empty($flow->metadata)) {
            return false;
        }

        $edgesArray = json_decode($flow->metadata, true);
        $edges = \Arr::get($edgesArray, "edges", null);
        $currentStep = $flowData->current_step;
		// logger('--from handle conditional action'.$currentStep);
        // Build the sourceHandle for the condition or default
        if ($conditionResult !== 'default') {
            $sourceHandle = 'condition-' . $conditionResult . '|' . $currentStep;
        } else {
            $sourceHandle = 'default|' . $currentStep;
        }

        // \Log::info("Looking for edge with source: {$currentStep} and sourceHandle: {$sourceHandle}");

        foreach ($edges as $edge) {
            if (
                isset($edge['source']) && (string)$edge['source'] === (string)$currentStep &&
                isset($edge['sourceHandle']) && $edge['sourceHandle'] === $sourceHandle
            ) {
                $targetNode = $edge['targetNode']['id'] ?? null;
                if ($targetNode) {
                    // \Log::info("Found matching edge, routing to node: " . $targetNode);
                    // Reload flowData
                    $flowData = FlowUserData::where('contact_id', $contactId)->first();
                    // Get the chat object (you may need to pass it in, or reconstruct it)
                    $chat = (object) [
                        'contact_id' => $contactId,
                        'organization_id' => $this->organizationId
                    ];
                    // Process the next node
                    $this->processFlow($chat, false, $flowData, $contactId, '');
                    return true;
                }
            }
        }

        // No matching path found, end the flow
        \Log::warning("DELETION POINT 5: No matching conditional path found, deleting FlowUserData for contact {$contactId}");
        FlowUserData::where('contact_id', $contactId)->delete();
        return false;
    }

    /**
     * Proceed to the next step in the flow.
     * $processedNodeId: the ID of the node that was just processed/sent.
     * Sets current_step to that node so findEdgesBySource can find the correct next node.
     */
    private function proceedToNextStep($flowData, $contactId, $processedNodeId = null)
    {
        // كل تقدّم يصفّر عدّاد الردود غير المطابقة: الإعادة مرّةً لكل سؤال.
        if ($processedNodeId !== null) {
            FlowUserData::where('contact_id', $contactId)->update(['current_step' => $processedNodeId, 'unmatched_replies' => 0]);
            return;
        }

        $flow = Flow::find($flowData->flow_id);
        if (!$flow || empty($flow->metadata)) {
            Log::warning("DELETION POINT 8: Flow not found or empty metadata for flow {$flowData->flow_id}");
            return;
        }

        $edgesArray = json_decode($flow->metadata, true);
        $edges = \Arr::get($edgesArray, "edges", null);

        $nextNodes = [];
        foreach ($edges as $edge) {
            if (isset($edge['source']) && (string) $edge['source'] === (string) $flowData->current_step) {
                if (isset($edge['targetNode']['id'])) {
                    $nextNodes[] = $edge['targetNode']['id'];
                }
            }
        }

        if (!empty($nextNodes)) {
            $nextStep = $nextNodes[0];
            FlowUserData::where('contact_id', $contactId)->update(['current_step' => $nextStep, 'unmatched_replies' => 0]);
        } else {
            Log::warning("DELETION POINT 6: No next step found for current_step {$flowData->current_step}, ending flow");
            FlowUserData::where('contact_id', $contactId)->delete();
        }
    }

    private function prepareHeader(array $fieldsArray, ?string $contactUuid = null): array
    {
        $header = [];

        if (($fieldsArray['headerType'] ?? '') === 'text') {
            $headerText = trim((string) ($fieldsArray['headerText'] ?? ''));
            if ($contactUuid !== null) {
                $headerText = $this->replacePlaceholders($contactUuid, $headerText);
            }
            $headerText = mb_substr($headerText, 0, 60);
            if ($headerText === '') {
                return [];
            }

            $header = [
                'type' => 'text',
                'text' => clean($headerText),
            ];
        } elseif (($fieldsArray['headerType'] ?? '') !== 'none') {
            $headerType = (string) ($fieldsArray['headerType'] ?? '');
            $headerMedia = $fieldsArray['headerMedia'] ?? [];
            if (is_object($headerMedia)) {
                $headerMedia = (array) $headerMedia;
            }

            $mediaLink = $this->normalizeMediaUrl((string) ($headerMedia['path'] ?? ''));
            if ($mediaLink === '') {
                return [];
            }

            $header['type'] = $headerType;
            $header[$headerType] = [
                'link' => $mediaLink,
            ];
        }

        return $header;
    }

    private function normalizeMediaUrl(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return rtrim((string) config('app.url'), '/') . '/' . ltrim($path, '/');
    }

    private function prepareButtonArray(array $fieldsArray, string $type): array
    {
        $buttonArray = [];

        if ($type === 'interactive buttons') {
            // WhatsApp Cloud API: max 3 reply buttons; title max 20 chars.
            $maxButtons = 3;
            $maxTitleLen = 20;

            foreach ($fieldsArray['buttons'] ?? [] as $id => $title) {
                if (count($buttonArray) >= $maxButtons) {
                    break;
                }

                $title = mb_substr(trim((string) $title), 0, $maxTitleLen);
                if ($title === '') {
                    continue;
                }

                $buttonArray[] = [
                    'id' => (string) $id,
                    'title' => $title,
                ];
            }
        } elseif ($type === 'interactive call to action url') {
            $displayText = $fieldsArray['ctaUrlButton']['displayText'] ?? '';
            if (mb_strlen($displayText) > 20) {
                $displayText = mb_substr($displayText, 0, 20);
            }
            $buttonArray = [
                'display_text' => $displayText,
                'url' => $fieldsArray['ctaUrlButton']['url'] ?? '',
            ];
        } elseif ($type === 'interactive list') {
            // WhatsApp Cloud API: max 10 rows total across all sections; row id must be unique;
            // row title max 24, description max 72, section title max 24 (chars).
            $maxRowsTotal = 10;
            $maxRowTitleLen = 24;
            $maxRowDescLen = 72;
            $maxSectionTitleLen = 24;
            $totalRows = 0;
            $seenIds = [];
            $buttonArray = [];
            $originalRowCount = 0;

            foreach ($fieldsArray['sections'] ?? [] as $sectionIndex => $section) {
                $originalRowCount += count($section['rows'] ?? []);
                if ($totalRows >= $maxRowsTotal) {
                    break;
                }
                $sectionTitle = mb_substr(trim($section['title'] ?? ''), 0, $maxSectionTitleLen);
                $rowsOut = [];

                foreach ($section['rows'] ?? [] as $rowIndex => $row) {
                    if ($totalRows >= $maxRowsTotal) {
                        break;
                    }
                    $title = mb_substr(trim($row['title'] ?? ''), 0, $maxRowTitleLen);
                    if ($title === '') {
                        continue;
                    }

                    $baseId = preg_replace('/[^a-zA-Z0-9_-]/', '_', (string) ($row['id'] ?? $rowIndex));
                    $uniqueId = 's'.$sectionIndex.'_r'.$rowIndex.'_'.$baseId;
                    if ($uniqueId === 's'.$sectionIndex.'_r'.$rowIndex.'_') {
                        $uniqueId = 's'.$sectionIndex.'_r'.$rowIndex;
                    }
                    if (strlen($uniqueId) > 200) {
                        $uniqueId = substr($uniqueId, 0, 200);
                    }
                    $suffix = 0;
                    while (isset($seenIds[$uniqueId])) {
                        $suffix++;
                        $uniqueId = substr($uniqueId, 0, 180).'_'.$suffix;
                    }
                    $seenIds[$uniqueId] = true;

                    $rowPayload = [
                        'id' => $uniqueId,
                        'title' => $title,
                    ];
                    $desc = trim($row['description'] ?? '');
                    if ($desc !== '') {
                        $rowPayload['description'] = mb_substr($desc, 0, $maxRowDescLen);
                    }
                    $rowsOut[] = $rowPayload;
                    $totalRows++;
                }

                if ($rowsOut !== []) {
                    $buttonArray[] = [
                        'title' => $sectionTitle,
                        'rows' => $rowsOut,
                    ];
                }
            }

            if ($originalRowCount > $maxRowsTotal) {
                Log::warning('Flow interactive list truncated for WhatsApp API limit (10 rows max)', [
                    'original_rows' => $originalRowCount,
                    'sent_rows' => $totalRows,
                ]);
            }
        }

        return $buttonArray;
    }

    /**
     * Backward BFS from the current step to find the closest previous interactive node
     * whose button/list option matches the given message.
     * Returns the node ID of that step so the flow can be re-set to it,
     * allowing the user to navigate back by re-typing a previous keyword.
     */
    private function findPreviousMatchingStep(array $edges, $currentStep, string $message): ?string
    {
        // الرسالة الفارغة (بصمة صوتية، صورة) كانت تساوي خانة زرّ فارغة فتُرجع
        // العميل إلى قائمة قديمة.
        if (trim($message) === '') {
            return null;
        }

        $visited = [(string) $currentStep];
        $queue   = [(string) $currentStep];

        while (!empty($queue)) {
            $nodeId = array_shift($queue);

            foreach ($edges as $edge) {
                $targetId = (string) ($edge['targetNode']['id'] ?? $edge['target'] ?? '');
                $sourceId = (string) ($edge['source'] ?? '');

                if ($targetId !== $nodeId || in_array($sourceId, $visited)) {
                    continue;
                }

                $visited[] = $sourceId;
                $queue[]   = $sourceId;

                // قائمة سابقة يختار فيها هذا الردّ خياراً موصولاً.
                $fields = $edge['sourceNode']['data']['metadata']['fields'] ?? [];

                if ($this->choiceHandle($fields, $message) !== false
                    && $this->findEdgesBySource($edges, $sourceId, $message) !== []) {
                    return $sourceId;
                }
            }
        }

        return null;
    }

    private function findEdgesBySource($edges, $sourceId, $message)
    {
        $sourceId = (string) $sourceId;

        $matchingEdges = array_values(array_filter(
            $edges ?? [],
            fn ($edge) => isset($edge['source']) && (string) $edge['source'] === $sourceId
        ));

        if ($matchingEdges === []) {
            return [];
        }

        $fields = $matchingEdges[0]['sourceNode']['data']['metadata']['fields'] ?? [];
        $handle = $this->choiceHandle($fields, (string) $message);

        // عقدة ليست خيارات (نصّ، وسائط، رابط، إجراء، بداية): تمضي إلى ما بعدها
        // أيّاً كان الردّ.
        if ($handle === false) {
            return $matchingEdges[0]['targetNode'] ?? [];
        }

        // الأزرار والقوائم لا بدّ فيها من مطابقة، حتى لو وُصل خيارٌ واحد فقط.
        // الاختصار القديم (حافة واحدة = امضِ) كان يجعل ذلك الخيار جواباً لكل
        // ردّ: «إلغاء» أو أيّ نصّ حرّ يقود إلى فرع «القائمة الرئيسية».
        if ($handle === null) {
            return [];
        }

        foreach ($matchingEdges as $edge) {
            if (($edge['sourceHandle'] ?? null) === $handle) {
                return $edge['targetNode'] ?? [];
            }
        }

        return [];
    }

    /**
     * مقبض الخيار الذي يطابقه الردّ في عقدة أزرار أو قائمة.
     *
     * @return string|null|false المقبض، أو null إن لم يطابق شيئاً، أو false إن
     *                           لم تكن العقدة خيارات أصلاً.
     *
     * يُقارَن بالعنوان كما أُرسل (مقصوصاً إلى حدّ واتساب)، فواتساب يُرجع العنوان
     * المقصوص. وأوّل مطابقة تفوز: الأخيرة كانت تفوز فيصير الفرع الأوّل لعنوانين
     * متكرّرين غير قابل للوصول.
     */
    private function choiceHandle(array $fields, string $message)
    {
        $type = $fields['type'] ?? null;
        $isReplyButtons = $type === 'interactive buttons' && ($fields['buttonType'] ?? '') === 'buttons';

        if (!$isReplyButtons && $type !== 'interactive list') {
            return false;
        }

        $reply = mb_strtolower(trim($message));

        if ($reply === '') {
            return null;
        }

        if ($isReplyButtons) {
            $mapping = ['button1' => 'a', 'button2' => 'b', 'button3' => 'c'];

            foreach ($fields['buttons'] ?? [] as $key => $title) {
                $title = mb_strtolower(mb_substr(trim((string) $title), 0, 20));

                if ($title !== '' && $title === $reply && isset($mapping[$key])) {
                    return $mapping[$key];
                }
            }

            return null;
        }

        foreach ($fields['sections'] ?? [] as $sectionIndex => $section) {
            foreach ($section['rows'] ?? [] as $rowIndex => $row) {
                $title = mb_strtolower(mb_substr(trim((string) ($row['title'] ?? '')), 0, 24));

                if ($title !== '' && $title === $reply) {
                    return 'a' . $sectionIndex . $rowIndex;
                }
            }
        }

        return null;
    }

    /**
     * عقدة المسار بمعرّفها: من مصفوفة العقد، وإلا من نسخها داخل الحواف.
     */
    private function findNodeById(array $metadata, $nodeId): ?array
    {
        $nodeId = (string) $nodeId;

        foreach ($metadata['nodes'] ?? [] as $node) {
            if ((string) ($node['id'] ?? '') === $nodeId) {
                return $node;
            }
        }

        foreach ($metadata['edges'] ?? [] as $edge) {
            foreach (['sourceNode', 'targetNode'] as $side) {
                if ((string) ($edge[$side]['id'] ?? '') === $nodeId) {
                    return $edge[$side];
                }
            }
        }

        return null;
    }

    private function isDelayNode(?array $node): bool
    {
        return ($node['type'] ?? null) === 'action'
            && str_replace('-', '_', (string) ($node['data']['actionType'] ?? '')) === 'delay'
            && ($node['data']['is_active'] ?? true) !== false;
    }

    /**
     * Search for an active keyword-triggered flow whose keywords match the given message.
     * Returns the flow row (stdClass) or null if none found.
     *
     * المطابقة في PHP لا بـ FIND_IN_SET: انظر KeywordMatcher. والأقدم يفوز
     * حين تتكرّر الكلمة في أكثر من flow نشط.
     */
    private function findFlowByKeyword(string $msg, int $organizationId): ?object
    {
        $flows = \DB::table('flows')
            ->where('trigger', 'keywords')
            ->where('organization_id', $organizationId)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get(['id', 'keywords']);

        foreach ($flows as $flow) {
            if (KeywordMatcher::matches($msg, $flow->keywords)) {
                return $flow;
            }
        }

        return null;
    }

    /**
     * المتغيّرات بقواعد الردود المحفوظة نفسها (ContactPlaceholderService):
     * أسماء عربية، وقيمة فارغة لا تُبقي الرمز، ومصفوفات لا تُسقط المهمة.
     * {phone_number} هو ما تعرضه قائمة متغيّرات المحرّر، والخدمة تعرفه {phone}.
     */
    private function replacePlaceholders($contactUuid, $message)
    {
        $message = str_replace('{phone_number}', '{phone}', (string) $message);

        return ContactPlaceholderService::replace((int) $this->organizationId, (string) $contactUuid, $message);
    }
}
