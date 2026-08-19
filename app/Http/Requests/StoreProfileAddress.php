<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreProfileAddress extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $url = $this->input('support_ticket_form_url');
        if ($url === '' || $url === null) {
            $this->merge(['support_ticket_form_url' => null]);
        }

        // منتقي الخريطة يُرسل '' حين لا يُحدَّد موقع، وقاعدة numeric ترفضها
        // فيفشل حفظ الإعدادات كلّها بسبب حقل لم يمسّه المستخدم أصلاً.
        foreach (['latitude', 'longitude'] as $coordinate) {
            if ($this->input($coordinate) === '') {
                $this->merge([$coordinate => null]);
            }
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array|string>
     */
    public function rules(): array
    {
        $rules = [
            'organization_name' => 'required',
            'address' => 'required',
            'country' => 'required',
            'state' => 'required',
            'zip' => 'required',
            'city' => 'required',
            'enable_campaign_resend' => 'boolean',
            'move_failed_contacts_to_group' => 'boolean',
            'resend_intervals' => 'nullable|array',
            // حتى أسبوع لكل فترة: أشهر أسباب الفشل (131042 مشكلة الدفع) قد تستغرق
            // أياماً حتى تُحل، فحدّ الـ24 ساعة السابق كان يرفض قيماً مشروعة مثل 48.
            'resend_intervals.*' => 'integer|min:1|max:168',
            'failed_campaign_group' => 'required_if:move_failed_contacts_to_group,true|nullable|exists:contact_groups,uuid',
            'support_ticket_form_url' => 'nullable|url|max:2048',
            // موقع النشاط على الخريطة — يُرسَل للعميل بطاقةَ موقع في المحادثة.
            // اختياري كلّياً، لكن نصف نقطة لا يُرسَل: كلٌّ يشترط الآخر.
            'latitude' => 'nullable|required_with:longitude|numeric|between:-90,90',
            'longitude' => 'nullable|required_with:latitude|numeric|between:-180,180',
        ];

        return $rules;
    }
}
