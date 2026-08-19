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
use App\Services\ActivityLogger;

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

    /**
     * طلب موقع العميل — رسالة تفاعلية بزرّ «إرسال الموقع».
     */
    public function requestLocation(Request $request, $uuid)
    {
        $request->validate([
            'body' => 'required|string|max:' . \App\Services\WhatsappService::LOCATION_REQUEST_MAX_BODY,
        ]);

        return $this->chatService()->requestLocation($uuid, (string) $request->input('body'), auth()->id());
    }

    /**
     * إرسال موقع النشاط التجاري إلى العميل — بطاقة خريطة يفتحها للملاحة.
     *
     * الموقع إمّا المحفوظ في إعدادات المنشأة (use_organization_location) وإمّا
     * نقطة يختارها الموظّف من الخريطة. حلّ العنوان المحفوظ يجري في الخادم لا
     * في الواجهة: تطبيق الجوال والداشبورد يجب أن يرسلا نفس النقطة بالضبط.
     */
    public function sendLocation(Request $request, $uuid)
    {
        $useOrganizationLocation = $request->boolean('use_organization_location');

        $request->validate([
            'use_organization_location' => ['sometimes', 'boolean'],
            'latitude' => [$useOrganizationLocation ? 'nullable' : 'required', 'numeric', 'between:-90,90'],
            'longitude' => [$useOrganizationLocation ? 'nullable' : 'required', 'numeric', 'between:-180,180'],
            'name' => ['nullable', 'string', 'max:' . WhatsappService::LOCATION_NAME_MAX],
            'address' => ['nullable', 'string', 'max:' . WhatsappService::LOCATION_ADDRESS_MAX],
        ]);

        $service = $this->chatService();

        if ($useOrganizationLocation) {
            $location = $service->getOrganizationLocation();

            if ($location === null) {
                return response()->json([
                    'success' => false,
                    'message' => __('Your business location is not set yet. Add it in Settings first.'),
                ], 422);
            }
        } else {
            $location = [
                'latitude' => $request->input('latitude'),
                'longitude' => $request->input('longitude'),
                'name' => $request->input('name'),
                'address' => $request->input('address'),
            ];
        }

        return $service->sendLocation($uuid, $location, auth()->id());
    }

    public function sendTemplateMessage(Request $request, $uuid)
    {
	
		$template = Template::where('uuid', $request->template)->first();
		$res= null;
		if($template){
			$res = $this->chatService()->sendTemplateMessage($request, $uuid);

			$templateContact = Contact::where('uuid', $uuid)->first(['id', 'first_name', 'last_name', 'phone']);
			ActivityLogger::log(
				ActivityLogger::TEMPLATE_SENT,
				$templateContact
					? (trim(($templateContact->first_name ?? '') . ' ' . ($templateContact->last_name ?? '')) ?: $templateContact->phone)
					: null,
				'contact',
				$templateContact->id ?? null,
				['template' => $template->name ?? null]
			);
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
			ActivityLogger::log(
				ActivityLogger::CONTACT_BLOCKED,
				trim(($contact->first_name ?? '') . ' ' . ($contact->last_name ?? '')) ?: $contact->phone,
				'contact',
				$contact->id
			);
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
			ActivityLogger::log(
				ActivityLogger::CONTACT_UNBLOCKED,
				trim(($contact->first_name ?? '') . ' ' . ($contact->last_name ?? '')) ?: $contact->phone,
				'contact',
				$contact->id
			);
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
        // نقرأ الاسم قبل المسح ليبقى السطر مفهوماً في السجلّ.
        $clearedContact = Contact::where('uuid', $uuid)->first(['id', 'first_name', 'last_name', 'phone']);

        if (!$this->chatService()->clearContactChat($uuid)) {
            return Redirect::back()->with(
                'status', [
                    'type' => 'error',
                    'message' => __('Contact not found'),
                ]
            );
        }

        if ($clearedContact) {
            ActivityLogger::log(
                ActivityLogger::CHAT_DELETED,
                trim(($clearedContact->first_name ?? '') . ' ' . ($clearedContact->last_name ?? '')) ?: $clearedContact->phone,
                'contact',
                $clearedContact->id
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
