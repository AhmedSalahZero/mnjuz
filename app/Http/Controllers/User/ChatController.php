<?php
namespace App\Http\Controllers\User;

use App\Helpers\DateTimeHelper;
use App\Http\Controllers\Controller as BaseController;
use App\Models\AutoReply;
use App\Models\Chat;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Template;
use App\Services\ChatService;
use App\Services\WhatsappService;
use DateTime;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Redirect;

class ChatController extends BaseController
{
    private function chatService()
    {
        return new ChatService(session()->get('current_organization'));
    }

    public function index(Request $request, $uuid = null)
    {
        return $this->chatService()->getChatList($request, $uuid, $request->query('search'));
    }

    public function updateChatSortDirection(Request $request)
    {
		
        $request->session()->put('chat_sort_direction', $request->sort);

        return Redirect::back();
    }

    public function sendMessage(Request $request)
    {
        return $this->chatService()->sendMessage($request);
    }

    public function sendTemplateMessage(Request $request, $uuid)
    {
	
		$template = Template::where('uuid', $request->template)->first();
		$res= null;
		if($template){
			$res = $this->chatService()->sendTemplateMessage($request, $uuid);
		}else{
			return Redirect::back()->with(
            'status', [
                'type' => 'error', 
                'message' => __('Template not found!'),
                'res' => null
            ]
        );
		}

        return Redirect::back()->with(
            'status', [
                'type' => $res->success === true ? 'success' : 'error', 
                'message' => $res->success === true ? __('Message sent successfully!') : $res->message,
                'res' => $res
            ]
        );
    }
	
	public function blockContact(Request $request, $contactId)
    {
		
		$contact = Contact::find($contactId);
		$organization = Organization::find($request->get('organization'));
		$res= null;
		if($contact){
			$res = $this->chatService()->blockContact($organization,$contact);
		}else{
			return Redirect::back()->with(
            'status', [
                'type' => 'error', 
                'message' => __('Contact Not Found'),
                'res' => null
            ]
        );
		}
        return Redirect::back()->with(
            'status', [
                'type' => $res['status'] ? 'success' : 'error', 
                'message' => $res['message'],
                'res' => null
            ]
        );
    }
	public function unblockContact(Request $request, $contactId)
    {
		
		$contact = Contact::find($contactId);
		$organization = Organization::find($request->get('organization'));
		$res= null;
		if($contact){
			$res = $this->chatService()->unblockContact($organization,$contact);
		}else{
			return Redirect::back()->with(
            'status', [
                'type' => 'error', 
                'message' => __('Contact Not Found'),
                'res' => null
            ]
        );
		}
        return Redirect::back()->with(
            'status', [
                'type' => $res['status'] ? 'success' : 'error', 
                'message' => $res['message'],
                'res' => null
            ]
        );
    }
    public function deleteChats($uuid)
    {
        $this->chatService()->clearContactChat($uuid);

        return Redirect::back()->with(
            'status', [
                'type' => 'success', 
                'message' => __('Chat cleared successfully!')
            ]
        );
    }

    public function loadMoreMessages(Request $request, $contactId)
    {
	//	logger('from load more messages');
        $page = $request->query('page', 1);
        $messages = $this->chatService()->getChatMessages($contactId, $page);
        
        return response()->json($messages);
    }
}
