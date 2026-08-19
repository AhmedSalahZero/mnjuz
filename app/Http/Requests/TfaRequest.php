<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use RobThree\Auth\Providers\Qr\BaconQrCodeProvider;
use RobThree\Auth\TwoFactorAuth;

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
    // Determine the field name based on request type
    $fieldName = ($this->expectsJson() || $this->is('api/*')) ? 'tfa_code' : 'token';
    
    return [
      $fieldName => [
        'required',
        'string',
        function ($attribute, $value, $fail) {
          // Check if this is an API request (mobile)
          if ($this->expectsJson() || $this->is('api/*')) {
			  // For mobile API, get user from tfa_token
			  $tfaToken = $this->input('tfa_token');
			  if (!$tfaToken) {
				  $fail(__('TFA token is required for API requests'));
				  return;
				}
				
				try {

					$decrypted = decrypt($tfaToken);
		
              list($userId, $timestamp) = explode('|', $decrypted);
              // Set locale based on user's language
              $user = User::find($userId);
			 
              if ($user) {
                app()->setLocale($user->language ?? 'en');
              }
              
              // Check if token is not expired (30 minutes)
              if (now()->timestamp - $timestamp > 1800) {
                $fail(__('TFA token expired'));
                return;
              }
              if (!$user) {
                $fail(__('User not found'));
                return;
              }
            } catch (\Exception $e) {
              $fail(__('Invalid TFA token'));
              return;
            }
          } else {
            // For web requests, get user from session
            $user = User::find($this->session->get('tfa'));
            if (!$user) {
              $fail(__('Session expired or invalid'));
              return;
            }
          }
          
          // Verify TFA code
          $tfa = new TwoFactorAuth(new BaconQrCodeProvider());
          $verify = $tfa->verifyCode($user->tfa_secret, $value);

          if (!$verify) {
            $fail(__('Invalid token'));
          }
        },
      ],
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
  protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator)
  {
    if ($this->expectsJson() || $this->is('api/*')) {
      // السبب الفعلي في message: التطبيق يعرضه وحده ويتجاهل errors.
      $errors = $validator->errors();

      throw new \Illuminate\Http\Exceptions\HttpResponseException(
        response()->json([
          'success' => false,
          'message' => $errors->first() ?: __('The given data was invalid.', [], getApiLang()),
          'errors' => $errors,
        ], 400)
      );
    }

    parent::failedValidation($validator);
  }
}
