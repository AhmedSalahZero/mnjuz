<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Rules\Tfa;

class StoreProfileTfa extends FormRequest
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
      'status' => 'required',
      'token' => [
        'required_if:status,1',
        'string',
        'nullable',
        new Tfa($this->status, auth()->user()->tfa_secret),
      ],
    ];
  }
}
