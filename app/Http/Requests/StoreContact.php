<?php

namespace App\Http\Requests;

use App\Rules\UniquePhone;
use App\Rules\BrazilPhoneValidation;
use Illuminate\Foundation\Http\FormRequest;
use App\Rules\ContactLimit;

class StoreContact extends FormRequest
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
        $uuid = $this->route('uuid');

        $rules = [
            'first_name' => 'required',
            'last_name' => 'nullable|max:255',
            'phone' => [
                'required',
                'string',
                'max:255',
                new BrazilPhoneValidation,
                new UniquePhone($this->organization, $uuid),
            ],
            'email' => 'nullable|email|max:255',
        ];

        // جهة اتصال من قناة أخرى (تيليجرام، المحادثة المباشرة…) بلا رقم
        // واتساب: الرقم ليس هويّتها، وطلبه كان يمنع تعديل اسمها أصلاً.
        // ContactService لا يكتبه لها، فلا معنى للتحقّق منه.
        if ($uuid && $this->isNonWhatsappContact((string) $uuid)) {
            $rules['phone'] = ['nullable'];
        }

        if ($this->isMethod('post')) {
            $rules['first_name'] = ['required', new ContactLimit];
        } else {
            $rules['first_name'] = ['required'];
        }

        return $rules;
    }

    private function isNonWhatsappContact(string $uuid): bool
    {
        $channel = \App\Models\Contact::where('uuid', $uuid)->value('channel');

        return $channel !== null && $channel !== \App\Services\Messaging\Channel::WHATSAPP;
    }
}
