<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use RobThree\Auth\TwoFactorAuth;
use RobThree\Auth\Providers\Qr\BaconQrCodeProvider;
use App\Models\User;

class TfaRequest extends FormRequest
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
      'token' => [
        'required',
        'string',
        function ($attribute, $value, $fail) {
          $user = User::find($this->session->get('tfa'));
          $tfa = new TwoFactorAuth(new BaconQrCodeProvider());
          $verify = $tfa->verifyCode($user->tfa_secret, $value);

          if (!$verify) {
            $fail('Invalid token');
          }
        },
      ],
    ];
  }
}
