<?php

namespace App\Http\Controllers\Api;

use App\Helpers\CustomHelper;
use App\Http\Controllers\Api\Concerns\MobileApi;
use App\Http\Controllers\Controller;
use App\Models\ContactGroup;
use App\Models\Organization;
use App\Models\Template;
use App\Services\ActivityLogger;
use App\Services\ContactPlaceholderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * إعدادات المنشأة من التطبيق: العامّة، وأوقات العمل.
 *
 * كلّها تسكن عمود metadata في جدول organizations — لا جدول لكل إعداد. لذلك
 * كل كتابة تقرأ الـ JSON كاملاً وتُعدّل مفاتيحها وتُعيده، ولا تكتب العمود
 * من الصفر: الكتابة الكاملة تمحو ما لا تعرفه هذه النقطة من إعدادات.
 */
class MobileSettingController extends Controller
{
    use MobileApi;

    /**
     * متغيّرات الرسائل: ما يُدرجه المستخدم في نصّ يُرسَل لاحقاً لجهة اتصال.
     *
     * يقابل نافذة «اختر متغيّر» في الويب، وتحتاجها شاشتان في التطبيق: رسالة
     * خارج أوقات العمل، ونصّ الردّ الجاهز.
     *
     * ولا يصحّ أن يحفظها التطبيق في كوده: نصفها حقول مخصّصة تُعرَّف في كل
     * منشأة على حدة وتتغيّر متى أضاف العميل حقلاً.
     *
     * التطبيق يعرض `label` ويُدرج `value` في موضع المؤشّر، ولا يستبدل شيئاً
     * بنفسه — الاستبدال كلّه في الخادم وقت الإرسال.
     */
    public function placeholders(Request $request): JsonResponse
    {
        return $this->ok(
            ContactPlaceholderService::optionsForOrganization($this->organizationId($request))
        );
    }

    /** ما يقرأه التطبيق من الإعدادات العامّة. */
    public function general(Request $request): JsonResponse
    {
        $organization = Organization::find($this->organizationId($request));

        if (!$organization) {
            return $this->fail(404, __('No active organization was found for your account. Please select an organization and try again.'));
        }

        $metadata = $this->metadata($organization);
        $address = $organization->address ? json_decode($organization->address, true) : [];

        return $this->ok([
            'organization' => [
                'id' => $organization->id,
                'uuid' => $organization->uuid,
                'name' => $organization->name,
                'address' => is_array($address) ? $address : [],
            ],
            'timezone' => $metadata['timezone'] ?? null,
            'notifications' => [
                'enable_sound' => (bool) ($metadata['notifications']['enable_sound'] ?? false),
                'tone' => $metadata['notifications']['tone'] ?? null,
                'volume' => $metadata['notifications']['volume'] ?? null,
            ],
            'campaigns' => [
                'enable_resend' => (bool) ($metadata['campaigns']['enable_resend'] ?? false),
                'resend_intervals' => array_values((array) ($metadata['campaigns']['resend_intervals'] ?? [])),
                'move_failed_contacts_to_group' => (bool) ($metadata['campaigns']['move_failed_contacts_to_group'] ?? false),
                'failed_campaign_group' => $metadata['campaigns']['failed_campaign_group'] ?? null,
            ],
            'support' => [
                'ticket_form_url' => $metadata['support']['ticket_form_url'] ?? null,
            ],
            'auth_template' => $this->authTemplate($organization, $metadata),
            // اختيارٌ ضاع: القالب كان مختاراً ثم حُذف.
            //
            // auth_template تعود null في الحالتين — «لم يُختَر قطّ» و«اختير
            // ثم حُذف» — والفرق مهمّ للمستخدم: الثانية تعني أن رسالة التحقّق
            // توقّفت عنده وهو لا يدري. فالتطبيق يُنبّهه أن يختار بديلاً.
            'auth_template_missing' => $this->authTemplateMissing($organization, $metadata),
            'timezones' => array_values(config('formats.timezones', [])),
            'sounds' => config('sounds', []),
            // قوائم الاختيار التي كانت ناقصة: النقطة تقبل تعديل
            // campaigns.failed_campaign_group و auth_template وتُرجع المختار
            // منهما، ولم تكن تُرجع البدائل — فالتطبيق يعرف المختار ولا يستطيع
            // رسم القائمة.
            'contact_groups' => $this->contactGroups($organization->id),
            'auth_templates' => $this->approvedTemplates($organization->id),
        ]);
    }

