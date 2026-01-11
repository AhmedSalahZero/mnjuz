<?php 

namespace App\Helpers;

use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class CurrencyHelper
{
    public static function formatCurrency($amount) {
        return number_format($amount, 2);
    }
}