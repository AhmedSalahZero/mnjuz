<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use App\Rules\TeamLimit;

class StoreTeam extends FormRequest
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
            'email' => [
                'required',
                'email', // Added the email validation rule
                Rule::unique('users', 'email')->where(function ($query) {
                    $organizationId = session()->get('current_organization');

                    $query->whereIn('id', function ($subQuery) use ($organizationId) {
                        $subQuery->select('user_id')
                            ->from('teams')
                            ->where('deleted_at', null)
                            ->where('organization_id', $organizationId);
                    });
                }),
                new TeamLimit
            ],
            'role' => 'required'
        ];
    }

    /**
     * Get custom error messages for validator errors.
     *
     * @return array
     */
    public function messages(): array
    {
        return [
            'email.unique' => __('User with this email already exists in your team.'),
        ];
    }
}
