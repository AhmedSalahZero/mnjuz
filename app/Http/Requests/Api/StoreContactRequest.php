<?php

namespace App\Http\Requests\Api;

use App\Models\Contact;
use App\Rules\UniquePhone;
use App\Rules\ValidPhone;
use App\Services\PhoneService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreContactRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalize phone before validation so UniquePhone/ValidPhone see E.164-ready input.
     */
    protected function prepareForValidation(): void
    {
        if ($this->filled('phone')) {
            $this->merge(['phone' => PhoneService::normalize($this->phone)]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array|string>
     */
    public function rules()
    {
        $organizationId = $this->get('organization')
            ?? $this->user()?->current_mobile_organization_id;

        $uuid = $this->resolveContactUuidForUniqueness($organizationId);

        return [
            'first_name' => $this->isMethod('post') ? 'required' : 'sometimes',
            'phone' => [
                $this->isMethod('post') ? 'required' : 'sometimes',
                'string',
                'max:255',
                new ValidPhone(),
                new UniquePhone($organizationId, $uuid),
            ],
            'file' => 'nullable|file',
            'street' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:255',
            'state' => 'nullable|string|max:255',
            'zip' => 'nullable|string|max:20',
            'country' => 'nullable|string|max:255',
            'metadata' => 'nullable|array',
            'is_blocked' => 'nullable|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'first_name.required' => __('The first name field is required.', [], getApiLang()),
            'phone.string' => __('The phone must be a string.', [], getApiLang()),
            'phone.max' => __('The phone may not be greater than 255 characters.', [], getApiLang()),
            'file.file' => __('The file must be a valid file.', [], getApiLang()),
            'street.string' => __('The street must be a string.', [], getApiLang()),
            'city.string' => __('The city must be a string.', [], getApiLang()),
            'state.string' => __('The state must be a string.', [], getApiLang()),
            'zip.string' => __('The zip must be a string.', [], getApiLang()),
            'country.string' => __('The country must be a string.', [], getApiLang()),
            'metadata.array' => __('The metadata must be an array.', [], getApiLang()),
            'group.array' => __('The group must be an array.', [], getApiLang()),
            'group.*.string' => __('Each group must be a string.', [], getApiLang()),
            'group.*.exists' => __('The selected group is invalid.', [], getApiLang()),
            'is_blocked.boolean' => __('The is blocked field must be true or false.', [], getApiLang()),
        ];
    }

    /**
     * Handle a failed validation attempt.
     *
     * @param  \Illuminate\Contracts\Validation\Validator  $validator
     * @return void
     *
     * @throws \Illuminate\Http\Exceptions\HttpResponseException
     */
    protected function failedValidation(Validator $validator)
    {
        // نعرض سبب الرفض الفعلي (مثل «رقم الهاتف موجود مسبقًا») بدل رسالة عامة،
        // لأن الموبايل يعرض حقل message وحده. نفس شكل duplicateContactPhoneResponse.
        $errors = $validator->errors();
        $message = $errors->first() ?: __('The given data was invalid.', [], getApiLang());

        throw new HttpResponseException(
            response()->json([
                'statusCode' => 400,
                'success' => false,
                'data' => [],
                'message' => $message,
                'errors' => $errors,
            ], 400)
        );
    }

    /**
     * Resolve the contact uuid used to exclude the current row on update.
     * Mobile may pass a numeric id in the {uuid} route parameter.
     */
    private function resolveContactUuidForUniqueness($organizationId): ?string
    {
        $routeUuid = $this->route('uuid');
        if ($routeUuid === null || $routeUuid === '' || $organizationId === null) {
            return null;
        }

        if (!is_numeric($routeUuid)) {
            return (string) $routeUuid;
        }

        return Contact::where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->where('id', (int) $routeUuid)
            ->value('uuid');
    }
}
