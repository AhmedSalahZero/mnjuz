<?php

namespace App\Http\Controllers\Admin;

use DB;
use App\Http\Controllers\Controller as BaseController;
use App\Models\Setting;
use App\Models\TaxRate;
use App\Http\Requests\StoreTax;
use App\Http\Resources\TaxRateResource;
use App\Services\TaxService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule; 
use Inertia\Inertia;
use Helper;
use Session;
use Validator;

class TaxController extends BaseController
{
    public function __construct(TaxService $taxService)
    {
        $this->taxService = $taxService;
    }

    public function index(Request $request, $id = null){
        return Inertia::render('Admin/Setting/Tax', [
            'rows' => $this->taxService->get($request),
            'config' => Setting::get()
        ]);
    }

    public function store(StoreTax $request)
    {
        $this->taxService->store($request);

        return redirect('/admin/tax-rates')->with(
            'status', [
                'type' => 'success', 
                'message' => __('Tax rate added successfully')
            ]
        );
    }

    public function show($id)
    {
        $tax = TaxRate::where('id', $id)->first();
        return response()->json(['success' => true, 'item'=> $tax]);
    }

    public function update(StoreTax $request, $id)
    {
        $this->taxService->store($request, $id);

        return redirect('/admin/tax-rates')->with(
            'status', [
                'type' => 'success', 
                'message' => __('Tax rate updated successfully')
            ]
        );
    }

    /**
     * Delete tax rate.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        $this->taxService->delete($id);

        return redirect('/admin/tax-rates')->with(
            'status', [
                'type' => 'success', 
                'message' => __('Tax rate deleted successfully')
            ]
        );
    }
}