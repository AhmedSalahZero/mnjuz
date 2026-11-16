<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\MobileApi;
use App\Http\Controllers\Controller;
use App\Models\ChannelAccount;
use App\Models\WebChatFaq;
use App\Services\ActivityLogger;
use App\Services\WebChat\WebChatFaqEditor;
use App\Services\WebChat\WebChatFaqs;
use App\Services\WebChat\WebChatQuestions;
use App\Services\WebChat\WebChatSettings;
use App\Services\WebChat\WebChatSettingsForm;
use App\Services\WebChat\WebChatWidgetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * إعدادات المحادثة المباشرة من التطبيق: الـ widget وشكله ونموذج الزائر
 * والمواقع المسموحة والشعار والمفتاح والأسئلة الشائعة.
 *
 * محروسة كالويب (AuthorizesWebChatSettings): الإضافة «Web Chat» في الباقة،
 * والمالك والمدير وحدهما. القواعد والحفظ في WebChatSettingsForm و
 * WebChatFaqEditor — نفس ما يستعمله الويب.
 *
 * المعاينة الحيّة وحدها ليست هنا: صفحة ويب بجلسة تُعرض في iframe.
 */
class MobileWebChatController extends Controller
{
    use MobileApi;

    public function __construct(
        private readonly WebChatSettingsForm $form,
        private readonly WebChatFaqEditor $faqs,
        private readonly WebChatWidgetService $widgets,
    ) {
    }

    /** الإعدادات كما تعرضها صفحة الويب، ومعها القيم المسموحة لكل اختيار. */
    public function show(Request $request): JsonResponse
    {
        if ($refusal = $this->refusal($request)) {
            return $refusal;
        }

        return $this->ok($this->view($this->widget($request)));
    }

    /** حفظ النموذج كاملاً، كزرّ «حفظ» في الويب. */
    public function update(Request $request): JsonResponse
    {
        if ($refusal = $this->refusal($request)) {
            return $refusal;
        }

        $account = $this->widget($request);
        $validator = $this->form->validator($request->all(), $account);

        if ($validator->fails()) {
            return $this->invalid($validator->errors()->toArray());
        }

        $this->form->apply($account, $validator->validated());
        $this->logChange($request);

        return $this->ok($this->view($account->refresh()), __('Settings updated successfully'));
    }

    public function uploadLogo(Request $request): JsonResponse
    {
        if ($refusal = $this->refusal($request)) {
            return $refusal;
        }

        $validator = Validator::make($request->all(), $this->form->logoRules());

        if ($validator->fails()) {
            return $this->invalid($validator->errors()->toArray());
        }

        $account = $this->widget($request);
        $this->form->storeLogo($account, $request->file('logo'));
        $this->logChange($request);

        return $this->ok($this->view($account->refresh()), __('Settings updated successfully'));
    }

    public function removeLogo(Request $request): JsonResponse
    {
        if ($refusal = $this->refusal($request)) {
            return $refusal;
        }

        $account = $this->widget($request);
        $this->form->removeLogo($account);
        $this->logChange($request);

        return $this->ok($this->view($account->refresh()), __('Settings updated successfully'));
    }

    /** مفتاح جديد: الكود القديم في موقع التاجر يتوقّف فوراً. التطبيق يطلب التأكيد. */
    public function rotateKey(Request $request): JsonResponse
    {
        if ($refusal = $this->refusal($request)) {
            return $refusal;
        }

        $account = $this->widget($request);
        $this->widgets->rotateKey($account);
        $this->logChange($request);

        return $this->ok(
            $this->view($account->refresh()),
            __('A new key was generated. Replace the code on your website.')
        );
    }

    /* ───────────────────────── الأسئلة الشائعة ───────────────────────── */

    public function faqStore(Request $request): JsonResponse
    {
        if ($refusal = $this->refusal($request)) {
            return $refusal;
        }

        $organizationId = $this->organizationId($request);

        if (($data = $this->faqInput($request, null, $this->faqs->isFull($organizationId))) instanceof JsonResponse) {
            return $data;
        }

        $faq = $this->faqs->create($organizationId, $data, $request->file('image'), (int) $request->user()->id);
        $this->logChange($request);

        return $this->ok($this->faqView($organizationId, $faq), __('Settings updated successfully'));
    }

