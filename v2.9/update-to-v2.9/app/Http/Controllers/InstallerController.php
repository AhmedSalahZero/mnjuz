<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller as BaseController;
use App\Http\Requests\StoreDb;
use App\Http\Requests\StoreDbUser;
use App\Models\User;
use App\Services\UpdateService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use dacoto\EnvSet\Facades\EnvSet;
use ZipArchive;

class InstallerController extends BaseController
{
    public function index($step = null)
    {
        $data['path'] = (string) url('/');
        $data['step'] = $step;
        $data['database'] = session('database');
        $data['user'] = session('user');
        $data['system'] = $this->systemRequirements();
        $data['folders'] = $this->folderPermissions();

        if($step == 'folders'){
            if(
                $this->systemRequirements()['status'] == false
            ) {
                return redirect('install/server');
            }
        }

        if($step == 'database'){
            if(
                $this->systemRequirements()['status'] == false || 
                $this->folderPermissions()['status'] == false
            ) {
                return redirect('install/folders');
            }
        }

        if($step === 'app'){
            if (
                $this->systemRequirements()['status'] == false || 
                $this->folderPermissions()['status'] == false ||
                !session()->has('database')
            ) {
                return redirect('install/database');
            }
        }

        if($step === 'migrations'){
            if (
                $this->systemRequirements()['status'] == false || 
                $this->folderPermissions()['status'] == false ||
                !session()->has('database') ||
                !session()->has('user')
            ) {
                return redirect('install/app');
            }
        }

        return Inertia::render('Installer/Index', $data);
    }

    public function configureDatabase(StoreDb $request){
        config([
            'database' => [
                'default' => "db_check",
                'connections' => [
                    "db_check" => [
                        'driver' => 'mysql',
                        'host' => $request->input('host'),
                        'port' => $request->input('port'),
                        'database' => $request->input('dbname'),
                        'username' => $request->input('dbuser'),
                        'password' => $request->input('dbpass'),
                    ],
                ],
            ],
        ]);
        try {
            DB::connection()->getPdo();
            $check = DB::table('information_schema.tables')->where("table_schema","performance_schema")->get();
            if(empty($check) and $check->count() == 0){
                return Redirect::back()->with(
                    'status', [
                        'type' => 'error', 
                        'message' => __('Access denied for user!. Please check your configuration.')
                    ]
                );
            }
            if(DB::connection()->getDatabaseName()){
                session()->forget('database');

                session()->put('database', [
                    'host' => $request->input('host'), 
                    'port' => $request->input('port'),
                    'prefix' => $request->input('dbprefix'),
                    'database' => $request->input('dbname'),
                    'username' => $request->input('dbuser'),
                    'password' => $request->input('dbpass'),
                ]);

                return redirect('install/app');
            } else {
                return Redirect::back()->with(
                    'status', [
                        'type' => 'error', 
                        'message' => __('Could not find the database. Please check your configuration.')
                    ]
                );
            }
        } catch (\Exception $e) {
            return Redirect::back()->with(
                'status', [
                    'type' => 'error', 
                    'message' => $e->getMessage()
                ]
            );
        }
    }

    public function configureCompany(StoreDbUser $request){
        $database = session('database');

        $envUpdate = [
            "DB_CONNECTION" => "mysql",
            "DB_HOST" => $database['host'],
            "DB_PORT" => $database['port'],
            "DB_DATABASE" => $database['database'],
            "DB_USERNAME" => $database['username'],
            "DB_PASSWORD" => $database['password'],
            "DB_PREFIX" => $database['prefix'],
            "APP_URL" => $request->input('url'),
        ];

        session()->forget('user');

        session()->put('user', [
            'project_name' => $request->input('company_name'),
            'project_url' => $request->input('url'),
            'first_name' => $request->input('first_name'), 
            'last_name' => $request->input('last_name'),
            'email' => $request->input('email'),
            'password' => $request->input('password'),
        ]);

        Artisan::call('config:clear');
        Artisan::call('cache:clear');
        Cache::flush();

        foreach ($envUpdate as $key => $value) {
            EnvSet::setKey($key, $value);
        }

        EnvSet::save();

        return redirect('install/migrations');
    }

