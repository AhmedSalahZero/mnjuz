<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use RobThree\Auth\TwoFactorAuth;
use RobThree\Auth\Providers\Qr\BaconQrCodeProvider;

class Tfa implements ValidationRule
{
  protected $status;
  protected $userSecret;

  /**
   * Create a new rule instance.
   *
   * @param bool $status
   * @param string $userSecret
   */
  public function __construct($status, $userSecret)
  {
    $this->status = $status;
    $this->userSecret = $userSecret;
  }

  /**
   * Determine if the validation rule passes.
   */
  public function validate(string $attribute, mixed $value, Closure $fail): void
  {
    if ($this->status) {
      $tfa = new TwoFactorAuth(new BaconQrCodeProvider());
      $verify = $tfa->verifyCode($this->userSecret, $value);

      if (!$verify) {
        $fail('Invalid token');
      }
    }
  }
}
