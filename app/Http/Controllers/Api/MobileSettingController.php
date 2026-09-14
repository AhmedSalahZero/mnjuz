<?php

namespace App\Http\Controllers\Api;

use App\Helpers\CustomHelper;
use App\Http\Controllers\Api\Concerns\MobileApi;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\ActivityLogger;
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
            'timezones' => array_values(config('formats.timezones', [])),
            'sounds' => config('sounds', []),
        ]);
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
        ]);

        if ($validator->fails()) {
            return $this->fail(400, __('The provided data is invalid.'), $validator->errors()->toArray());
        }

        $organizationId = $this->organizationId($request);
        $organization = Organization::find($organizationId);

        if (!$organization) {
            return $this->fail(404, __('No active organization was found for your account. Please select an organization and try again.'));
        }

        $metadata = $this->metadata($organization);

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

        $organization->metadata = json_encode($metadata);
        $organization->save();

        ActivityLogger::log(ActivityLogger::SETTINGS_UPDATED, null, null, null, [], $organizationId);

        return $this->general($request);
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
