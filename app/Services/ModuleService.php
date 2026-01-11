<?php

namespace App\Services;

use App\Models\Addon;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redirect;
use ZipArchive;

class ModuleService
{
    public function install(Request $request)
    {
        $zipFilePath = base_path('modules/addon.zip');
        
        try {
            $this->g($request->input('purchase_code'), $request->input('addon'), $zipFilePath);
            $this->e($zipFilePath);

            // Check if the file exists before unlinking
            if (file_exists($zipFilePath)) {
                unlink($zipFilePath);
            }

            $m = $this->f($request->input('purchase_code'), $request->input('addon'));

            Addon::where('uuid', $request->input('uuid'))->update([
                'metadata' => $m,
                'status' => 1
            ]);

            return Redirect::back()->with(
                'status', [
                    'type' => 'success', 
                    'message' => __('Addon installed successfully!')
                ]
            );
        } catch (RequestException $e) {
            return $this->handleRequestException($e, $zipFilePath);
        } catch (\Exception $e) {
            return $this->handleGeneralException($e, $zipFilePath);
        }
    }

    public function update(Request $request)
    {
        $addon = $request->input('addon');
        $addonName = $request->input('name');
        $zipFilePath = base_path('modules/addon.zip');

        try {
            // Download and extract the addon
            $v = $this->h($addon, $zipFilePath);
            $this->e($zipFilePath);

            // Remove the zip file if it exists
            if (file_exists($zipFilePath)) {
                unlink($zipFilePath);
            }

            $m = $this->f(NULL, $addon);

            // Retrieve the addon by name
            $addonRecord = Addon::where('name', $addonName)->first();

            // Update addon status and perform necessary actions
            if ($addonRecord) {
                $addonRecord->update([
                    'version' => $v,
                    'metadata' => $m,
                    'update_available' => 0
                ]);
            }

            return Redirect::back()->with('status', [
                'type' => 'success', 
                'message' => __('Addon updated successfully!')
            ]);
        } catch (RequestException $e) {
            return $this->handleRequestException($e, $zipFilePath);
        } catch (\Exception $e) {
            return $this->handleGeneralException($e, $zipFilePath);
        }
    }

    protected function g($purchaseCode, $addonName, $zipFilePath)
    {
        $client = new Client();
        $url = 'https://axis96.com/api/install/addon';

        $response = $client->post($url, [
            'form_params' => [
                'purchase_code' => $purchaseCode,
                'addon' => $addonName,
            ],
            'headers' => [
                'Referer' => url('/'),
            ],
            'sink' => $zipFilePath,
        ]);

        if ($response->getStatusCode() != 200) {
            //unlink($zipFilePath);
            throw new \Exception('Failed to download the addon.');
        }
    }

    protected function h($addonName, $zipFilePath)
    {
        $client = new Client();
        $url = 'https://axis96.com/api/update/addon';

        $response = $client->post($url, [
            'form_params' => [
                'addon' => $addonName,
            ],
            'headers' => [
                'Referer' => url('/'),
            ],
            'sink' => $zipFilePath,
        ]);

        if ($response->getStatusCode() != 200) {
            throw new \Exception('Failed to update the addon.');
        }

        // Retrieve the 'Addon-Version' header
        $addonVersion = $response->getHeader('Addon-Version');

        // If the header exists, it will be an array; get the first value
        if (!empty($addonVersion)) {
            $addonVersion = $addonVersion[0];
        } else {
            $addonVersion = 'Unknown';
        }

        // Log or process the version as needed
        \Log::info("Addon '{$addonName}' updated to version: {$addonVersion}");

        return $addonVersion; // Optional: return the version if needed
    }

    protected function f($purchaseCode = NULL, $addonName)
    {
        $client = new Client();
        $url = 'https://axis96.com/api/install/addon/setup';

        try {
            // Make a POST request to the API with purchase code and addon name
            $response = $client->post($url, [
                'form_params' => [
                    'purchase_code' => $purchaseCode,
                    'addon' => $addonName,
                ],
                'headers' => [
                    'Referer' => url('/'),
                ]
            ]);

            // Check for successful response
            if ($response->getStatusCode() !== 200) {
                throw new \Exception('Failed to download the addon.');
            }

            // Decode JSON response to extract metadata
            $responseData = json_decode($response->getBody()->getContents(), true);

            // Ensure the response indicates success and contains data
            if (!isset($responseData['success']) || !$responseData['success']) {
                throw new \Exception('Failed to retrieve metadata.');
            }

            $metadata = $responseData['data'];
            $moduleName = $responseData['module'];
            $serviceClass = "Modules\\{$moduleName}\\Services\\SetupService";

            // Dynamically instantiate and run the setup controller if it exists
            if (class_exists($serviceClass)) {
                (new $serviceClass())->index();
            }

            return $metadata;

        } catch (\Exception $e) {
            // Handle any exceptions that occur during the process
            throw new \Exception("An error occurred: " . $e->getMessage());
        }
    }

    protected function e($zipFilePath)
    {
        $zip = new ZipArchive;

        if ($zip->open($zipFilePath) !== TRUE) {
            //unlink($zipFilePath);
            throw new \Exception('Failed to extract addon.');
        }

        $extractToPath = base_path('modules');
        $zip->extractTo($extractToPath);
        $zip->close();
    }

    protected function handleRequestException(RequestException $e, $zipFilePath)
    {
        if (file_exists($zipFilePath)) {
            unlink($zipFilePath);
        }

        $errorMessage = $e->getMessage();
        $userMessage = 'An error occurred';

        if ($e->hasResponse()) {
            $responseBody = (string) $e->getResponse()->getBody();
            $response = json_decode($responseBody);
    
            // Check if the API response has a specific message
            if (!empty($response->message)) {
                $userMessage = $response->message;
            }
    
            // Include detailed information in the log for debugging
            \Log::error("Addon failed: {$responseBody}");
        } else {
            // Log the exception for debugging if no response body is available
            \Log::error("Addon failed: {$errorMessage}");
        }

        return Redirect::back()
            ->with('status', [
                'type' => 'error',
                'message' => $userMessage
            ])
            ->withErrors([
                'purchase_code' => $userMessage
            ])
            ->withInput();
    }

    protected function handleGeneralException(\Exception $e, $zipFilePath)
    {
        // Check if the file exists before unlinking
        if (file_exists($zipFilePath)) {
            unlink($zipFilePath);
        }
        
        return Redirect::back()->with('status', [
            'type' => 'error',
            'message' => 'An error occurred: ' . $e->getMessage()
        ])->withErrors([
            'purchase_code' => 'An error occurred: ' . $e->getMessage()
        ])->withInput();
    }
}