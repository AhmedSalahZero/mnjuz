<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class CheckModuleUpdates extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'modules:check-updates';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check for updates from the remote API and update the database';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $apiUrl = 'https://axis96.com/api/modules/versions';

        $response = $this->fetchApiResponse($apiUrl);
        if (!$response) {
            return 1;
        }

        $this->checkSwiftchatsUpdate($response['data']['scripts']['swiftchats'] ?? null);
        $this->checkAddonUpdates($response['data']['addons'] ?? []);

        $this->info('Update check completed.');
        return 0;
    }

    /**
     * Fetch the response from the API.
     *
     * @param string $url
     * @return array|null
     */
    private function fetchApiResponse(string $url): ?array
    {
        $response = Http::get($url);

        if ($response->failed()) {
            $this->error('Failed to fetch updates from the remote API.');
            return null;
        }

        $data = $response->json();

        if (!isset($data['success']) || !$data['success']) {
            $this->error('Invalid response received from the API.');
            return null;
        }

        return $data;
    }

    /**
     * Check for updates specific to the Swiftchats script.
     *
     * @param array|null $swiftchats
     * @return void
     */
    private function checkSwiftchatsUpdate(?array $swiftchats): void
    {
        if (!$swiftchats) {
            $this->error('Swiftchats script information not found in the API response.');
            return;
        }

        $latestVersion = $swiftchats['version'];
        $currentVersionSetting = DB::table('settings')->where('key', 'version')->first();

        if (!$currentVersionSetting || !$currentVersionSetting->value) {
            DB::table('settings')->updateOrInsert(['key' => 'version'], ['value' => Config::get('version.version')]);
            $currentVersion = Config::get('version.version');
        } else {
            $currentVersion = $currentVersionSetting->value;
        }

        if (version_compare($latestVersion, $currentVersion, '>')) {
            DB::table('settings')->updateOrInsert(['key' => 'is_update_available'], ['value' => 1]);
            DB::table('settings')->updateOrInsert(['key' => 'last_update_check'], ['value' => Carbon::now()]);
            DB::table('settings')->updateOrInsert(['key' => 'available_version'], ['value' => $latestVersion]);

            $this->info("Swiftchats: Update available! Latest version is {$latestVersion}.");
        } else {
            DB::table('settings')->updateOrInsert(['key' => 'is_update_available'], ['value' => 0]);
            DB::table('settings')->updateOrInsert(['key' => 'last_update_check'], ['value' => Carbon::now()]);
            $this->info("Swiftchats: Up-to-date. Current version is {$currentVersion}.");
        }
    }

    /**
     * Check for updates for all addons.
     *
     * @param array $addons
     * @return void
     */
    private function checkAddonUpdates(array $addons): void
    {
        foreach ($addons as $moduleName => $moduleInfo) {
            $addon = DB::table('addons')->where('name', $moduleName)->where('status', 1)->first();

            if (!$addon) {
                $this->warn("Module '{$moduleName}' not found in the database.");
                continue;
            }

            $updateAvailable = version_compare($moduleInfo['version'], $addon->version, '>');

            DB::table('addons')
                ->where('name', $moduleName)
                ->update(['update_available' => $updateAvailable ? 1 : 0]);

            if ($updateAvailable) {
                $this->info("Addon '{$moduleName}' has an update available (Current: {$addon->version}, Latest: {$moduleInfo['version']}).");
            } else {
                $this->info("Addon '{$moduleName}' is up-to-date (Version: {$addon->version}).");
            }
        }
    }
}