    public function faqUpdate(Request $request, int $id): JsonResponse
    {
        if ($refusal = $this->refusal($request)) {
            return $refusal;
        }

        $organizationId = $this->organizationId($request);
        $faq = WebChatFaq::where('organization_id', $organizationId)->find($id);

        if (!$faq) {
            return $this->fail(404, __('Data not found'));
        }

        if (($data = $this->faqInput($request, $faq)) instanceof JsonResponse) {
            return $data;
        }

        $this->faqs->update($faq, $data, $request->file('image'), $request->boolean('remove_image'));
        $this->logChange($request);

        return $this->ok($this->faqView($organizationId, $faq), __('Settings updated successfully'));
    }

    public function faqDestroy(Request $request, int $id): JsonResponse
    {
        if ($refusal = $this->refusal($request)) {
            return $refusal;
        }

        $organizationId = $this->organizationId($request);
        $faq = WebChatFaq::where('organization_id', $organizationId)->find($id);

        if (!$faq) {
            return $this->fail(404, __('Data not found'));
        }

        $this->faqs->destroy($faq);
        $this->logChange($request);

        return $this->ok(['faqs' => WebChatFaqs::forDashboard($organizationId)], __('Settings updated successfully'));
    }

    /** الترتيب الجديد: معرّفات الأسئلة بالترتيب. معرّف من منشأة أخرى لا يُمسّ. */
    public function faqReorder(Request $request): JsonResponse
    {
        if ($refusal = $this->refusal($request)) {
            return $refusal;
        }

        $validator = Validator::make($request->all(), $this->faqs->reorderRules());

        if ($validator->fails()) {
            return $this->invalid($validator->errors()->toArray());
        }

        $organizationId = $this->organizationId($request);
        $this->faqs->reorder($organizationId, $validator->validated()['ids']);
        $this->logChange($request);

        return $this->ok(['faqs' => WebChatFaqs::forDashboard($organizationId)], __('Settings updated successfully'));
    }

    /* ───────────────────────── مساعدات ───────────────────────── */

    /** الصلاحية ثم الباقة — كترتيب AuthorizesWebChatSettings في الويب. */
    private function refusal(Request $request): ?JsonResponse
    {
        if (!$this->isPrivileged($request)) {
            return $this->forbidden();
        }

        if (!WebChatWidgetService::moduleEnabled($this->organizationId($request))) {
            return $this->fail(403, __('This feature is not available in your plan.'));
        }

        return null;
    }

    private function widget(Request $request): ChannelAccount
    {
        return $this->form->widget($this->organizationId($request), (int) $request->user()->id);
    }

    /**
     * ما تعرضه صفحة الويب، بلا رابط المعاينة (صفحة بجلسة ويب)، ومعه القيم
     * المسموحة: التطبيق يرسم الاختيارات منها ولا يحفظها في كوده.
     */
    private function view(ChannelAccount $account): array
    {
        return Arr::except($this->form->view($account), ['preview_url']) + [
            'options' => [
                'positions' => WebChatSettings::POSITIONS,
                'languages' => WebChatSettings::LANGUAGES,
                'phone_modes' => WebChatSettings::PHONE_MODES,
                'email_modes' => WebChatSettings::EMAIL_MODES,
                'question_targets' => WebChatQuestions::TARGETS,
                'question_validations' => WebChatQuestions::VALIDATIONS,
                'questions_max' => WebChatQuestions::MAX,
                'faqs_max' => WebChatFaqs::MAX,
                'logo_max_kb' => 1024,
                'faq_image_max_kb' => 2048,
            ],
        ];
    }

    /** @return array<string, mixed>|JsonResponse */
    private function faqInput(Request $request, ?WebChatFaq $faq, bool $full = false)
    {
        $validator = Validator::make($request->all(), $this->faqs->rules());

        if ($validator->fails()) {
            return $this->invalid($validator->errors()->toArray());
        }

        try {
            $this->faqs->check($request->all(), $request->hasFile('image'), $faq, $full);
        } catch (ValidationException $e) {
            return $this->invalid($e->errors());
        }

        return $validator->validated();
    }

    private function faqView(int $organizationId, WebChatFaq $faq): array
    {
        $faqs = WebChatFaqs::forDashboard($organizationId);

        return [
            'faq' => collect($faqs)->firstWhere('id', (int) $faq->id),
            'faqs' => $faqs,
        ];
    }

    private function invalid(array $errors): JsonResponse
    {
        return $this->fail(400, __('The provided data is invalid.'), $errors);
    }

    private function logChange(Request $request): void
    {
        ActivityLogger::log(ActivityLogger::SETTINGS_UPDATED, __('Web Chat'), null, null, [], $this->organizationId($request));
    }
}
