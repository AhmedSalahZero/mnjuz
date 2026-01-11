<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreWhatsappProfile extends FormRequest
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
        return [
            'profile_picture_url' => [],
        ];
    }

    protected function withValidator($validator)
    {
        if ($this->hasFile('profile_picture_url')) {
            $validator->addRules([
                'profile_picture_url' => [
                    'file',     // Validates it as a file
                    'image',    // Ensures it is an image
                    'mimes:jpg,png', // Restricts file types to JPG and PNG
                    'dimensions:min_width=192,min_height=192', // Validates dimensions
                ],
            ]);
        }
    }
}