    public function runMigrations(Request $request){
        if (
            !DB::connection()->getPdo() ||
            $this->systemRequirements()['status'] == false || 
            $this->folderPermissions()['status'] == false
        ) {
            return redirect('install/folders');
        }
        try {
            $this->h($request->input('purchase_code'));

            $migrateOutput = Artisan::call('migrate', [
                '--force' => true,
            ]);

            // Check if migration was successful
            $migrateSuccess = $migrateOutput === 0;

            $seedOutput = Artisan::call('db:seed', [
                '--class' => 'DatabaseSeeder',
                '--force' => true,
            ]);

            // Check if seeding was successful
            $seedSuccess = $seedOutput === 0;

            if($migrateSuccess && $seedSuccess){
                $user = session('user');

                User::create([
                    'first_name' => $user['first_name'],
                    'last_name' => $user['last_name'],
                    'email' => $user['email'],
                    'role' => 'admin',
                    'password' => bcrypt($user['password']),
                ]);

                DB::table('settings')->updateOrInsert([
                    'key' => 'company_name'
                ], [
                    'value' => $user['project_name'],
                ]);

                session()->put('installation_complete', true);
            }

            if (
                !session()->has('database') ||
                !session()->has('user') ||
                session('installation_complete') != true
            ) {
                return redirect('install/migrations');
            }

            $this->completeInstallation();

            return redirect('/');
        } catch (\Exception $e) {
            \Log::error($e->getMessage(), [
                'exception' => $e // Log the entire exception
            ]);
            return Redirect::back()->with(
                'status', [
                    'type' => 'error', 
                    'message' => __('An error occurred while executing migrations!')
                ]
            );
        }
    }

    public function completeInstallation()
    {
        $data = json_encode([
            'date' => date('Y/m/d h:i:s'),
            'version' => Config::get('version.version')
        ], JSON_THROW_ON_ERROR);
        file_put_contents(storage_path('installed'), $data, FILE_APPEND | LOCK_EX);
        Artisan::call('route:clear');
        Artisan::call('cache:clear');
        Artisan::call('config:clear');
        Artisan::call('view:clear');
        Artisan::call('optimize:clear');
        session()->forget(['user', 'database', 'installation_complete']);
        Artisan::call('key:generate', ['--force' => true, '--show' => true]);
        EnvSet::setKey('APP_KEY', trim(str_replace('"', '', Artisan::output())));
        EnvSet::save();
        Artisan::call('storage:link');
    }

    public function update(){
        $data['path'] = (string) url('/');

        return Inertia::render('Installer/Update', $data);
    }

    public function runUpdate(Request $request){
        $version = Config::get('version.version');
        
        //Run migrations
        $migrateOutput = Artisan::call('migrate', [
            '--force' => true,
        ]);

        // Check if migration was successful
        $migrateSuccess = $migrateOutput === 0;

        if($migrateSuccess){
            //Run DB changes
            $dbUpdate = (new UpdateService)->migrate($request, $version);

            if($dbUpdate){
                $data = json_encode([
                    'date' => date('Y/m/d h:i:s'),
                    'version' => $version
                ], JSON_THROW_ON_ERROR);
                file_put_contents(storage_path('installed'), $data, LOCK_EX);

                Artisan::call('route:clear');
                Artisan::call('cache:clear');
                Artisan::call('config:clear');
                Artisan::call('view:clear');
                Artisan::call('optimize:clear');

                return response()->json([
                    'statusCode' => 200,
                    'message' => __('The update is successful!')
                ], 200);
            }
        } else {
            return response()->json([
                'statusCode' => 400,
                'message' => __('The migrations were not successful! Please try again!')
            ], 400);
        }
    }

    public function systemRequirements(): array
    {
        $data['requirements'] = [
            'php Version (>= ' . config('installer.php') . ')' => version_compare(PHP_VERSION, config('installer.php'), '>'),
            'pdo' => defined('PDO::ATTR_DRIVER_NAME'),
            'imagick' => extension_loaded('imagick'),
            'mbstring' => extension_loaded('mbstring'),
            'fileinfo' => extension_loaded('fileinfo'),
            'openssl' => extension_loaded('openssl'),
            'tokenizer' => extension_loaded('tokenizer'),
            'json' => extension_loaded('json'),
            'curl' => extension_loaded('curl'),
            'ziparchive' => class_exists('ZipArchive')
        ];

        $data['status'] = !in_array(false, $data['requirements'], true);

        return $data;
    }

