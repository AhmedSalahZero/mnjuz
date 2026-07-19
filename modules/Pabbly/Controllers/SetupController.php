<?php

namespace Modules\Pabbly\Controllers;

use App\Http\Controllers\Controller as BaseController;

use App\Models\Addon;
use App\Models\Setting;
use App\Models\SubscriptionPlan;
use Modules\Pabbly\Requests\StorePabblySettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Modules\Pabbly\Services\PabblyService;

class SetupController extends BaseController
{
    public function store(StorePabblySettings $request)
    {
        $settings = $request->settings;
    
        foreach ($settings as $key => $value) {
            DB::table('settings')->updateOrInsert(
                ['key' => $key],
                ['value' => $value]
            );
    
            if ($key === 'pabbly_product_name') {
                $result = $this->handlePabblyProduct($value);
    
                if ($result['status'] === 'error') {
                    return redirect('/admin/addons')->with('status', [
                        'type' => 'error',
                        'message' => $result['message'] ?? __('Failed to update Pabbly product.')
                    ]);
                }
            }
        }
    
        if (isset($request->is_active)) {
            Addon::where('uuid', $request->uuid)->update(['is_active' => $request->is_active]);
        }

        // Update all subscription plans to support Pabbly
        $pabblyService = new PabblyService();
        $pabblyService->updateAllSubscriptionPlans();
    
        return redirect('/admin/addons')->with('status', [
            'type' => 'success',
            'message' => __('Pabbly Subscriptions settings updated successfully! All subscription plans have been updated to support Pabbly.')
        ]);
    }

    private function handlePabblyProduct(string $productName){
        $pabblyService = new PabblyService();
    
        $currentProductId = Setting::where('key', 'pabbly_product_id')->value('value');
    
        $response = $currentProductId
            ? $pabblyService->updateProduct($productName, $currentProductId)
            : $pabblyService->createProduct($productName);
    
        $data = $response->getData(true);
    
        if (($data['status'] ?? '') === 'error') {
            if (str_contains($data['message'], 'already exist')) {
                // Try to fetch existing product ID
                $result = $pabblyService->getProductIdByName($productName);
    
                if ($result['status'] === 'success') {
                    Setting::updateOrInsert(
                        ['key' => 'pabbly_product_id'],
                        ['value' => $result['product_id']]
                    );
                    return ['status' => 'success'];
                }
    
                return $result; // bubble error up
            }
    
            return [
                'status' => 'error',
                'message' => $data['message'] ?? 'Unknown error from Pabbly product creation.',
            ];
        }
    
        $newProductId = $data['data']['id'] ?? null;
    
        if ($newProductId) {
            Setting::updateOrInsert(
                ['key' => 'pabbly_product_id'],
                ['value' => $newProductId]
            );
            return ['status' => 'success'];
        }
    
        return [
            'status' => 'error',
            'message' => 'Failed to get product ID from Pabbly response.',
        ];
    }

}