<?php

namespace App\Http\Resources;

use App\Services\MyFatoorah\MyFatoorahApiClient;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MyFatoorahPaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'payment_url' => $this->payment_url,
            'invoice_id' => $this->invoice_id,
            'amount' => $this->amount,
            'currency' => $this->currency ?? MyFatoorahApiClient::resolveConfig()['currency'],
        ];
    }
}