    /** مجموعات جهات الاتصال — بدائل campaigns.failed_campaign_group. */
    private function contactGroups(int $organizationId): array
    {
        return ContactGroup::where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get(['uuid', 'name'])
            ->map(fn (ContactGroup $group) => [
                'uuid' => (string) $group->uuid,
                'name' => $group->name,
            ])
            ->all();
    }

    /**
     * القوالب المعتمدة وحدها — بدائل auth_template.
     *
     * الويب يُرشِّح بـ APPROVED، وقالبٌ لم تعتمده Meta يُردّ عند الإرسال.
     * و list-templates لا تُرشِّح، فلو بنى التطبيق قائمته منها عرض قوالب
     * تفشل عند الاستعمال.
     *
     * بلا metadata: بنية القالب قد تكون ثقيلة ولا تلزم إلا للمختار، وهي في
     * list-templates لمن أراد معاينةً أو تحرير متغيّرات.
     */
    private function approvedTemplates(int $organizationId): array
    {
        return Template::where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->where('status', 'APPROVED')
            ->orderBy('name')
            ->get(['uuid', 'name', 'language'])
            ->map(fn (Template $template) => [
                'uuid' => (string) $template->uuid,
                'name' => $template->name,
                'language' => $template->language,
            ])
            ->all();
    }

    /**
     * هل يشير الإعداد إلى قالب لم يعد موجوداً؟
     *
     * @param  array<string, mixed>  $metadata
     */
    private function authTemplateMissing(Organization $organization, array $metadata): bool
    {
        $uuid = $metadata['auth_template'] ?? null;

        if (!is_string($uuid) || $uuid === '') {
            return false;
        }

        return $this->authTemplate($organization, $metadata) === null;
    }

    /**
     * قالب المصادقة المختار ومتغيّراته.
     *
     * يُرسله send-auth-template لرمز التحقق. والمتغيّرات تُحفظ ومعها uuid
     * القالب الذي بُنيت له، ويشترط المُرسِل تطابقهما — فمتغيّرات قالبٍ قديم
     * تُهمَل صامتةً بعد تبديل القالب. نُرجع null عندها بدل أن نُظهر للتطبيق
     * متغيّرات لن تُستعمل.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function authTemplate(Organization $organization, array $metadata): ?array
    {
        $uuid = $metadata['auth_template'] ?? null;

        if (!is_string($uuid) || $uuid === '') {
            return null;
        }

        // بلا whereNull: الموديل يستثني المحذوف بنفسه منذ إضافة SoftDeletes.
        $template = Template::where('uuid', $uuid)
            ->where('organization_id', $organization->id)
            ->first();

        if (!$template) {
            return null;
        }

        $parameters = $metadata['auth_template_parameters'] ?? null;
        $belongsToTemplate = is_array($parameters) && ($parameters['template'] ?? null) === $uuid;

        return [
            'uuid' => (string) $template->uuid,
            'name' => $template->name,
            'language' => $template->language,
            'status' => $template->status,
            // بنية القالب للمختار وحده — بها يرسم التطبيق المعاينة من هذا
            // الاستدعاء بلا حاجة إلى list-templates.
            //
            // للمختار وحده لا لكل القوالب: metadata لكل قالب ثقيلة، وردّ
            // الإعدادات يُستدعى عند كل فتح للشاشة.
            'components' => $this->components($template),
            'parameters' => $belongsToTemplate ? $parameters : null,
        ];
    }

    /**
     * مكوّنات القالب: HEADER و BODY و FOOTER و BUTTONS كما تحفظها Meta.
     *
     * مصفوفة دائماً ولو كانت metadata فارغة أو تالفة — فالتطبيق يمرّ عليها
     * بحلقة، وnull تُسقطه.
     *
     * @return array<int, mixed>
     */
    private function components(Template $template): array
    {
        $metadata = $template->metadata ? json_decode($template->metadata, true) : null;

        if (!is_array($metadata) || !isset($metadata['components']) || !is_array($metadata['components'])) {
            return [];
        }

        return array_values($metadata['components']);
    }

