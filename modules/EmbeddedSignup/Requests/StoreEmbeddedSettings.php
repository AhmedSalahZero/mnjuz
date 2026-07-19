<?php

namespace Modules\EmbeddedSignup\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreEmbeddedSettings extends FormRequest
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
            'settings.whatsapp_access_token' => 'required',
            'settings.whatsapp_client_id' => 'required',
            'settings.whatsapp_client_secret' => 'required',
            'settings.whatsapp_config_id' => 'required'
        ];

        return $rules;
    }

    public function messages(): array
    {
        return [
            'settings.whatsapp_access_token.required' => __('This field is required.'),
            'settings.whatsapp_client_id.required' => __('This field is required.'),
            'settings.whatsapp_client_secret.required' => __('This field is required.'),
            'settings.whatsapp_config_id.required' => __('This field is required.'),
        ];
    }
}
