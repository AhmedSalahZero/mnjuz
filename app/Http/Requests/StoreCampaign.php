<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Rules\CampaignLimit;

class StoreCampaign extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array|string>
     */
    public function rules(): array
    {
        $rules = [
            'name' => 'required',
            'template' => 'required',
            'contacts' => 'required',
        ];

        if ($this->isMethod('post')) {
            $rules['name'] = ['required', new CampaignLimit];
        } else {
            $rules['name'] = ['required'];
        }
    
        if (!$this->input('skip_schedule')) {
            $rules['time'] = 'required';
        }

        // Check for header.format and header.parameters[0].value
        $headerParams = $this->input('header.parameters');

        if(!empty($headerParams)){
            $format = $this->input('header.format');
            $selection = $this->input('header.parameters.0.selection');

            // Rules for image format
            if ($format === 'TEXT') {
                $rules['header.parameters.0.value'] = 'required|max:60'; // Max 60 characters
            }

            if (in_array($format, ['IMAGE', 'DOCUMENT', 'VIDEO'], true)) {
                if ($selection === 'history') {
                    // معرّف الملف السابق (uuid). نقبل النصّ عامّةً لا uuid حصراً:
                    // الصفحات المفتوحة قبل النشر ما زالت ترسل المسار، ورفضها
                    // بالتحقّق يُنتج «هذا الحقل مطلوب» على اختيار صحيح. الخدمة
                    // تقبل الشكلين وتردّ برسالة مفهومة إن لم يوجد الملف.
                    $rules['header.parameters.0.value'] = 'required|string|max:2048';
                } elseif ($selection === 'default') {
                    $rules['header.parameters.0.value'] = 'required|url|max:2048';
                } elseif ($format === 'IMAGE') {
                    $rules['header.parameters.0.value'] = 'required|image|mimes:png,jpg,jpeg|max:5120';
                } elseif ($format === 'VIDEO') {
                    $rules['header.parameters.0.value'] = 'required|file|mimes:mp4|max:16384';
                } elseif ($format === 'DOCUMENT') {
                    $rules['header.parameters.0.value'] = 'required|file|mimes:pdf,txt,ppt,doc,xls,docx,pptx,xlsx|max:102400';
                }
            }
        }

        // Check for body.parameters[0].value
        $bodyParams = $this->input('body.parameters');
        $bodyParamValue = $this->input('body.parameters.0.value');

        if(!empty($bodyParams)){
            $rules['body.parameters.0.value'] = 'required|max:1028';
        }


        // Check each button for specific validation rules
        $buttons = $this->input('buttons', []);

        foreach ($buttons as $index => $button) {
            $buttonType = $button['type'];
            
            switch ($buttonType) {
                case 'QUICK_REPLY':
                    $rules["buttons.$index.parameters.0.value"] = 'required|max:25'; // Adjust max as needed
                    break;
                case 'COPY_CODE':
                    $rules["buttons.$index.parameters.0.value"] = 'required|max:15'; // Adjust max as needed
                    break;
                case 'URL':
                    // Check if URL has parameters
                    if (!empty($button['parameters'])) {
                        $rules["buttons.$index.parameters.0.value"] = 'required|url|max:2000'; // Adjust max as needed
                    }
                    break;
                // Add more cases for other button types as needed
            }
        }

        return $rules;
    }

    public function messages()
    {
        return [
            'name.required' => __('The name field is required.'),
            'template.required' => __('The template field is required.'),
            'contacts.required' => __('The contacts field is required.'),
            // رسائل مترجَمة لا نصوصاً إنجليزية ثابتة: العميلة رأت
            // «This field is required.» وسط واجهة عربية ففهمتها «الصورة غير
            // موجودة». والرسالة الآن تُسمّي المطلوب وتقول كيف يُنفَّذ.
            'header.parameters.0.value.required' => $this->headerRequiredMessage(),
            'header.parameters.0.value.max' => __('The value must not exceed :max characters.'),
            'header.parameters.0.value.image' => __('The value must be an image (PNG or JPG) and should not exceed 5MB.'),
            'header.parameters.0.value.video' => __('The value must be a video (MP4) and should not exceed 16MB.'),
            'body.parameters.0.value.required' => __('This field is required.'),
            'body.parameters.0.value.max' => __('The value must not exceed :max characters.'),
            'buttons.*.parameters.*.value.required' => __('This field is required.'),
            'buttons.*.parameters.*.value.max' => __('The value must not exceed :max characters.'),
            'buttons.*.parameters.*.value.url' => __('This value is not a valid url'),
            // Add other custom messages as needed
        ];
    }

    /**
     * رسالة الترويسة الفارغة، بحسب نوعها.
     *
     * «هذا الحقل مطلوب» لا تدلّ على شيء في نموذج فيه حقول كثيرة — ولا تقول
     * إن أمام العميل طريقين: الرفع أو ملف استُخدم سابقاً.
     */
    private function headerRequiredMessage(): string
    {
        return match ($this->input('header.format')) {
            'IMAGE' => __('Choose a header image: upload a new one or pick a previously used file.'),
            'VIDEO' => __('Choose a header video: upload a new one or pick a previously used file.'),
            'DOCUMENT' => __('Choose a header document: upload a new one or pick a previously used file.'),
            default => __('This field is required.'),
        };
    }
}
