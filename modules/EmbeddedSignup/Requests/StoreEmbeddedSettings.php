<?php

namespace Modules\EmbeddedSignup\Requests;

use App\Models\Setting;
use Illuminate\Foundation\Http\FormRequest;

class StoreEmbeddedSettings extends FormRequest
{
    /**
     * Secret fields are no longer sent to the browser, so a blank submission
     * means "keep the stored value". Require them only when nothing is stored yet.
     */
    private function secretRule(string $settingKey): string
    {
        $row = Setting::where('key', $settingKey)->first();

        return ($row && filled($row->value)) ? 'nullable' : 'required';
    }
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
            'settings.whatsapp_access_token' => $this->secretRule('whatsapp_access_token'),
            'settings.whatsapp_client_id' => 'required',
            'settings.whatsapp_client_secret' => $this->secretRule('whatsapp_client_secret'),
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
