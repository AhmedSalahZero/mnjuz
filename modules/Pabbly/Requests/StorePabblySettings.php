<?php

namespace Modules\Pabbly\Requests;

use App\Models\Setting;
use Illuminate\Foundation\Http\FormRequest;

class StorePabblySettings extends FormRequest
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
            'settings.pabbly_api_key' => $this->secretRule('pabbly_api_key'),
            'settings.pabbly_secret_key' => $this->secretRule('pabbly_secret_key'),
            'settings.pabbly_product_name' => 'required',
        ];

        return $rules;
    }

    public function messages(): array
    {
        return [
            'settings.pabbly_api_key.required' => __('This field is required.'),
            'settings.pabbly_secret_key.required' => __('This field is required.'),
            'settings.pabbly_product_name.required' => __('This field is required.'),
        ];
    }
}