    /**
     * تعديل الإعدادات العامّة.
     *
     * اسم المنشأة وعنوانها ليسا هنا عمداً: تغييرهما يُزامَن مع منصّة الفوترة
     * (واز) وله أثر محاسبي، فيبقى في الويب حتى يُنقل بمساره كاملاً.
     *
     * والمدير والمالك وحدهما — الموظّف لا يغيّر إعدادات المنشأة.
     */
    public function updateGeneral(Request $request): JsonResponse
    {
        if (!$this->isPrivileged($request)) {
            return $this->forbidden();
        }

        $organizationId = $this->organizationId($request);
        $organization = Organization::find($organizationId);

        if (!$organization) {
            return $this->fail(404, __('No active organization was found for your account. Please select an organization and try again.'));
        }

        $metadata = $this->metadata($organization);

        $validator = Validator::make($request->all(), [
            'timezone' => 'nullable|string|max:64',
            'notifications' => 'nullable|array',
            'notifications.enable_sound' => 'nullable|boolean',
            'notifications.tone' => 'nullable|string|max:255',
            'notifications.volume' => 'nullable|numeric|between:0,1',
            'campaigns' => 'nullable|array',
            'campaigns.enable_resend' => 'nullable|boolean',
            'campaigns.resend_intervals' => 'nullable|array|max:10',
            'campaigns.resend_intervals.*' => 'integer|min:1',
            'campaigns.move_failed_contacts_to_group' => 'nullable|boolean',
            'campaigns.failed_campaign_group' => 'nullable|string|max:64',
            'support' => 'nullable|array',
            'support.ticket_form_url' => 'nullable|url|max:2048',
            'auth_template' => 'nullable|string|max:64',
            'auth_template_parameters' => 'nullable|array',
        ]);

        $this->validateAuthTemplate($validator, $request, $organizationId, $metadata);

        if ($validator->fails()) {
            return $this->fail(400, __('The provided data is invalid.'), $validator->errors()->toArray());
        }

        // ما لم يُرسله التطبيق يبقى كما هو: الإرسال الجزئي هو القاعدة في
        // شاشات الجوال، ومسح ما لم يُذكر يُطفئ إعدادات لم يمسّها أحد.
        foreach (['timezone'] as $key) {
            if ($request->has($key)) {
                $metadata[$key] = $request->input($key);
            }
        }

        foreach (['notifications', 'campaigns', 'support'] as $section) {
            if (!$request->has($section)) {
                continue;
            }

            $metadata[$section] = array_merge(
                (array) ($metadata[$section] ?? []),
                (array) $request->input($section)
            );
        }

        $this->applyAuthTemplate($metadata, $request);

        $organization->metadata = json_encode($metadata);
        $organization->save();

        ActivityLogger::log(ActivityLogger::SETTINGS_UPDATED, null, null, null, [], $organizationId);

        return $this->general($request);
    }

    /**
     * قالب المصادقة: معتمدٌ ويخصّ هذه المنشأة، ومتغيّراته تخصّه هو.
     *
     * المُرسِل يشترط تطابق `parameters.template` مع القالب المختار وإلّا
     * أهملها صامتاً (ApiController::sendAuthTemplate). فنردّ هنا برسالة
     * مفهومة بدل أن يحفظ العميل متغيّرات لن تُستعمل أبداً.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function validateAuthTemplate($validator, Request $request, int $organizationId, array $metadata): void
    {
        $validator->after(function ($validator) use ($request, $organizationId, $metadata) {
            $selected = $metadata['auth_template'] ?? null;

            if ($request->has('auth_template')) {
                $selected = $request->input('auth_template');

                if (is_string($selected) && $selected !== '') {
                    $approved = Template::where('uuid', $selected)
                        ->where('organization_id', $organizationId)
                        ->whereNull('deleted_at')
                        ->where('status', 'APPROVED')
                        ->exists();

                    if (!$approved) {
                        $validator->errors()->add(
                            'auth_template',
                            __('Choose an approved template that belongs to this organization.')
                        );

                        return;
                    }
                } else {
                    $selected = null;
                }
            }

            $parameters = $request->input('auth_template_parameters');

            if (!$request->has('auth_template_parameters') || !is_array($parameters) || $parameters === []) {
                return;
            }

            if (($parameters['template'] ?? null) !== $selected) {
                $validator->errors()->add(
                    'auth_template_parameters',
                    __('These variables belong to a different template.')
                );
            }
        });
    }

    /** @param array<string, mixed> $metadata */
    private function applyAuthTemplate(array &$metadata, Request $request): void
    {
        if ($request->has('auth_template')) {
            $previous = $metadata['auth_template'] ?? null;
            $selected = $request->input('auth_template');

            if (!is_string($selected) || $selected === '') {
                unset($metadata['auth_template'], $metadata['auth_template_parameters']);
            } else {
                $metadata['auth_template'] = $selected;

                // متغيّرات القالب السابق لا تصلح للجديد — ويُهملها المُرسِل
                // صامتاً، فتبقى في القاعدة تُوهم أنها فعّالة.
                if ($selected !== $previous && !$request->has('auth_template_parameters')) {
                    unset($metadata['auth_template_parameters']);
                }
            }
        }

        if (!$request->has('auth_template_parameters')) {
            return;
        }

        $parameters = $request->input('auth_template_parameters');

        if (!is_array($parameters) || $parameters === []) {
            unset($metadata['auth_template_parameters']);

            return;
        }

        $metadata['auth_template_parameters'] = $parameters;
    }

