<?php

namespace App\Http\Requests;

use App\Rules\NotRoleUser;
use App\Rules\NotUniqueEmail;
use App\Rules\UniqueEmail;
use App\Services\Broadcasting\BroadcastProvider;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOrganization extends FormRequest
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
            'plan' => 'required',
            // معرّف الشركة في واز أعمال — يضبطه المشرف يدوياً للعملاء القدامى.
            'waz_company_id' => 'nullable|integer|min:1',
            // مزوّد البثّ للمنشأة؛ الفراغ = افتراضي المنصّة.
            'broadcast_provider' => 'nullable|in:pusher,reverb',
        ];

        if ($this->isMethod('post')) {
            if ($this->input('create_user') == 1) {
                $rules += [
                    'email' => ['required', 'email', new UniqueEmail],
                    'first_name' => 'required',
                    'last_name' => 'required',
                    'password' => 'required|confirmed',
                ];
            } else {
                $rules['email'] = ['required', 'email', new NotUniqueEmail];
            }
        }

        return $rules;
    }

    /**
     * لا تُحوَّل منشأة إلى مزوّدٍ بياناته ناقصة: يتّصل متصفّحها بلا مفتاح أو
     * عنوان، فتُحفظ الرسائل ولا تصل لحظياً بلا خطأ ظاهر.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $provider = BroadcastProvider::normalize($this->input('broadcast_provider'));

            if ($provider !== null && !BroadcastProvider::isConfigured($provider)) {
                $validator->errors()->add(
                    'broadcast_provider',
                    __('Complete the :provider settings under Settings › Broadcast first.', ['provider' => ucfirst($provider)])
                );
            }
        });
    }
}
