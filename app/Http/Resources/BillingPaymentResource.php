<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BillingPaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'processor' => $this->processor,
            'transaction_id' => $this->transaction_id ?? $this->details,
            'invoice_id' => $this->invoice_id,
            'payment_status' => $this->payment_status,
            'payment_method' => $this->payment_method,
            'amount' => number_format((float) $this->amount, 2, '.', ''),
            'currency' => $this->currency ?? config('myfatoorah.currency', 'SAR'),
            'created_at' => $this->created_at ?? null,
        ];
    }
}
