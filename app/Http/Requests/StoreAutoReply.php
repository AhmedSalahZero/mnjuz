<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Rules\CannedReplyLimit;
use App\Services\Messaging\AutomationChannels;

class StoreAutoReply extends FormRequest
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
            'trigger' => 'required',
            'match_criteria' => 'required',
            'response_type' => 'required',
            'response' => 'required',
            // قناة واحدة على الأقل: ردٌّ بلا قنوات لا يعمل في أيّ مكان. والمفتاح
            // نفسه اختياريّ: صفحةٌ فُتحت قبل التحديث لا ترسله، وكان يرفضها بـ 422
            // لا تعرضه واجهتها القديمة (AutoReplyService يُبقي ما اختير).
            'channels' => ['sometimes', 'array', 'min:1'],
            'channels.*' => ['string', Rule::in(AutomationChannels::available())],
        ];

        // Add the CannedReplyLimit rule only for POST requests
        if ($this->isMethod('post')) {
            $rules['name'] = ['required', new CannedReplyLimit($this->organization)];
        } else {
            $rules['name'] = ['required'];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'channels.required' => __('Choose at least one channel.'),
            'channels.min' => __('Choose at least one channel.'),
        ];
    }
}