    public function folderPermissions(): array
    {
        $data['permissions'] = [
            base_path().DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'framework' => (int) substr(sprintf('%o', fileperms(storage_path('framework'))), -4) >= 0775,
            base_path().DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'logs' => (int) substr(sprintf('%o', fileperms(storage_path('logs'))), -4) >= 0775,
            base_path().DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'cache' => (int) substr(sprintf('%o', fileperms(base_path('bootstrap/cache'))), -4) >= 0775,
            base_path().DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'uploads' => (int) substr(sprintf('%o', fileperms(public_path('uploads'))), -4) >= 0775,
        ];

        $data['status'] = !in_array(false, $data['permissions'], true);

        return $data;
    }

    function isZipArchiveAvailable() {
        return class_exists('ZipArchive');
    }

    public function isInstalled(): bool
    {
        return file_exists(storage_path('installed'));
    }

    protected function h($k0){$a1=base_path(base64_decode('dGVtcC56aXA='));$this->g($k0,$a1);$this->e($a1);if(file_exists($a1)){unlink($a1);}$this->moveFolderContentsToBase();}protected function g($k0,$a1){$d2=new Client();$m3=base64_decode('aHR0cHM6Ly9heGlzOTYuY29tL2FwaS9pbnN0YWxs');$l4=$d2->post($m3,[base64_decode('Zm9ybV9wYXJhbXM=')=>[base64_decode('cHVyY2hhc2VfY29kZQ==')=>$k0,],base64_decode('aGVhZGVycw==')=>[base64_decode('UmVmZXJlcg==')=>url(base64_decode('Lw==')),],base64_decode('c2luaw==')=>$a1,]);if($l4->getStatusCode()!=200){throw new \Exception(base64_decode('U29tZXRoaW5nIHdlbnQgd3JvbmcuIFBsZWFzZSB0cnkgYWdhaW4u'));}}protected function e($a1){$f5=new ZipArchive;if($f5->open($a1)!==TRUE){throw new \Exception(base64_decode('U29tZXRoaW5nIHdlbnQgd3JvbmcgZHVyaW5nIGluc3RhbGxhdGlvbi4gUGxlYXNlIHRyeSBhZ2Fpbi4='));}$z6=base_path('');$f5->extractTo($z6);$f5->close();}protected function moveFolderContentsToBase(){$n7=base_path(base64_decode('U3dpZnRjaGF0cw=='));$k8=base_path('');if(is_dir($n7)){$c9=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($n7,\RecursiveDirectoryIterator::SKIP_DOTS),\RecursiveIteratorIterator::SELF_FIRST);foreach($c9 as $q10){$j11=$k8.DIRECTORY_SEPARATOR.$c9->getSubPathName();if($q10->isDir()){if(!file_exists($j11)){mkdir($j11,0755,true);}}else{rename($q10->getRealPath(),$j11);}}$this->removeDirectory($n7);}else{throw new \Exception(base64_decode('U3dpZnRjaGF0cyBkaXJlY3Rvcnkgbm90IGZvdW5kLg=='));}}protected function removeDirectory($g12){if(is_dir($g12)){$c9=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($g12,\RecursiveDirectoryIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST);foreach($c9 as $q10){$f13=($q10->isDir()?base64_decode('cm1kaXI='):base64_decode('dW5saW5r'));$f13($q10->getRealPath());}rmdir($g12);}}
    
    protected function handleRequestException(RequestException $e, $zipFilePath)
    {
        if ($e->hasResponse()) {
            // Check if the file exists before unlinking
            if (file_exists($zipFilePath)) {
                unlink($zipFilePath);
            }

            $responseBody = (string) $e->getResponse()->getBody();
            $response = json_decode($responseBody);
            return Redirect::back()->withErrors([
                'purchase_code' => $response->message ?? 'An error occurred'
            ])->withInput();
        }
        unlink($zipFilePath);
        return Redirect::back()->withErrors([
            'purchase_code' => 'An error occurred: ' . $e->getMessage()
        ])->withInput();
    }

    protected function handleGeneralException(\Exception $e, $zipFilePath)
    {
        // Check if the file exists before unlinking
        if (file_exists($zipFilePath)) {
            unlink($zipFilePath);
        }
        
        return Redirect::back()->withErrors([
            'purchase_code' => 'An error occurred: ' . $e->getMessage()
        ])->withInput();
    }
}