<?php

namespace App\Services;

use App\Http\Resources\TaxRateResource;
use App\Models\PaymentGateway;
use App\Models\TaxRate;
use App\Services\StripeService;

class TaxService
{
    /**
     * Get all tax rates based on the provided request filters.
     *
     * @param Request $request
     * @return mixed
     */
    public function get(object $request)
    {
        $rows = TaxRate::where('deleted_at', null)->latest()->paginate(10);

        return TaxRateResource::collection($rows);
    }

    /**
     * Store Tax Rate
     *
     * @param Request $request
     * @param string $id
     * @return \App\Models\TaxRate
     */
    public function store(object $request, $id = NULL)
    {
        $taxrate = $id === null ? new TaxRate() : TaxRate::where('id', $id)->firstOrFail();
        $taxrate->name = $request->name;
        $taxrate->percentage = $request->percentage;
        $taxrate->save();

        $stripe = PaymentGateway::where('name', 'Stripe')->first();

        if($stripe->is_active == '1'){
            (new StripeService)->updateProductPrices();
        }

        return $taxrate;
    }

    /**
     * Delete Tax Rate
     *
     * @param Request $request
     * @param string $id
     * @return \App\Models\TaxRate
     */
    public function delete($id)
    {
        $taxrate = TaxRate::findOrFail($id);
        $taxrate->status = 'inactive';
        $taxrate->deleted_at = date('Y-m-d H:i:s');
        $taxrate->save();

        $stripe = PaymentGateway::where('name', 'Stripe')->first();

        if($stripe->is_active == '1'){
            (new StripeService)->updateProductPrices();
        }

        return $taxrate;
    } 
}