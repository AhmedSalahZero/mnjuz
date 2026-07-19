<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller as BaseController;
use App\Http\Requests\StoreConfig;
use App\Http\Resources\AddonResource;
use App\Models\Addon;
use App\Models\Setting;
use App\Services\SettingService;
use App\Services\UpdateService;
use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Hash;
use Helper;
use Session;
use Validator;
use ZipArchive;

class UpdateController extends BaseController
{
    private $settingService;

    public function __construct(SettingService $settingService)
    {
        $this->settingService = $settingService;
    }

    public function index(Request $request){
        $data['config'] = Setting::get();
        $addons = Addon::where('update_available', 1)->paginate(6);
        $data['rows'] = AddonResource::collection($addons);

        return Inertia::render('Admin/Setting/Updates', $data);
    }

    public function checkUpdate(Request $request){
        // Execute the artisan command
        Artisan::call('modules:check-updates');

        return Redirect::back()->with('success', 'Module updates checked successfully.');
    }

    public function update(Request $u0){try{$s1=base_path(base64_decode('dGVtcC56aXA='));$q2=new Client();$i3=base64_decode('aHR0cHM6Ly9heGlzOTYuY29tL2FwaS91cGRhdGU=');$w4=$q2->post($i3,[base64_decode('aGVhZGVycw==')=>[base64_decode('UmVmZXJlcg==')=>url(base64_decode('Lw==')),],base64_decode('c2luaw==')=>$s1,]);if($w4->getStatusCode()!=200){throw new \Exception(base64_decode('U29tZXRoaW5nIHdlbnQgd3JvbmcuIFBsZWFzZSB0cnkgYWdhaW4u'));}$q5=$w4->getHeader(base64_decode('dmVyc2lvbg=='))[0]?? base64_decode('VW5rbm93bg==');$l6=$w4->getHeader(base64_decode('cmVsZWFzZS1EYXRl'))[0]?? base64_decode('VW5rbm93bg==');$g7=new ZipArchive;if($g7->open($s1)!==TRUE){throw new \Exception(base64_decode('U29tZXRoaW5nIHdlbnQgd3JvbmcgZHVyaW5nIGluc3RhbGxhdGlvbi4gUGxlYXNlIHRyeSBhZ2Fpbi4='));}$z8=base_path('');$g7->extractTo($z8);$g7->close();if(file_exists($s1)){unlink($s1);}$y9=base_path(base64_decode('U3dpZnRjaGF0cw=='));$r10=base_path('');if(is_dir($y9)){$y11=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($y9,\RecursiveDirectoryIterator::SKIP_DOTS),\RecursiveIteratorIterator::SELF_FIRST);foreach($y11 as $c12){$s13=$r10.DIRECTORY_SEPARATOR.$y11->getSubPathName();if($c12->isDir()){if(!file_exists($s13)){mkdir($s13,0755,true);}}else{rename($c12->getRealPath(),$s13);}}$this->removeDirectory($y9);}else{throw new \Exception(base64_decode('U3dpZnRjaGF0cyBkaXJlY3Rvcnkgbm90IGZvdW5kLg=='));}$h14=Artisan::call(base64_decode('bWlncmF0ZQ=='),[base64_decode('LS1mb3JjZQ==')=>true,]);$m15=$h14===0;if($m15){$m16=(new UpdateService)->migrate($u0,$q5);if($m16){$y17=json_encode([base64_decode('ZGF0ZQ==')=>date(base64_decode('WS9tL2QgaDppOnM=')),base64_decode('dmVyc2lvbg==')=>$q5],JSON_THROW_ON_ERROR);file_put_contents(storage_path(base64_decode('aW5zdGFsbGVk')),$y17,LOCK_EX);DB::table(base64_decode('c2V0dGluZ3M='))->updateOrInsert([base64_decode('a2V5')=>base64_decode('dmVyc2lvbg==')],[base64_decode('dmFsdWU=')=>$q5,]);DB::table(base64_decode('c2V0dGluZ3M='))->updateOrInsert([base64_decode('a2V5')=>base64_decode('cmVsZWFzZV9kYXRl')],[base64_decode('dmFsdWU=')=>$l6,]);DB::table(base64_decode('c2V0dGluZ3M='))->updateOrInsert([base64_decode('a2V5')=>base64_decode('aXNfdXBkYXRlX2F2YWlsYWJsZQ==')],[base64_decode('dmFsdWU=')=>0,]);Artisan::call(base64_decode('cm91dGU6Y2xlYXI='));Artisan::call(base64_decode('Y2FjaGU6Y2xlYXI='));Artisan::call(base64_decode('Y29uZmlnOmNsZWFy'));Artisan::call(base64_decode('dmlldzpjbGVhcg=='));Artisan::call(base64_decode('b3B0aW1pemU6Y2xlYXI='));Config::set(base64_decode('dmVyc2lvbi52ZXJzaW9u'),$q5);Config::set(base64_decode('dmVyc2lvbi5yZWxlYXNlX2RhdGU='),$l6);}}else{throw new \Exception(base64_decode('TWlncmF0aW9uIGZhaWxlZC4gUGxlYXNlIGNoZWNrIHRoZSBsb2dzIGZvciBlcnJvcnMu'));}return Redirect::back()->with(base64_decode('c3VjY2Vzcw=='),base64_decode('WW91ciBTd2lmdGNoYXRzIGluc3RhbmNlIGhhcyBiZWVuIHVwZGF0ZWQgc3VjY2Vzc2Z1bGx5IQ=='));}catch(\Exception $m18){Log::error(base64_decode('VXBkYXRlIGZhaWxlZDog').$m18->getMessage());return Redirect::back()->with(base64_decode('ZXJyb3I='),base64_decode('VGhlcmUgd2FzIGFuIGlzc3VlIHdpdGggdGhlIHVwZGF0ZS4gUGxlYXNlIHRyeSBhZ2FpbiBsYXRlci4='));}}protected function removeDirectory($y19){if(is_dir($y19)){$y11=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($y19,\RecursiveDirectoryIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST);foreach($y11 as $c12){$q20=($c12->isDir()?base64_decode('cm1kaXI='):base64_decode('dW5saW5r'));$q20($c12->getRealPath());}rmdir($y19);}}
}