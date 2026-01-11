<?php

namespace App\Http\Controllers\User;

use DB;
use App\Http\Controllers\Controller as BaseController;
use App\Http\Requests\StoreWhatsappSettings;
use App\Helpers\CustomHelper;
use App\Http\Requests\StoreWhatsappProfile;
use App\Models\Addon;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Setting;
use App\Models\Template;
use App\Services\ContactFieldService;
use App\Services\WhatsappService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Validator;

class PluginController extends BaseController
{
    public function index(Request $request){
        if ($request->isMethod('get')) {
            $data['title'] = __('Plugin Settings');
            $data['modules'] = Addon::get();
            $allModules = Addon::where('category', 'plugins')->where('is_active', 1)->where('status', 1)->get();
            
            // Filter modules based on user's plan
            $data['plugins'] = $allModules->filter(function($module) {
                return CustomHelper::isModuleEnabled($module->name);
            })->map(function($module) {
                // Add download URL for each module
                if (strtolower($module->name) === 'woocommerce') {
                    // Get WooCommerce download link from settings
                    $woocommerceLink = Setting::where('key', 'woocommerce_plugin_link')->value('value');
                    $module->download_url = $woocommerceLink ?: route('plugin.download', ['name' => strtolower($module->name)]);
                } else {
                    $module->download_url = route('plugin.download', ['name' => strtolower($module->name)]);
                }
                return $module;
            });
            
            return Inertia::render('User/Settings/Plugins/Index', $data);
        }
    }

    public function download($name)
    {
        $plugin = Addon::where('name', $name)
            ->where('category', 'plugin')
            ->where('is_active', 1)
            ->firstOrFail();

        // Check if user has access to this plugin
        if (!CustomHelper::isModuleEnabled($plugin->name)) {
            abort(403, 'You do not have access to this plugin');
        }

        if (strtolower($name) === 'woocommerce') {
            $link = Setting::where('key', 'woocommerce_plugin_link')->value('value');
            $name = Setting::where('key', 'woocommerce_plugin_name')->value('value') . '-' . 'woocommerce';
            if (!$link) {
                abort(404, 'WooCommerce plugin link not found');
            }
            // Remove /storage prefix from the link
            $link = str_replace('/storage/', '', $link);
            // Convert storage path to full path
            $pluginPath = storage_path('app/public/' . $link);
        } else {
            $pluginPath = base_path('plugins/' . strtolower($name) . '.zip');
        }

        if (!file_exists($pluginPath)) {
            abort(404, 'Plugin file not found');
        }

        return response()->download($pluginPath, $name . '.zip');
    }
}