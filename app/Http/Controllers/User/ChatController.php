<?php
namespace App\Http\Controllers\User;

use App\Helpers\DateTimeHelper;
use App\Http\Controllers\ApiController;
use App\Http\Controllers\Controller as BaseController;
use App\Models\AutoReply;
use App\Models\Chat;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Template;
use App\Services\ChatService;
use App\Services\ContactService;
use App\Services\PhoneService;
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

    public function markAsRead(Request $request, $uuid)
    {
        $marked = $this->chatService()->markContactAsReadByUuid($uuid);

        return response()->json(['success' => $marked]);
    }

    /**
     * يفتح محادثة داخلية اعتمادًا على رقم الهاتف (من بطاقة جهة اتصال واردة مثلاً).
     * يبحث عن جهة الاتصال داخل المؤسسة الحالية أو ينشئها ثم يعيد الـ uuid لفتح /chats/{uuid}.
     */
    public function openByPhone(Request $request)
    {
        $request->validate([
            'phone' => ['required', 'string', 'max:255'],
        ]);

        if (!PhoneService::isValid($request->input('phone'))) {
            return response()->json([
                'success' => false,
                'message' => __('The phone number is not valid.'),
            ], 422);
        }

        $organizationId = session()->get('current_organization');
        $contactService = new ContactService($organizationId);

        $contact = $contactService->findOrCreateByPhone($request->input('phone'), array_filter([
            'first_name' => $request->input('first_name'),
            'last_name' => $request->input('last_name'),
        ]));

        return response()->json([
            'success' => true,
            'uuid' => $contact->uuid,
        ]);
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
	public function sendAuthTemplate(Request $request, $uuid)
	{
		$organizationId = session()->get('current_organization');
		$organization = Organization::find($organizationId);
		$metadata = $organization->metadata ? json_decode($organization->metadata, true) : [];
		$templateUUID = $metadata['auth_template'] ?? null;
		$template = Template::where('uuid', $templateUUID)->first();

		if(!$template){
			return response()->json([
				'statusCode' => 404,
				'success' => false,
				'message' => __('Auth Template not found! .. Please Go To Settings -> General -> Auth Template To Edit It'),
			], 400);
		}
		$contact = Contact::where('uuid', $uuid)->first();
		$request->merge(['template_uuid' => $template->uuid, 'phone' => $contact->phone]);

		// Pass saved auth template variables so the API can build template components (body/header/buttons params).
		$authParams = $metadata['auth_template_parameters'] ?? null;
		if ($authParams && isset($authParams['template']) && $authParams['template'] === $template->uuid) {
			$request->merge(['template_parameters' => $authParams]);
		}
		
		$res = (new ApiController)->sendTemplateMessageByUUID($request);
		return json_decode($res->getContent(), true);
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
        if (!$this->chatService()->clearContactChat($uuid)) {
            return Redirect::back()->with(
                'status', [
                    'type' => 'error',
                    'message' => __('Contact not found'),
                ]
            );
        }

        return Redirect::back()->with(
            'status', [
                'type' => 'success', 
                'message' => __('Chat cleared successfully!')
            ]
        );
    }

    public function loadMoreMessages(Request $request, $contactId)
    {
        $page = $request->query('page', 1);
        $messages = $this->chatService()->getChatMessages($contactId, $page);
        
        return response()->json($messages);
    }

    public function loadMoreContacts(Request $request)
    {
        return $this->chatService()->getContactsPage($request);
    }
}
