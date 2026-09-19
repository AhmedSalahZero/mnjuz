<?php

namespace App\Http\Controllers\User;

use DB;
use App\Http\Controllers\Controller as BaseController;
use App\Helpers\CustomHelper;
use App\Http\Requests\StoreAutoReply;
use App\Models\Addon;
use App\Models\AutoReply;
use App\Models\Setting;
use App\Services\AutoReplyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Helper;
use App\Services\ActivityLogger;
use App\Services\ContactPlaceholderService;

class CannedReplyController extends BaseController
{
    private $autoReplyService;

    public function __construct(AutoReplyService $autoReplyService)
    {
        $this->autoReplyService = $autoReplyService;
    }

    public function index(Request $request){
        $rows = $this->autoReplyService->getRows($request);
        $aimodule = CustomHelper::isModuleEnabled('AI Assistant');
        $fbmodule = CustomHelper::isModuleEnabled('Flow builder');

        return Inertia::render('User/Automation/Basic/Index', [ 
            'title' => __('Canned replies'), 
            'allowCreate' => true, 
            'rows' => $rows, 
            'filters' => request()->all(), 
            'aimodule' => $aimodule,
            'fbmodule' => $fbmodule,
        ]);
    }

    public function create(){
        $data['title'] = __('Canned replies');
        $data['placeholders'] = ContactPlaceholderService::optionsForOrganization(
            (int) session()->get('current_organization')
        );

        return Inertia::render('User/Automation/Basic/Create', $data);
    }

    public function store(StoreAutoReply $request){
        $this->autoReplyService->store($request);

        ActivityLogger::log(ActivityLogger::AUTO_REPLY_UPDATED, (string) $request->input('name'), 'auto_reply');

        return Redirect::route('cannedReply.create')->with(
            'status', [
                'type' => 'success', 
                'message' => __('Data added successfully!')
            ]
        );
    }

    public function edit($uuid){
        $data['title'] = __('Canned replies');
        $data['autoreply'] = AutoReply::where('uuid', $uuid)->first();
        // كانت هنا نسخة ثالثة من الكود نفسه تُسقط {url:...} للحقول المخصّصة،
        // فيرى المستخدم في التعديل متغيّرات أقلّ ممّا رآه في الإنشاء.
        $data['placeholders'] = ContactPlaceholderService::optionsForOrganization(
            (int) session()->get('current_organization')
        );

        return Inertia::render('User/Automation/Basic/Edit', $data);
    }

    public function update(StoreAutoReply $request, $uuid){
        $this->autoReplyService->store($request, $uuid);

        ActivityLogger::log(ActivityLogger::AUTO_REPLY_UPDATED, (string) $request->input('name'), 'auto_reply');

        return Redirect::route('cannedReply.edit', $uuid)->with(
            'status', [
                'type' => 'success', 
                'message' => __('Data updated successfully!')
            ]
        );
    }

    public function delete($uuid)
    {
        $this->autoReplyService->destroy($uuid);

        ActivityLogger::log(ActivityLogger::AUTO_REPLY_UPDATED, null, 'auto_reply');

        return Redirect::back()->with(
            'status', [
                'type' => 'success', 
                'message' => __('Row deleted successfully!')
            ]
        );
    }
}