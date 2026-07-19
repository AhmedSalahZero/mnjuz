<?php

namespace Modules\Woocommerce\Controllers;

use App\Http\Controllers\Controller as BaseController;
use App\Models\Addon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class SetupController extends BaseController
{
    public function store(Request $request){
        $settings = $request->settings;

        // Get name and description from settings
        $pluginName = $settings['woocommerce_plugin_name'] ?? 'Swiftchats Integration';
        $pluginDescription = $settings['woocommerce_plugin_description'] ?? 'WooCommerce integration with Swiftchats';

        // Store previous plugin path for later deletion
        $previousPluginLink = DB::table('settings')->where('key', 'woocommerce_plugin_link')->value('value');

        try {
            // Download new plugin
            $response = Http::withHeaders([
                'Referer' => url('/'),
            ])->post('https://axis96.com/api/plugin/download/woocommerce', [
                'name' => $pluginName,
                'description' => $pluginDescription,
            ]);

            if ($response->successful()) {
                $pluginFilename = 'woocommerce-plugin-' . time() . '.zip';
                $pluginPath = 'public/plugins/' . $pluginFilename;

                Storage::put($pluginPath, $response->body());

                $publicUrl = Storage::url($pluginPath);

                // Save the new plugin link
                DB::table('settings')->updateOrInsert([
                    'key' => 'woocommerce_plugin_link'
                ], [
                    'value' => $publicUrl
                ]);

                // Delete the previous plugin file AFTER the new one is saved
                if ($previousPluginLink) {
                    $previousPluginPath = str_replace('/storage/', 'public/', parse_url($previousPluginLink, PHP_URL_PATH));
                    if (Storage::exists($previousPluginPath)) {
                        Storage::delete($previousPluginPath);
                    }
                }

                foreach ($settings as $key => $value) {
                    DB::table('settings')->updateOrInsert([
                        'key' => $key
                    ], [
                        'value' => $value,
                    ]);
                }

                if (isset($request->is_active)) {
                    Addon::where('uuid', $request->uuid)->update(['is_active' => $request->is_active]);
                }

                return redirect('/admin/addons')->with(
                    'status',
                    [
                        'type' => 'success',
                        'message' => __('Woocommerce plugin settings updated successfully!')
                    ]
                );
            }

            return redirect('/admin/addons')->with(
                'status',
                [
                    'type' => 'error',
                    'message' => __('Woocommerce plugin settings could not be updated!')
                ]
            );
        } catch (\Exception $e) {
            report($e);

            return redirect('/admin/addons')->with(
                'status',
                [
                    'type' => 'error',
                    'message' => __('Woocommerce plugin settings could not be updated!')
                ]
            );
        }
    }
}
