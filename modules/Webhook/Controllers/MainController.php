<?php

namespace Modules\Webhook\Controllers;

use App\Http\Controllers\Controller as BaseController;
use App\Helpers\CustomHelper;
use Illuminate\Http\Request;
use Modules\Webhook\Models\Webhook;
use Modules\Webhook\Models\WebhookEvent; 
use Modules\Webhook\Requests\StoreWebhook;
use Modules\Webhook\Requests\UpdateWebhook;
use Modules\Webhook\Resources\WebhookResource;
use Inertia\Inertia;

class MainController extends BaseController
{
    public function index()
    {
        if(!CustomHelper::isModuleEnabled('Webhooks')){
            abort(404);
        }

        $data['apirequests'] = config('apiguide');
        $data['url'] = url('/');
        $data['rows'] = WebhookResource::collection(Webhook::with('events')->where('organization_id', session()->get('current_organization'))->paginate(10));
        $data['events'] = ['message.received', 'message.sent', 'message.status.update', 'contact.created', 'contact.updated', 'contact.deleted', 'group.created', 'group.updated', 'group.deleted', 'autoreply.created', 'autoreply.updated', 'autoreply.deleted'];

        return Inertia::render('Webhook::User/Index', $data);
    }

    public function store(StoreWebhook $request)
    {
        $webhook = Webhook::create([
            'url' => $request->url,
            'organization_id' => session()->get('current_organization'),
        ]);

        // Attach the events to the webhook
        foreach ($request->events as $event) {
            WebhookEvent::create([
                'webhook_id' => $webhook->id,
                'event' => $event,
            ]);
        }

        return back()->with(
            'status', [
                'type' => 'success', 
                'message' => __('Webhook url added successfully!')
            ]
        );
    }

    public function update(UpdateWebhook $request, $uuid)
    {
        // Find the existing webhook
        $webhook = Webhook::with('events')->where('uuid', $uuid)->firstOrFail();

        // Update the webhook URL
        $webhook->url = $request->url;
        $webhook->save();

        // Update the events
        // First, delete existing events
        $webhook->events()->delete();

        // Then, attach the new events
        foreach ($request->events as $event) {
            WebhookEvent::create([
                'webhook_id' => $webhook->id,
                'event' => $event,
            ]);
        }

        return back()->with(
            'status', [
                'type' => 'success', 
                'message' => __('Webhook url updated successfully!')
            ]
        );
    }

    public function destroy($uuid)
    {
        $webhook = Webhook::with('events')->where('uuid', $uuid)->firstOrFail();
        $webhook->events()->delete(); // Delete related events
        $webhook->delete();

        return back()->with(
            'status', [
                'type' => 'success', 
                'message' => __('Webhook url removed successfully!')
            ]
        );
    }

