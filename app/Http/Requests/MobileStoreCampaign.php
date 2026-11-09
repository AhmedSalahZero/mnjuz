<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * إنشاء حملة من تطبيق الجوال — قواعد الويب نفسها حرفاً بحرف.
 *
 * يرث StoreCampaign كي لا تتفرّق القواعد: كل إصلاح في تحقّق الحملة (ترويسة
 * الفيديو، الجمهور الفارغ، الملف السابق) يصل التطبيق تلقائياً. الفرقان:
 * المنشأة من التوكن لا من الجلسة، وشكل الخطأ هو شكل نقاط التطبيق (400).
 */
class MobileStoreCampaign extends StoreCampaign
{
    /**
     * التطبيق يرسل multipart حين يرفع ملف الترويسة، وفيه تصل القيم نصوصاً:
     * «false» نصّاً صحيحةٌ في PHP، فكانت كل حملة مجدولة تُرسل فوراً.
     * وقالبٌ بلا ترويسة يصل بلا مفتاح header، والخدمة تقرأ header['format'].
     */
    protected function prepareForValidation(): void
    {
        $header = $this->input('header');

        $this->merge([
            'skip_schedule' => $this->boolean('skip_schedule'),
            'header' => is_array($header) ? $header + ['format' => null, 'parameters' => []] : ['format' => null, 'parameters' => []],
            'body' => is_array($this->input('body')) ? $this->input('body') : ['parameters' => []],
            'footer' => is_array($this->input('footer')) ? $this->input('footer') : ['text' => null],
            'buttons' => is_array($this->input('buttons')) ? $this->input('buttons') : [],
        ]);
    }

    /** قواعد الويب، ووقت الجدولة يُقرأ تاريخاً بتوقيت المنشأة. */
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['name'] = array_merge((array) $rules['name'], ['string', 'max:128']);
        $rules['template'] = 'required|string';
        $rules['contacts'] = 'required|string';

        if (!$this->boolean('skip_schedule')) {
            $rules['time'] = 'required|date_format:Y-m-d H:i';
        }

        return $rules;
    }

    protected function organizationId(): int
    {
        return (int) ($this->user()?->current_mobile_organization_id ?? 0);
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'statusCode' => 400,
            'success' => false,
            'message' => __('The provided data is invalid.'),
            'errors' => $validator->errors()->toArray(),
        ], 400));
    }
}
