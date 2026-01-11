<?php

namespace Modules\IntelliReply\Controllers;

use App\Http\Controllers\Controller as BaseController;
use App\Helpers\CustomHelper;
use Illuminate\Http\Request;
use App\Models\Addon;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Setting;
use Inertia\Inertia;
use Modules\IntelliReply\Models\Document;
use Modules\IntelliReply\Resources\DocumentResource;
use Modules\IntelliReply\Services\AIResponseService;

class MainController extends BaseController
{
    public function index(Request $request){
        if(!CustomHelper::isModuleEnabled('AI Assistant')){
            abort(404);
        }

        $organizationId = session()->get('current_organization');
        $model = new Document;
        $searchTerm = $request->query('search');

        $data['title'] = __('Settings');
        $data['rows'] = DocumentResource::collection($model->listAll($organizationId, $searchTerm));
        $data['settings'] = Organization::where('id', $organizationId)->first();
        $data['timezones'] = config('formats.timezones');
        $data['countries'] = config('formats.countries');
        $data['sounds'] = config('sounds');
        $data['models'] = config('models');
        $data['voices'] = config('voices');
        $data['filters'] = request()->all();
        $data['aimodule'] = CustomHelper::isModuleEnabled('AI Assistant');
        $data['fbmodule'] = CustomHelper::isModuleEnabled('Flow builder');
        $keys = ['enable_api_key_input', 'enable_model_selection', 'default_open_ai_text_model', 'default_open_ai_audio_model'];
        $data['appSettings'] = Setting::whereIn('key', $keys)->get();
        //dd($data);

        return Inertia::render('IntelliReply::User/Index', $data);
    }

    public function activate(Request $request){
        $organizationId = session()->get('current_organization');
        $organizationConfig = Organization::where('id', $organizationId)->first();

        $metadataArray = $organizationConfig->metadata ? json_decode($organizationConfig->metadata, true) : [];
        $metadataArray['ai']['active'] = $request->active;

        $updatedMetadataJson = json_encode($metadataArray);
        $organizationConfig->metadata = $updatedMetadataJson;

        if($organizationConfig->save()){
            return back()->with(
                'status', [
                    'type' => 'success', 
                    'message' => $request->active ? __('AI assistant activated') : __('AI assistant deactivated')
                ]
            );
        } else {
            return back()->with(
                'status', [
                    'type' => 'error', 
                    'message' => __('Something went wrong. Refresh the page and try again')
                ]
            );
        }
    }

    public function assistant_setup(Request $request){
        $organizationId = session()->get('current_organization');
        $organizationConfig = Organization::where('id', $organizationId)->first();

        $metadataArray = $organizationConfig->metadata ? json_decode($organizationConfig->metadata, true) : [];
        $metadataArray['ai']['enable_automatic_responses'] = $request->enable_automatic_responses;
        $metadataArray['ai']['start_keywords'] = $request->start_keywords;
        $metadataArray['ai']['stop_keywords'] = $request->stop_keywords;

        $updatedMetadataJson = json_encode($metadataArray);
        $organizationConfig->metadata = $updatedMetadataJson;

        if($organizationConfig->save()){
            return back()->with(
                'status', [
                    'type' => 'success', 
                    'message' => __('Settings updated successfully')
                ]
            );
        } else {
            return back()->with(
                'status', [
                    'type' => 'error', 
                    'message' => __('Something went wrong. Refresh the page and try again')
                ]
            );
        }
    }

    public function enable_ai_assistant(Request $request, $uuid){
        Contact::where('uuid', $uuid)->update([
            'ai_assistance_enabled' => $request->ai_assistant
        ]);

        return back()->with(
            'status', [
                'type' => 'successs', 
                'message' => __('AI assistant updated successfully!')
            ]
        );
    }

    public function setup(Request $request){
        $organizationId = session()->get('current_organization');
        $organizationConfig = Organization::where('id', $organizationId)->first();

        $metadataArray = $organizationConfig->metadata ? json_decode($organizationConfig->metadata, true) : [];
        $metadataArray['ai']['active'] = $request->active;
        $metadataArray['ai']['platform'] = "OpenAI";
        $metadataArray['ai']['model'] = $request->model;
        $metadataArray['ai']['max_tokens'] = $request->max_tokens;
        $metadataArray['ai']['temperature'] = $request->temperature;
        $metadataArray['ai']['api_key'] = $request->api_key;
        $metadataArray['ai']['allow_audio_response'] = $request->allow_audio_response;
        $metadataArray['ai']['voice'] = $request->voice;
        $metadataArray['ai']['ai_chat_form_active'] = $request->ai_chat_form_active;

        $updatedMetadataJson = json_encode($metadataArray);
        $organizationConfig->metadata = $updatedMetadataJson;

        if($organizationConfig->save()){
            return back()->with(
                'status', [
                    'type' => 'success', 
                    'message' => __('Open AI settings updated successfully')
                ]
            );
        } else {
            return back()->with(
                'status', [
                    'type' => 'error', 
                    'message' => __('Something went wrong. Refresh the page and try again')
                ]
            );
        }
    }

    public function chat_suggestion(Request $request){
        try {
            $contactUuId = $request->contact;
            $contact = Contact::where('uuid', $contactUuId)->first();
            $organizationId = session()->get('current_organization');

            $aiService = new AIResponseService();
            $response = $aiService->processResponse(false, $organizationId, $contact->id);
            
            return response()->json([
                'success' => true,
                'data' => $response
            ]);
    
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate AI response: ' . $e->getMessage()
            ], 500);
        }
    }
}


