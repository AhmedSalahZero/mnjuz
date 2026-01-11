<?php

namespace App\Services;

use App\Models\BillingPayment;
use App\Models\BillingTransaction;
use App\Models\PaymentGateway;
use App\Models\Setting;
use App\Models\User;
use App\Services\SubscriptionService;
use App\Traits\ConsumesExternalServices;
use Carbon\Carbon;
use CurrencyHelper;
use DB;
use Helper;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Storage;

class MediaService
{
    public static function upload($image)
    {
        if(config('settings.use_s3_as_storage',false)){
            $path = $image->storePublicly('uploads/media/send/'.$contact->company_id,'s3');
            $imageUrl = Storage::disk('s3')->url($path);
        } else {
            $path = $image->store(null,'public',);
            $imageUrl = Storage::disk('public')->url($path);
        }

        $name = basename($path);

        return ['name' => $name, 'path' => $imageUrl];
    }
}