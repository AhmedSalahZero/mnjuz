<?php

namespace App\Services;

use App\Http\Resources\TemplateResource;
use App\Models\Organization;
use App\Models\Template;
use App\Services\Carousel\CarouselDefinition;
use App\Services\Carousel\CarouselTemplateCreator;
use App\Services\WhatsappService;
use Illuminate\Http\Request;

use Inertia\Inertia;
use Validator;

class TemplateService
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

    public function getTemplates(Request $request, $uuid = null, $searchTerm = null)
    {
        $response = [];

        if ($uuid === null) {
            $response = $this->getTemplatesListResponse($request);
        } elseif ($uuid === 'sync') {
            $response = $this->syncFromMeta();
        } else {
            $response = $this->getTemplateDetailResponse($request, $uuid);
        }

        return $response;
    }

    /**
     * زرّ «مزامنة» في صفحة القوالب.
     *
     * يمرّ على TemplateSyncService لا على WhatsappService::syncTemplates.
     * كانت نسختان من المزامنة، فأصلحنا واحدة وبقيت الأخرى تنهار: قالب
     * اسمه رابط خرائط جوجل (٢٦٨ حرفاً) تجاوز عمود name، ورمى 1406 —
     * و`catch` القديمة تلتقط أخطاء الشبكة وحدها، فوصل الخطأ صفحةً بيضاء
     * للمستخدم في الإنتاج.
     *
     * والخدمة الجديدة تحصر الفشل عند القالب الواحد وتُبلّغ بما تُخطّي،
     * ولا تعتمد على الجلسة. والواجهة تتجاهل جسم الردّ وتُعيد التحميل،
     * فتوحيد المسارين لا يكسر شيئاً.
     */
    private function syncFromMeta()
    {
        $result = app(\App\Services\TemplateSyncService::class)
            ->refreshOrganization((int) $this->organizationId);

        // نجاح جزئي نجاح: ما زُومن يظهر بعد إعادة التحميل، والمتخطّى
        // مذكور في العدّاد وفي السجلّ.
        return response()->json([
            'success' => $result['error'] === null,
            'message' => $result['error'] ?? __('Templates synced.'),
            'updated' => $result['updated'],
            'created' => $result['created'],
            'skipped' => $result['failed'],
        ], $result['error'] === null ? 200 : 422);
    }

    private function getTemplatesListResponse(Request $request)
    {
        if ($request->expectsJson()) {
            $rows = Template::where('organization_id', $this->organizationId)->where('deleted_at', null)
                ->get()
                ->map(function ($row) {
                    return [
                        'value' => $row->id,
                        'label' => $row->name,
                    ];
                });

            return response()->json([$rows]);
        }

        return Inertia::render('User/Templates/Index', [
            'title' => __('templates'),
            'allowCreate' => true,
            'rows' => TemplateResource::collection(
                Template::where('organization_id', $this->organizationId)->where('deleted_at', null)->latest()->paginate(10)
            ),
        ]);
    }

    private function getTemplateDetailResponse(Request $request, $uuid)
    {
        if ($request->expectsJson()) {
            $row = Template::where('uuid', $uuid)->where('deleted_at', null)->first();
            return response()->json($row);
        }

        $data['languages'] = config('languages');
        $data['template'] = Template::where('uuid', $uuid)->first();

        // محرّر القوالب لا يعرف البطاقات، وحفظه كان سيرسل القالب إلى Meta
        // بلا مكوّن CAROUSEL.
        if ($data['template'] && CarouselDefinition::isCarousel($data['template']->metadata)) {
            return redirect('/templates')->with('status', [
                'type' => 'error',
                'message' => __('Carousel templates cannot be edited. Create a new template instead.'),
            ]);
        }

        $data['title'] = 'Edit Template';
        return Inertia::render('User/Templates/Edit', $data);
    }

    public function createTemplate(Request $request)
    {
        if ($request->isMethod('get')){
            $data['languages'] = config('languages');
            $data['settings'] = Organization::where('id', $this->organizationId)->first();
            
            return Inertia::render('User/Templates/Add', $data);
        } else if ($request->isMethod('post')){
            if ($response = $this->abortIfDemo('create')) {
                return $response;
            }

            $validator = Validator::make($request->all(),[
                'name' => 'required',
                'category' => 'required',
                'language' => 'required',
            ]);

            if ($validator->fails()) {
                return response()->json(['success' => false,'message'=>'Some required fields have not been filled','errors'=>$validator->messages()->get('*')]);
            }

            if ($request->input('template_type') === 'carousel') {
                return (new CarouselTemplateCreator($this->whatsappService, (int) $this->organizationId))->create($request);
            }

            return $this->whatsappService->createTemplate($request);
        }
    }

    public function updateTemplate(Request $request, $uuid)
    {
        if ($response = $this->abortIfDemo('update')) {
            return $response;
        }

        $existing = Template::where('uuid', $uuid)->where('organization_id', $this->organizationId)->first();
        if ($existing && CarouselDefinition::isCarousel($existing->metadata)) {
            return response()->json([
                'success' => false,
                'message' => __('Carousel templates cannot be edited. Create a new template instead.'),
            ]);
        }

        $validator = Validator::make($request->all(),[
            'name' => 'required',
            'category' => 'required',
            'language' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false,'message'=>'Some required fields have not been filled','errors'=>$validator->messages()->get('*')]);
        }

        return $this->whatsappService->updateTemplate($request, $uuid);
    }

    public function deleteTemplate($uuid)
    {
        if ($response = $this->abortIfDemo('delete')) {
            return $response;
        }

        $query = $this->whatsappService->deleteTemplate($uuid);

        if($query->success === true){
            return response()->json([
                'success' => true,
                'message'=> __('Template deleted successfully')
            ]);
        } else {
            return response()->json([
                'success' => false,
                'message'=> $query->message ?? __('something went wrong. Refresh the page and try again')
            ]);
        }
    }

    protected function abortIfDemo($type)
    {
        if (app()->environment('demo') && $this->organizationId == 1) {
            if($type == 'delete'){
                return response()->json([
                    'success' => false,
                    'message' => 'You are not allowed to do this in this demo account. To test template features please create your own account.'
                ]);
            } else {
                $responseObject = new \stdClass();
                $responseObject->success = false;
                $responseObject->data = new \stdClass();
                $responseObject->data->error = new \stdClass();
                $responseObject->data->error->message = 'You are not allowed to do this in this demo account. To test template features please create your own account.';

                return response()->json($responseObject);
            }
        }

        return null;
    }
}