    public function test(Request $request, $event)
    {
        $webhookUrl = $request->url;

        if($event == 'message.received'){
            $this->sendNotification($webhookUrl, [
                "event" => "message.received",
                "data" => array (
                    'value' => 
                    array (
                        'messaging_product' => 'whatsapp',
                        'metadata' => 
                        array (
                            'display_phone_number' => '19680825846',
                            'phone_number_id' => '363351553535621',
                        ),
                        'contacts' => 
                        array (
                            0 => 
                            array (
                                'profile' => 
                                array (
                                    'name' => 'John',
                                ),
                                'wa_id' => '+19680825846',
                            ),
                        ),
                      'messages' => 
                        array (
                            0 => 
                            array (
                                'from' => '+19680825846',
                                'id' => 'wamid.HBgMMjU0NzIwMDU1ODE5FQIAEhggNjI4NDQ5NjhBQUUzQTU5OTg5NEEzRkM0RjkyNzNGNzQA',
                                'timestamp' => '1727857481',
                                'text' => 
                                array (
                                    'body' => 'Hi',
                                ),
                                'type' => 'text',
                            ),
                        ),
                    ),
                    'field' => 'messages',
                )  
            ]);
        } else if($event == 'message.status.update'){
            $this->sendNotification($webhookUrl, [
                "event" => "message.status.update",
                "data" => array (
                    'value' => 
                    array (
                        'messaging_product' => 'whatsapp',
                        'metadata' => 
                        array (
                            'display_phone_number' => '+19680825846',
                            'phone_number_id' => '363351553535621',
                        ),
                        'statuses' => 
                        array (
                            0 => 
                            array (
                            'id' => 'wamid.HBgMMjU0NzIwMDU1ODE5FQIAERgSMTFENTQ0MjU4RERFMjBDNzBEAA==',
                            'status' => 'delivered',
                            'timestamp' => '1727857139',
                            'recipient_id' => '19680825846',
                            'conversation' => 
                            array (
                                'id' => 'aa4aad1df7c69f44f351d10c6df391ee',
                                'origin' => 
                                array (
                                'type' => 'utility',
                                ),
                            ),
                            'pricing' => 
                            array (
                                'billable' => true,
                                'pricing_model' => 'CBP',
                                'category' => 'utility',
                            ),
                            ),
                        ),
                    ),
                    'field' => 'messages',
                )                    
            ]);
        } else if($event == 'message.sent'){
            $this->sendNotification($webhookUrl, [
                "event" => "message.sent",
                "data" => [
                    "data" => [
                        "success" =>true,
                        "data" => [
                            "messaging_product" => "whatsapp",
                            "contacts" => [["input" => "+19680825846","wa_id" => "+19680825846"]],
                            "messages" => [["id" => "wamid.HBgMMjU0NzIwMDU1ODE5FQIAERgSNkVFMjJENDY4RkZGOTUwRjFGAA==","message_status"=>"accepted"]],
                            "chat" => [
                                "organization_id" => 3,
                                "wam_id" => "wamid.HBgMMjU0NzIwMDU1ODE5FQIAERgSNkVFMjJENDY4RkZGOTUwRjFGAA==",
                                "contact_id" => 1451,
                                "type" => "outbound",
                                "metadata" => "{\"type\":\"text\",\"text\":{\"body\":\"Welcome and congratulations!! This message demonstrates your ability to send a WhatsApp message notification from the Cloud API, hosted by Meta. Thank you for taking the time to test with us.\",\"footer\":\"WhatsApp Business Platform sample message\"}}",
                                "media_id" => null,
                                "status" => "accepted",
                                "created_at" => "2024-10-02 06:29:47",
                                "uuid" => "64968a32-fdd9-4c1d-8941-b8e538036616",
                                "id" => 251,
                                "contact" => [
                                    "id" => 1451,
                                    "uuid" => "f012dd1c-2312-4a38-8e20-cf64c79bb9d2",
                                    "organization_id" => 3,
                                    "first_name" => "John",
                                    "last_name" => "Doe",
                                    "phone" => "+19680825846",
                                    "email" => null,
                                    "latest_chat_created_at" => "2024-10-02 06:29:47",
                                    "avatar" => null,
                                    "address" => "{\"street\":null,\"city\":null,\"state\":null,\"zip\":null,\"country\":null}",
                                    "metadata" => "{\"Gender\":null}",
                                    "contact_group_id" => null,
                                    "is_favorite" => 0,
                                    "ai_assistance_enabled" => 0,
                                    "created_by" => 6,
                                    "created_at" => "2024-09-27 09:19:49",
                                    "updated_at" => "2024-09-27 09:19:49",
                                    "deleted_at" => null,
                                    "full_name" => "John Doe",
                                    "formatted_phone_number" => "+1 (968) 082-5846"
                                ]
                            ]
                        ]
                    ]
                ]
            ]);
        } else if($event == 'contact.created'){
            $this->sendNotification($webhookUrl, [
                'event' => "contact.created",
                'data' => [
                    "first_name" => "John",
                    "last_name" => "Doe",
                    "email" => null,
                    "phone" => "+19680825846",
                    "created_at" => "2024-09-27 11:48:40",
                    "address" => "{\"street\":null,\"city\":null,\"state\":null,\"zip\":null,\"country\":null}",
                    "metadata" => "{\"Gender\":null}",
                    "updated_at" => "2024-09-27 11:48:40",
                    "uuid" => "81a2d165-41e2-43f2-847e-d69e08cba0e0",
                    "id" => 1449,
                    "full_name" => "John Doe",
                    "formatted_phone_number" => "+1 (968) 082-5846"
                ],
            ]);
        } else if($event == 'contact.updated'){
            $this->sendNotification($webhookUrl, [
                'event' => "contact.updated",
                'data' => [
                    "id" => 1449,
                    "uuid" => "81a2d165-41e2-43f2-847e-d69e08cba0e0",
                    "first_name" => "Jeff",
                    "last_name" => "Doe",
                    "phone" => "+19680825846",
                    "email" => null,
                    "latest_chat_created_at" => null,
                    "avatar" => null,
                    "address" => "{\"street\":null,\"city\":null,\"state\":null,\"zip\":null,\"country\":null}",
                    "metadata" => "{\"Gender\":null}",
                    "contact_group_id" => null,
                    "is_favorite" => 0,
                    "ai_assistance_enabled" => 0,
                    "created_at" => "2024-09-27 11:48:40",
                    "updated_at" => "2024-09-27 11:50:28",
                    "deleted_at" => null,
                    "full_name" => "Jeff Doe",
                    "formatted_phone_number" => "+1 (968) 082-5846"
                ],
            ]);
        } else if($event == 'contact.deleted'){
            $this->sendNotification($webhookUrl, [
                'event' => "contact.deleted",
                'data' => [
                    "id" => 1448,
                    "uuid" => "6f1a3cb7-1597-419f-847a-eaa8c2435a36",
                    "organization_id" => 3,
                    "first_name" => "John",
                    "last_name" => "Doe",
                    "phone" => "+19680825846",
                    "email" => null,
                    "latest_chat_created_at" => null,
                    "avatar" => null,
                    "address" => "{\"street\":null,\"city\":null,\"state\":null,\"zip\":null,\"country\":null}",
                    "metadata" => "{\"Gender\":null}",
                    "contact_group_id" => null,
                    "is_favorite" => 0,
                    "ai_assistance_enabled" => 0,
                    "created_by" => 6,
                    "created_at" => "2024-09-27 11:47:05",
                    "updated_at" => "2024-09-27 11:47:05",
                    "deleted_at" => null,
                    "full_name" => "John Doe",
                    "formatted_phone_number" => "+1 (968) 082-5846"
                ],
            ]);
        } else if($event == 'group.created'){
            $this->sendNotification($webhookUrl, [
                'event' => "group.created",
                'data' => [
                    "name" => "Lead 9",
                    "uuid" => "d746e4c5-db24-4fb2-be03-590421344d69",
                    "updated_at" => "2024-09-27T16:30:16.000000Z",
                    "created_at" => "2024-09-27T16:30:16.000000Z"
                ],
            ]);
        } else if($event == 'group.updated'){
            $this->sendNotification($webhookUrl, [
                'event' => "group.updated",
                'data' => [
                    "uuid" => "d3364ad4-fd92-47d7-9e6e-df7c2bf12164",
                    "name" => "Lead 9",
                    "created_at" => "2024-09-27T16:09:16.000000Z",
                    "updated_at" => "2024-09-27T16:09:16.000000Z",
                    "deleted_at" => null
                ],
            ]);
        } else if($event == 'group.deleted'){
            $this->sendNotification($webhookUrl, [
                'event' => "group.deleted",
                'data' => [
                    "uuid" => "d3364ad4-fd92-47d7-9e6e-df7c2bf12164",
                    "deleted_at" => "2024-09-27T16:10:40.006437Z"
                ],
            ]);
        } else if($event == 'autoreply.created'){
            $this->sendNotification($webhookUrl, [
                'event' => "autoreply.created",
                'data' => [
                    "name" => "About Us",
                    "trigger" => "what do you do?",
                    "match_criteria" => "contains",
                    "metadata" => "{\"type\":\"text\",\"data\":{\"text\":\"We sell shoes\"}}",
                    "updated_at" => "2024-09-27T16:31:18.192226Z",
                    "created_at" => "2024-09-27T16:31:18.192271Z",
                    "uuid" => "de6fe115-4c30-4c8d-9245-6ae31c1c820f"
                ],
            ]);
        } else if($event == 'autoreply.updated'){
            $this->sendNotification($webhookUrl, [
                'event' => "autoreply.updated",
                'data' => [
                    "uuid" => "de6fe115-4c30-4c8d-9245-6ae31c1c820f",
                    "name" => "About Us",
                    "trigger" => "what do you do?",
                    "match_criteria" => "contains",
                    "metadata" => "{\"type\":\"text\",\"data\":{\"text\":\"We sell shoes and clothes\"}}",
                    "deleted_by" => null,
                    "deleted_at" => null,
                    "created_at" => "2024-09-27 16:31:18",
                    "updated_at" => "2024-09-27T16:31:51.547181Z"
                ],
            ]);
        } else if($event == 'autoreply.deleted'){
            $this->sendNotification($webhookUrl, [
                'event' => "autoreply.deleted",
                'data' => [
                   "list" => [
                        "uuid" => "de6fe115-4c30-4c8d-9245-6ae31c1c820f",
                        "deleted_at" => "2024-09-27T16:32:26.673081Z"
                   ]
                ],
            ]);
        }

        return back()->with(
            'status', [
                'type' => 'success', 
                'message' => __('Test event has been sent successfully!')
            ]
        );
    }

    public function trigger($event, $organizationId, $data = [])
    {
        $webhooks = Webhook::where('organization_id', $organizationId)->whereHas('events', function ($query) use ($event) {
            $query->where('event', $event);
        })->get();

        foreach ($webhooks as $webhook) {
            // Send a notification to the webhook URL
            $this->sendNotification($webhook->url, [
                'event' => $event,
                'data' => $data,
            ]);
        }

        return response()->json(['message' => 'Notifications sent!'], 200);
    }

    protected function sendNotification($url, $payload)
    {
        // Use GuzzleHttp or Laravel HTTP client to send the request
        try {
            $response = \Http::withOptions([
                'curl' => [
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                    CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                ],
            ])->post($url, $payload);
            
            return $response;
        } catch (\Exception $e) {
            // Log the error or handle it as needed
            //\Log::error('Webhook notification error: ' . $e->getMessage());
            return response()->json(['error' => 'Notification failed'], 500);
        }
    }
}


