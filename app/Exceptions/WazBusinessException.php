<?php

namespace App\Exceptions;

use Exception;

/**
 * فشل في الربط مع منصة واز أعمال. يوقف التسجيل قبل إنشاء أي بيانات محلية.
 */
class WazBusinessException extends Exception
{
}
