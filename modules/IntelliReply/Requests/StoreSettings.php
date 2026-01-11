<?php

namespace Modules\IntelliReply\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSettings extends FormRequest
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
            'settings.enable_api_key_input' => 'required',
            'settings.enable_model_selection' => 'required',
            'settings.default_open_ai_key' => 'required_if:settings.enable_api_key_input,true',
            'settings.default_open_ai_model' => 'required',
        ];

        return $rules;
    }

    public function messages(): array
    {
        return [
            'settings.enable_api_key_input.required' => __('This field is required.'),
            'settings.enable_model_selection.required' => __('Enable Model Selection field is required.'),
            'settings.default_open_ai_key.required_if' => __('Default OpenAI Key is required when API Key Input is enabled.'),
            'settings.default_open_ai_text_model.required' => __('This field is required.'),
            'settings.default_open_ai_audio_model.required' => __('This field is required.'),
        ];
    }
}