    /** أوقات العمل: فترة أو أكثر لكل يوم، ورسالة تُرسل خارجها. */
    public function workingHours(Request $request): JsonResponse
    {
        $organizationId = $this->organizationId($request);

        if (!CustomHelper::isModuleEnabled('Working Hours', $organizationId)) {
            return $this->fail(403, __('This feature is not available in your plan.'));
        }

        $organization = Organization::find($organizationId);

        if (!$organization) {
            return $this->fail(404, __('No active organization was found for your account. Please select an organization and try again.'));
        }

        $metadata = $this->metadata($organization);
        $slots = $metadata['working_hours'] ?? [];
        $message = $metadata['working_hours_outside_message'] ?? '';

        return $this->ok([
            'working_hours' => is_array($slots) ? array_values($slots) : [],
            'working_hours_outside_message' => is_string($message) ? $message : '',
        ]);
    }

    public function updateWorkingHours(Request $request): JsonResponse
    {
        $organizationId = $this->organizationId($request);

        if (!CustomHelper::isModuleEnabled('Working Hours', $organizationId)) {
            return $this->fail(403, __('This feature is not available in your plan.'));
        }

        if (!$this->isPrivileged($request)) {
            return $this->forbidden();
        }

        $validator = Validator::make($request->all(), [
            'slots' => 'nullable|array|max:64',
            'slots.*.day' => 'required|integer|between:0,6',
            'slots.*.open' => 'required|regex:/^\d{2}:\d{2}$/',
            'slots.*.close' => 'required|regex:/^\d{2}:\d{2}$/',
            'working_hours_outside_message' => 'nullable|string|max:4096',
        ]);

        if ($validator->fails()) {
            return $this->fail(400, __('The provided data is invalid.'), $validator->errors()->toArray());
        }

        $validated = $validator->validated();

        // نهاية قبل بداية تعني فترةً لا تُغلق أبداً، فتبقى المنشأة «خارج
        // الدوام» على الدوام. نفس فحص الويب حرفاً بحرف.
        foreach ($validated['slots'] ?? [] as $slot) {
            if (strcmp($slot['open'], $slot['close']) >= 0) {
                return $this->fail(400, __('Each working hours row must have an end time after the start time.'));
            }
        }

        $slots = array_map(fn (array $slot) => [
            'day' => (int) $slot['day'],
            'open' => substr($slot['open'], 0, 5),
            'close' => substr($slot['close'], 0, 5),
        ], $validated['slots'] ?? []);

        $organization = Organization::find($organizationId);
        $metadata = $this->metadata($organization);
        $metadata['working_hours'] = $slots;
        $metadata['working_hours_outside_message'] = $validated['working_hours_outside_message'] ?? '';

        $organization->metadata = json_encode($metadata);
        $organization->save();

        ActivityLogger::log(ActivityLogger::SETTINGS_UPDATED, null, null, null, [], $organizationId);

        return $this->ok([
            'working_hours' => $slots,
            'working_hours_outside_message' => $metadata['working_hours_outside_message'],
        ], __('Settings updated successfully'));
    }

    /** @return array<string, mixed> */
    private function metadata(Organization $organization): array
    {
        $metadata = $organization->metadata ? json_decode($organization->metadata, true) : [];

        return is_array($metadata) ? $metadata : [];
    }
}
