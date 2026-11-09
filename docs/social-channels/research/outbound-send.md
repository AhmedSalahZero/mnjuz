# Subsystem: outbound-send


## summary

The outbound-send subsystem is the path from "agent/API/job wants to send something to a contact" to "a `chats` row + `chat_logs` row (+ `chat_media`) exists, a `NewChatEvent` is broadcast on `chats.ch{org}.{user}`, and a `message.sent` webhook fires". Every entry point (web `ChatController` → `ChatService`, mobile/API `ApiController`, chunked-upload controllers, `SendTextMessageJob`, `SendMediaJob`, `RetryMediaWithTranscodeJob`, plus campaigns/flows/auto-replies outside this subsystem) constructs a `WhatsappService` from `organizations.metadata.whatsapp` (`access_token`, `app_id`, `phone_number_id`, `waba_id`) and calls one of three methods: `executeSendMessage` (text/interactive/location), `sendMedia` (link-based media), or `sendTemplateMessage`. All three build a Meta Graph `/{phone_number_id}/messages` payload with `to = contacts.phone`, POST it via Guzzle, and only on success persist the chat and broadcast. There is no channel abstraction anywhere: 19 call sites `new WhatsappService(...)` directly, the 24-hour window is a global constant, `contacts.phone` non-empty is a hard precondition (`isSendableNumber`) in every send path, and the persistence/broadcast block is copy-pasted inside each `WhatsappService` send method rather than shared. The good news: the persistence layer (`Chat`, `ChatLog`, `ChatMedia`, `ChatStatusLog`, `NewChatEvent`/`ChatBroadcastPayloadBuilder`, `ChunkedUploadService`, `getChatMessages`, access control, ticket handling) is already channel-agnostic; what must change is the driver selection, the guards, and extracting the recorder so new drivers reuse it.

## current_flow

## A. Web dashboard text/media send
1. `routes/web.php:177` `POST /chats` → `app/Http/Controllers/User/ChatController.php:82-86` `sendMessage()` → `ChatService::sendMessage()` (`ChatService` built with `session('current_organization')`, `ChatController.php:25-28`).
2. `app/Services/ChatService.php:688-823` `sendMessage()`:
   - `:690` `initializeWhatsappService()` (`:59-72`) reads `Organization.metadata` JSON → `whatsapp.access_token / app_id / phone_number_id / waba_id`, `config('graph.api_version')`, `new WhatsappService(...)`.
   - `:691` contact by uuid+org; `:699` `assertConversationAccess` (`:74-111`: agents only see assigned ticket when ticketing active).
   - `:701-703` `MessagingWindowHelper::isMessagingWindowOpen` → 422 JSON if closed.
   - `:705-712` `ActivityLogger::log(MEDIA_SENT|MESSAGE_SENT, name ?: phone, ...)`.
   - `:717-719` multiple files → `queueMediaBatch()` (`:835-920`): per file `ImageCompressionService::prepareForWhatsapp` (`:873`), write `temp/send-media/...` on `local` disk (`:895`), build `SendMediaJob(..., continueBatchOnFailure: true)` (`:897-910`), `Bus::chain($chain)->onQueue('high')` (`:917`), returns `{success:true, queued:n}`.
   - `:721-812` single file: `prepareForWhatsapp` (`:736`); if `tempMessageId` present → store temp file + `SendMediaJob::dispatch(...)->onQueue('high')` and return `null` (`:755-772`); else synchronous: video remux via `VideoTranscodeService::transcodeForWhatsapp` (`:777-787`), store to `local` (`public/...` → URL `APP_URL/media/...`) or `s3` (`uploads/media/sent/{org}/...`, ContentType from `whatsapp_media_content_type`) (`:789-803`), `WhatsappService::sendMedia(...)` (`:805`).
   - `:814-822` text: `WhatsappService::sendMessage($contact->uuid, $message, auth()->id())`.
3. `app/Services/WhatsappService.php:177-217` `sendMessage()`: reads `Request()->get('tempMessageId')` and `'msg_uuid'` (`:179-180`), normalizes text (`:796-805`); if `tempMessageId` set and type ≠ location → `SendTextMessageJob::dispatch(...)->onQueue('high')` and return `null` (`:187-202`); else `executeSendMessage()`.
4. `WhatsappService.php:223-351` `executeSendMessage()`: contact by uuid+org (`:236-238`); `isSendableNumber` guard (`:243-251`); empty-text guard (`:254`); location guard (`:260`); URL `https://graph.facebook.com/{v}/{phone_number_id}/messages` (`:264`); payload `messaging_product=whatsapp, recipient_type=individual, to=$contact->phone` (`:267-269`) + type branch text (`:270-273`) / location (`:274-279`) / location_request (`:280-284`) / interactive (`:285-294`, `buildInteractivePayload` `:2262-2304`); `sendHttpRequest` (`:2331-2371`). On success: build stored metadata (`:302-317`), `Chat::create` with `wam_id = data->messages[0]->id`, `type=outbound`, `status='delivered'` (`:318-327`); optionally set `uuid = msg_uuid` if not taken (`:328-331`, `isAssignableMessageUuid` `:869-885`); reload with contact+media and attach to `$responseObject->data->chat` (`:333-334`); `ChatLog::insertGetId(entity_type='chat')` (`:336-341`); `event(new NewChatEvent([[type=>'chat', value=>$chatLog->relatedEntities, tempMessageId]], $orgId))` (`:343-345`). Always: `WebhookHelper::triggerWebhookEvent('message.sent', ['data'=>$responseObject], $orgId)` (`:348`). Returns the response object.
5. `Chat::created` observer (`app/Models/Chat.php:21-32`) updates `contacts.latest_chat_created_at` (and `last_inbound_chat_created_at` for inbound).

## B. Queued text (`app/Jobs/SendTextMessageJob.php`)
- Props `:17-27`; `handle()` `:55-90`: `Organization::find` (return if missing), reads `metadata.whatsapp.*` (`:62-67`), `new WhatsappService` (`:69-76`), `executeSendMessage(...)` (`:78-89`). No window check inside the job (checked by caller). Queue name is set by dispatcher (`high`).

## C. Queued media (`app/Jobs/SendMediaJob.php`)
- Ctor `:26-45` (promoted props incl. `continueBatchOnFailure` `:44`), `$timeout=300` (`:24`).
- `handle()` `:47-206`: temp file must be readable (`:48-51`); video remux (`:55-70`); write final file to `local`/`s3` (`:74-96`, `whatsapp_media_content_type` `:88`); org (`:104`); contact by uuid+org **`whereNull('deleted_at')`** (`:111-120`); `isSendableNumber` (`:128`); `MessagingWindowHelper::isMessagingWindowOpen` (`:141`); `metadata.whatsapp.*` → `new WhatsappService` (`:147-161`); `sendMedia(...)` (`:165-177`); on failure: if `continueBatchOnFailure` log & return (`:185-197`) else `throw RuntimeException` so the queue retries with the temp file kept (`:200-203`); `failed()` deletes temp (`:212`).
- Dispatched from: `ChatService.php:759,897`, `User/ChunkedUploadController.php:122-132`, `Api/ChunkedUploadController.php:198-208`.

## D. `WhatsappService::sendMedia()` `:887-1047`
- contact by uuid+org (`:890-892`), `isSendableNumber` (`:894-903`); payload `to=phone, type=$mediaType, {$mediaType}.link=$mediaUrl`, `filename` for document (`:914-916`), `caption` unless audio (`:918-920`); POST (`:922`). Success: `wamId` (`:928`), `getContentTypeFromUrl` (HTTP HEAD, `:1074-1094`) → `whatsapp_acceptable_content_type` (`:930`), `formatMediaResponse` (`:1096-1114`), `getMediaSizeInBytesFromUrl` (**downloads the whole file**, `:1116-1132`). Two branches:
  - `$existingChatId` (retry path, `:934-977`): `Chat.update(wam_id, status='sent', metadata)`, `ChatMedia.update`, `ChatStatusLog::create({id, status:'sent'})` (`:953-960`), `NewChatEvent(..., statusChanged=true)` (`:975`).
  - create (`:979-1023`): `Chat::create(status='sent')`, `ChatLog`, `ChatMedia::create(name, path=URL, location, type=mime, size)`, `Chat.update(media_id, uuid?)`, `NewChatEvent` with `tempMessageId`.
- Failure logged with `describeSendError` (`:1030-1039`, `:1057-1072`). Webhook `message.sent` always (`:1042-1044`).

## E. Template send (web)
- `routes/web.php:194` → `ChatController::sendTemplateMessage` `:142-177` → `ChatService::sendTemplateMessage` `:938-1028`: header media upload → `ChatMedia` (`:954-1014`), `buildTemplate()` (`app/Traits/TemplateTrait.php:30-59`) → `WhatsappService::sendTemplateMessage($uuid, $template, userId, null, $mediaId)` (`:1027`); flash redirect back with `{type, message, res}`.
- `WhatsappService::sendTemplateMessage` `:461-543`: closure; `isSendableNumber` (`:470-478`); payload `type=template, template=$templateContent` (`:484-488`); on success `Chat::create` with metadata from `buildCampaignTemplateChatMessage` (campaign, `:557-640`) or `buildTemplateChatMessage` (`:642-755`), `media_id`, `status = messages[0].message_status ?? 'sent'` (`:498-508`); `ChatLog`, `NewChatEvent` (`:513-526`); webhook (`:530`). If `$sendByQueue` the closure itself is `dispatch()`ed to queue `high` (`:536-537`) — a serialized closure job.
- Auth template: `ChatController::sendAuthTemplate` `:178-204` reads `metadata.auth_template` + `auth_template_parameters`, merges `phone = $contact->phone` and calls `ApiController::sendTemplateMessageByUUID` (`:202`).

## F. Location
- `routes/web.php:196-197` → `ChatController::requestLocation` `:91-98` / `sendLocation` `:107-140` → `ChatService::requestLocation` `:549-589` / `sendLocation` `:596-631` (window + `isUsableLocation` guards, `ActivityLogger`) → `WhatsappService::sendMessage(type=TYPE_LOCATION_REQUEST)` / `sendLocation()` `:1175-1188` → `executeSendMessage(type=location)`.
- Mobile: `ApiController::requestLocation` `:2486-2522`, `sendLocation` `:2530-2591`, `organizationLocation` `:2597-2609` (window checked in controller to return `statusCode` shape, then delegates to `ChatService`).

## G. Mobile / public API (`app/Http/Controllers/ApiController.php`, `routes/api.php:43-45,114-140`)
- `sendMessage` `:931-1017` (`POST /api/send`, and `/api/v1/send-msg` with `type=text` via `sendMsg` `:1026-1037`): mobile org from `user.current_mobile_organization_id` + forces `tempMessageId=-1` to queue (`:934-939`); validates `phone` with `PhoneService::isValid` (`:941-945`); subscription (`:960`); `whatsappNotConnectedResponse` (`:969`, → `WhatsappConnectionHelper::errorFor`, `:1584-1607`); `resolveContactByPhone` (`:973`, `:1526-1544` → `ContactService::findOrCreateByPhone`, creates the contact if missing); window (`:975-977`); `initializeWhatsappService` (`:979`, `:1609-1621`); type `text` or `interactive buttons` if `buttons` present (`:980`); `sendMessage(uuid, message, userId=0, ...)` (`:988`); response `:1001-1016`.
- `sendFileMessage` `:1382-1524` (via `/api/v1/send-msg` with files): `normalizeUploadedFiles` (`:1050-1078`), org from mobile column (`:1388`), mimes whitelist (`:1395`), per-file size via `ChatMediaUploadHelper` (`:1424-1437`), subscription/connection/contact/window (`:1438-1457`), merges `uuid, types, tempMessageIds (generated), msg_uuid, caption` (`:1476-1489`), rebuilds request (`:1495`) and calls `ChatService::sendMessage` (`:1497`) → batch chain; response `:1512-1523`.
- `sendTemplateMessage` `:1079-1132` (`POST /api/send/template`, synchronous), `sendTemplateMessageByUUID` `:1159-1236` (`/api/v1/send-template`, `sendByQueue=true`), `sendAuthTemplate` `:1243-1307` (`/api/v1/send-auth-template`).
- `sendMediaMessage` `:1309-1377` (`POST /api/send/media`, link-based, synchronous, hardcodes `location='amazon'` `:1368`).
- Chunked upload: `app/Http/Controllers/Api/ChunkedUploadController.php` (`/api/v1/chats/upload/chunk`) — phone validation `:48-52`, subscription `:83`, `WhatsappConnectionHelper` `:87`, contact by phone `:91/:226-238`, window `:97`, store chunk `:107`, `finish()` `:163-224` → `ChunkedUploadService::assemble` → `SendMediaJob::dispatch` (`:198-208`). Web counterpart `app/Http/Controllers/User/ChunkedUploadController.php` (`/chats/upload/chunk`): contact by uuid `:47-50`, `finish()` `:92-135` → `SendMediaJob` (`:122-132`). `app/Services/Chat/ChunkedUploadService.php` is pure file plumbing (`temp/chunked-uploads/{org}/{user}/{id}` → `temp/send-media/{id}.{ext}`, `:39-42,82-116`).

## H. Status-driven retry (`app/Jobs/RetryMediaWithTranscodeJob.php`)
- Dispatched from `app/Jobs/ProcessMessageStatusJob.php:121-123` when a failed status carries codes `131053/131052` (`:26`, `shouldRetryForChat` `:42-69`). `handle()` `:71-165` marks `metadata.transcode_retry_count=1, transcode_retry_status='retrying'` (`:95-96`), re-encodes, re-stores, `new WhatsappService` (`:129-136`), `sendMedia(..., existingChatId=$chat->id)` (`:139-152`), `markRetryFailed` sets `status='failed'` (`:167-175`).

## I. Dead / unused in this subsystem
- `app/Jobs/ProcessMediaUploadJob.php` — no dispatcher anywhere (grep of `app/`, `modules/`, `routes/`); builds S3 URL from `env('AWS_BUCKET')` (`:90`).
- `app/Services/MediaService.php` — `upload()` references undefined `$contact` (`:28`); imported in `ApiController.php:32` but not called by the send methods read.
- `app/Http/Controllers/User/MessageController.php` — only renders `User/Message/Index` (`:12-14`); nothing to do with sending.
- `routes/web.php:200` `/chat/test/{id}` → `ChatController::sendAutoReply` which does not exist.

## J. Queues / events summary
- All sends use queue `high` (`ChatService.php:769,917`, `WhatsappService.php:200,537`, both ChunkedUploadControllers). `NewChatEvent` also queues on `high` (`app/Events/NewChatEvent.php:38`) and broadcasts on presence channels `chats.ch{org}.{userId}` (`:104-129`). DB writes per successful send: `chats`, `chat_logs`, (`chat_media`), (`chat_status_logs` on retry path), `contacts.latest_chat_created_at` via observer.

## whatsapp_assumptions

## Service construction / credentials
- `app/Services/ChatService.php:59-72` — reads `metadata.whatsapp.{access_token,app_id,phone_number_id,waba_id}` + `config('graph.api_version')` and builds `WhatsappService`; every ChatService send path calls it (`:551,:598,:690,:940`). A non-WA contact has no such config.
- `app/Jobs/SendTextMessageJob.php:62-76`, `app/Jobs/SendMediaJob.php:147-161`, `app/Jobs/RetryMediaWithTranscodeJob.php:129-136`, `app/Jobs/ProcessMediaUploadJob.php:64-70`, `app/Http/Controllers/ApiController.php:1609-1621` — same `metadata['whatsapp']` → `new WhatsappService` copy-paste (19 sites app-wide, see caller census: `SettingController`, `SendCampaignJob:374`, `ProcessSingleCampaignLogJob:289`, `RetryCampaignLogJob:285`, `AutoReplyService:378`, `ConversationRatingService:285`, `TemplateService:36`, `SyncIceBreakersToMeta:62`, `modules/FlowBuilder/.../ActionExecutionService:38`, `FlowExecutionService:39`, `modules/IntelliReply/.../AIResponseService:427`).
- `app/Helpers/WhatsappConnectionHelper.php:26,36` — "connected" means `metadata.whatsapp` has `access_token, phone_number_id, waba_id`; used by `ApiController::whatsappNotConnectedResponse` (`:969,1116,1211,1348,1447`) and `Api/ChunkedUploadController.php:87`. New channels need their own connection check.
- `app/Services/WhatsappService.php:2238-2244` `setHeaders()` Bearer token; `:2331-2371` `sendHttpRequest` returns `{success, data, message?}` where `data` is the raw Graph JSON.

## Recipient identity = phone
- `WhatsappService.php:85-88` `isSendableNumber()` = `trim(contact->phone) !== ''`; enforced at `:243-251` (text), `:470-478` (template), `:894-903` (media) and `SendMediaJob.php:128`. New-channel contacts (phone NULL by decision) are refused with "This contact has no phone number." on every path.
- `WhatsappService.php:269,:486,:911,:1149` — `to = $contact->phone`; `:284` passes `$contact->phone` to `buildLocationRequestPayload`.
- `WhatsappService.php:816` `getParameters('phone')` → `contact->phone` for template placeholders.
- `ChatController.php:47-72` `openByPhone` (`PhoneService::isValid`, `findOrCreateByPhone`) — web "open chat by phone" cannot open a Telegram/Messenger contact.
- `ChatController.php:194` auth template merges `phone = $contact->phone` and `ApiController::sendTemplateMessageByUUID` validates `phone` required (`:1162-1166`).
- `ApiController.php:941-945, 1082-1086, 1162-1166, 1246-1250, 1317-1321, 1398-1402` — every API send validates `phone` `required` + `PhoneService::isValid`; `resolveContactByPhone` (`:1526-1544`) creates contacts by phone. `Api/ChunkedUploadController.php:48-52,226-238` same. `:1294` `sendAuthTemplate` formats with `PhoneNumber::formatE164`.
- `ApiController.php:1007, 1521`, `Api/ChunkedUploadController.php:221` — API responses echo `phone`.
- `ApiController.php:2973-2974` `formatChatValue` emits `phone`, `formatted_phone_number`; `app/Services/Chat/ChatBroadcastPayloadBuilder.php:128-129,344,367-368` broadcast payload emits `phone`/`formatted_phone_number`; `app/Http/Resources/ContactResource.php:30,40` emits them (`ContactListResource` does not emit phone).
- `ActivityLogger` labels fall back to `$contact->phone`: `ChatService.php:576,623,707`, `ChatController.php:154,216,247,285`, `ApiController.php:994`.
- `app/Services/PhoneService.php:209-221` `fromWhatsappId()` ("wa_id" → E.164) and `:160-188` `toE164` are WhatsApp/phone semantics; `:86` `formatForDisplay` feeds `Contact::formatted_phone_number` (`app/Models/Contact.php:375-388`).
- `ChatService.php:275,322` selects `phone` and calls `encryptPhoneNumber()` for the chat view; `:1397` `formatted_phone_number` in `contactPayloadForChatView`.

## 24-hour window
- `app/Helpers/MessagingWindowHelper.php:11` `WINDOW_HOURS = 24`, `:13-26` `isOpen`, `:28-43` reads `contacts.last_inbound_chat_created_at` or last inbound chat, `:45-48` `isMessagingWindowOpen(Contact)` with no channel parameter.
- `:76-84` and `:86-95` closed-window messages literally say "Whatsapp does not allow sending messages 24 hours after… you can send them a template message" (web `{success,message}` 422; API adds `statusCode:422`).
- Enforced at: `ChatService.php:563,610,701`; `SendMediaJob.php:141`; `ApiController.php:975,1354,1455,2513,2561`; `Api/ChunkedUploadController.php:97`; also `ConversationRatingService.php:68`, `ResetConversationRating.php:49` (outside subsystem). `MessagingWindowHelper::payloadForContact` (`:58-74`) exposes `is_messaging_window_open` to web (`ChatService.php:1379`) and mobile (`ContactResource.php:24,63-66`).
- `ChatService.php:1267-1272` block-contact error 139100 "must have messaged you within the last 24 hours".

## Templates
- `ChatService.php:255-259` chat page loads `Template` rows `status='APPROVED'` as `templates` prop; `:938-1028` `sendTemplateMessage` assumes Meta template structure (`header.format IMAGE/DOCUMENT/VIDEO` `:954`, `buildTemplate` → `components` `:1025`).
- `WhatsappService.php:461-543` payload `type='template'`; `:504-506` metadata via `buildTemplateChatMessage` (reads `templates.metadata.components` HEADER/BODY/FOOTER/BUTTONS `:642-755`) or `buildCampaignTemplateChatMessage` (`:557-640`, reads `campaigns.metadata.header/body/footer/buttons`); status from `messages[0].message_status` (`:506`). `:1190-1787` template CRUD/sync/delete against `/{waba_id}/message_templates`.
- `ChatController.php:142-204` template + auth template endpoints; `ApiController.php:1079-1307` three template endpoints; `organizations.metadata.auth_template` / `auth_template_parameters` (`ChatController.php:183,197`, `ApiController.php:1277,1301`).
- `MessagingWindowHelper` closed message points users to templates as the escape hatch.

## Meta payload / response shape
- Request: `messaging_product='whatsapp'`, `recipient_type='individual'` (`WhatsappService.php:267-268,484-485,909-910,1147-1148`; `ChatService.php:1249,1330` block_users).
- Graph URLs: `https://graph.facebook.com/{v}/{phone_number_id}/messages` (`WhatsappService.php:264,480,905,1144`), `/{phone_number_id}/block_users` (`ChatService.php:1247,1328`), `/{mediaId}` (`:1791`), `/{waba_id}?fields=health_status` (`:1801`), `/{app_id}/uploads` (`:2196`).
- Response: `$responseObject->data->messages[0]->id` (`:320,:500,:928`) — fatal if a driver returns a different shape; `messages[0]->message_status` (`:506`); error shape `data->error->{code,message,type,error_data->{messaging_product,details},fbtrace_id}` (`describeSendError` `:1057-1072`; `error_user_msg` `:2358`).
- Media: link-based send `{$type}.link` (`:913`), `filename` (`:915`), `caption` ≤1024 (`ChatService.php:935`, `ApiController.php:1360`), no caption for audio (`ChatService.php:924`, `SendMediaJob.php:163`, `WhatsappService.php:918,1105`).
- Media types: `image|video|audio|document|sticker` (`:823-826` doc comment); `ChatMediaUploadHelper::extensionsByType` (`app/Helpers/ChatMediaUploadHelper.php:164-173`) + `'gif'`; `User/ChunkedUploadController.php:32` `in:image,video,audio,document`; `Api/ChunkedUploadController.php:54` adds `gif`; `ApiController.php:1395` mimes list.
- Media limits are WhatsApp limits: `ChatMediaUploadHelper.php:14` `IMAGE_WHATSAPP_BYTES = 5MB`, `:16-18` video/audio 16MB, document 100MB; `config/chat.php:16-22`. `ImageCompressionService::prepareForWhatsapp` (`app/Services/ImageCompressionService.php:26-46`, 5MB, RGB/8-bit/JPEG-PNG rules) called unconditionally at `ChatService.php:736,873`; `VideoTranscodeService::transcodeForWhatsapp` at `ChatService.php:780`, `SendMediaJob.php:63`, `RetryMediaWithTranscodeJob.php:110`.
- MIME helpers `whatsapp_acceptable_content_type` / `whatsapp_media_content_type` (`app/Helpers/Helpers.php:17-41,49-73`) encode WA-accepted MIMEs (e.g. `audio/ogg; codecs=opus`, `audio/mp4`).
- `WhatsappService.php:1074-1094,1116-1132` HEAD + full GET of the media URL after send to fill `chat_media.type/size`.
- Meta error codes: `RetryMediaWithTranscodeJob.php:26` `131053/131052`; `ChatService.php:1267` `139100`.
- `ChatService.php:1221-1371` block/unblock are Graph-only.
- `WhatsappService.php:1142-1156` `reactToMessage` (Graph reaction, no persistence).

## Interactive / location types
- `WhatsappService.php:53-63` constants `TYPE_LOCATION_REQUEST='location request'`, `TYPE_LOCATION='location'`, Meta length caps 1000/1024; `:2248-2250` `INTERACTIVE_TYPES = ['interactive buttons','interactive call to action url','interactive list']`; `:90-108` location_request_message; `:123-146` location payload; `:2262-2304` `interactive.type = button|cta_url|list`.
- `ChatController.php:94,115-116` and `ApiController.php:2492,2540-2541` validate against `WhatsappService::LOCATION_*` constants.
- `ApiController.php:980-986` maps `buttons` → `'interactive buttons'`, header `{type:'text', text}`.

## Stored `wam_id` / status / metadata semantics
- `chats.wam_id` = Meta message id (`WhatsappService.php:320,500,928,938`; `ChatStatusLog` metadata `id` `:956`); unique index `chats_wam_id_active_unique(wam_id_active_key)` is **global, not org-scoped** (`database/migrations/2026_05_31_000002_add_virtual_unique_indexes_for_contacts_and_chats.php:33-42`).
- Status literals: text → `'delivered'` immediately (`:325`), media/template → `'sent'` (`:506,:939,:957,:986`), retry → `'failed'` (`RetryMediaWithTranscodeJob.php:171`, `ProcessMediaUploadJob.php:151,167`). Mobile only understands `accepted|sent|delivered|read|failed` (`app/Support/ChatStatus.php:15`, `played→delivered`).
- Chat metadata keys are the WhatsApp inbound shape mirrored for outbound: `{type:'text', text:{body}}` (`:302`), `{type:'location', location:{...}}` (`:313-316`), `{id, type, {type}:{mime_type, caption}}` (`formatMediaResponse` `:1096-1114`), `ProcessMediaUploadJob.php:117` `{$mediaType}.link`. Consumers: `ContactListResource::metadataPreview` (`app/Http/Resources/ContactListResource.php:56-109`, branches on `text|button|interactive|document|image|video|audio|sticker|location|contacts`), `ApiController::formatChatValue/V2` (`:2949-2957,2425-2433`), `RetryMediaWithTranscodeJob::shouldRetryForChat` (`metadata.type === 'video'` `:49-51`), Vue bubbles.
- `WhatsappService.php:299-305` explicit contract: keep `type:'text'` for new interactive kinds and add flags (`location_request:true`, `cta_url`) because the mobile app falls to its default branch on unknown types.

## Hidden HTTP-request coupling
- `WhatsappService.php:179-180` reads `Request()->get('tempMessageId')` / `'msg_uuid'` inside the service; `ApiController.php:938,1389` force `tempMessageId=-1` to make sends queue; `ChatService.php:728` reads `Request()->get('tempMessageId')`.

## required_changes

## 0. Already channel-agnostic (keep as is)
`ChunkedUploadService`, both `ChunkedUploadController::finish()` mechanics, `ChatService::getChatMessages/getChatList/canAccessConversation/handleTicketAssignment/clearContactChat`, `Chat`/`ChatLog`/`ChatMedia`/`ChatStatusLog` models, `NewChatEvent` + `ChatBroadcastPayloadBuilder`, `ActivityLogger` calls, `ChatStatus`, `sanitize_filename_for_storage`, `queueMediaBatch` chain ordering, `SendMediaJob` temp-file lifecycle, `resolveMediaCaption`.

## 1. Channel driver abstraction (new, additive)
- Add `app/Services/Messaging/Contracts/ChannelDriver.php` with: `channel(): string`; `canAddress(Contact): bool` (replaces `isSendableNumber`); `sendText(Contact, string $body, array $options): SendResult`; `sendMedia(Contact, MediaPayload): SendResult`; `sendLocation(Contact, array $location): SendResult` (optional capability); `capabilities(): ChannelCapabilities` (templates, locationRequest, interactiveButtons, captions, maxBytes per media type, blockContact, deliveryReceipts); `messagingWindow(): ?int` hours (WA 24, Messenger 24, Instagram 24, TikTok per its policy, Telegram null = no window).
- `SendResult` value object: `{success: bool, providerMessageId: ?string, status: 'sent'|'delivered', error: ?array{code,message,details}, raw: mixed}` — and a `toLegacyResponseObject()` that yields exactly `stdClass{success, data->messages[0]->id, data->error, message}` so existing callers (`ApiController` returns `$responseObject` verbatim in `data`, webhooks `message.sent`, flows) keep working.
- `WhatsappDriver` = thin adapter over the existing `WhatsappService` (no behavior change; keep `WhatsappService` public API intact because 19 external constructors and flows/campaigns/IntelliReply call it).
- `TelegramDriver`, `MessengerDriver`, `InstagramDriver`, `TikTokDriver` under `app/Services/Messaging/Drivers/`, each built from its own `organizations.metadata.{telegram|messenger|instagram|tiktok}` config block.
- `ChannelResolver::forContact(Contact): ChannelDriver` keyed on new `contacts.channel` (default `'whatsapp'`), and `ChannelResolver::forOrganization($orgId, $channel)`.
- `ChannelConnectionHelper::errorFor($orgId, $channel)` generalizing `WhatsappConnectionHelper::errorFor` (keep the old class delegating to it).

## 2. Extract the recorder (the key refactor)
- Create `app/Services/Messaging/OutboundMessageRecorder.php` containing the persistence+broadcast+webhook block that is duplicated at `WhatsappService.php:318-348`, `:498-532`, `:926-1044` and re-implemented in `RetryMediaWithTranscodeJob::broadcastChatUpdate` (`:177-199`): `recordText(Contact, providerId, metadata, userId, msgUuid, tempMessageId)`, `recordMedia(...)` (creates `chat_media`), `recordTemplate(...)`, `attachToExisting(chatId, providerId, ...)` (retry path). Make `WhatsappService` call it (pure refactor first, verified by the existing tests), then new drivers call it too. This is what guarantees `chat_logs`, `NewChatEvent` wrapper `[[type=>'chat', value=>relatedEntities, tempMessageId]]`, `uuid` assignment (`isAssignableMessageUuid`) and webhook parity across channels.
- Provider message ids must be namespaced before hitting `chats.wam_id` (global unique index): `tg:{bot_id}:{chat_id}:{message_id}` for Telegram (message_id is per-chat sequential), `fb:{mid}`, `ig:{mid}`, `tt:{id}`; keep WA ids raw. Column is `varchar(128)` — fits.

## 3. Guards made channel-aware
- `WhatsappService::isSendableNumber` stays for WA; introduce `$driver->canAddress($contact)` and use it in `SendMediaJob.php:128`, `executeSendMessage :243`, `sendTemplateMessage :470`, `sendMedia :894` via the driver.
- `MessagingWindowHelper`: add `windowHoursFor(Contact)`/`isMessagingWindowOpen(Contact)` consulting `ChannelResolver` (null = always open); parametrize the closed-window text (drop the literal "Whatsapp" in `:81,:92`); keep `payloadForContact` keys unchanged (`is_messaging_window_open` etc.) so web/mobile need no change.
- Templates are WA-only: gate `ChatService::sendTemplateMessage :938`, `ChatController::sendTemplateMessage/sendAuthTemplate`, `ApiController :1079-1307` with `capabilities()->templates` → 422 "Templates are not available on {channel}". In `ChatService::getChatList :255-259` pass `templates` only when the open contact is WA (or let the Vue hide the button by `contact.channel`).
- Location request (`ChatService::requestLocation :549`) and interactive types: capability-gated (Telegram supports `request_location` reply keyboard; Messenger/Instagram/TikTok do not) — return 422 rather than silently sending text.
- Block/unblock (`ChatService :1221-1371`): gate on `capabilities()->blockContact`; for non-WA either local-only `markAsBlocked` or 422.

## 4. Entry points
- `ChatService`: replace the four `initializeWhatsappService()` calls (`:551,:598,:690,:940`) with `$driver = ChannelResolver::forContact($contact)` after the contact lookup; `sendMessage :822` → `$driver->sendText(...)`; `:805` → `$driver->sendMedia(...)`; keep `prepareForWhatsapp`/transcode but make them conditional on `capabilities()->mediaConstraints` (they are harmless defaults). `resolveMediaCaption :935` cap → `capabilities()->captionMax` (Messenger has no captions: driver sends caption as a following text message and records both, or records as metadata caption only).
- `SendTextMessageJob` / `SendMediaJob`: replace `metadata.whatsapp` block (`SendTextMessageJob :62-76`, `SendMediaJob :147-161`) with `ChannelResolver::forContact($contact)`. Keep constructor signatures (append optional params only) because jobs already serialized in Redis at deploy time must still unserialize.
- `WhatsappService::sendMessage :179-180`: stop reading the HTTP request inside the service for new drivers; pass `tempMessageId`/`msg_uuid` explicitly from `ChatService` (leave WA as is to avoid regressions, but do not copy the pattern).
- `ApiController` mobile endpoints: accept `contact_uuid` (or `channel` + `external_id`) as an alternative to `phone` on `/send`, `/send-msg`, `/send-template`, `/send-auth-template`, `/send/media`, `/chats/upload/chunk` (`Api/ChunkedUploadController`), `/request-location`, `/send-location` already take `uuid`; `resolveContactByPhone :1526` gets a sibling `resolveContact(Request, orgId)` that prefers `contact_uuid`; `whatsappNotConnectedResponse :969,1116,1211,1348,1447` and `Api/ChunkedUploadController :87` → `ChannelConnectionHelper::errorFor($org, $contact->channel)`. Add `channel` to every response `data` block (`:1003-1008,1516-1522`, `Api/ChunkedUploadController :214-222`) and to `formatChatValue :2967-2991` / `formatChatMessageV2 :2435-2448`; document that `phone`/`formatted_phone_number` are `null` for non-WA and `wam_id` now carries a namespaced provider id.
- `ChatController::openByPhone :47` stays WA-only (documented); add nothing.
- `ActivityLogger` label fallbacks (`ChatService :576,623,707`, `ChatController :154,216,247,285`, `ApiController :994`): use `full_name ?: phone ?: external_id`.
- `ChatMediaUploadHelper::maxUploadBytesForType/maxAssembledBytesForType (:100,:140)`: add optional `$channel` and per-channel table (Telegram bot upload 50MB, Messenger 25MB, Instagram 8MB image/25MB video — verify against current docs), default WA.
- Broadcast: `ChatBroadcastPayloadBuilder::loadContactInfo :340-346` add `channel` + `external_id` to the select and payload so the Vue chat list can render the platform icon on live updates without a phone.

## 5. Campaigns (outside this subsystem but wired here)
- Campaign jobs call `WhatsappService::sendTemplateMessage(..., $campaignId)` (`SendCampaignJob.php:252,290`, `ProcessSingleCampaignLogJob.php:120`, `RetryCampaignLogJob.php:158`). For non-WA audiences: campaign metadata should carry a free-form `{text, media?}` body, the job resolves the driver per contact, calls `sendText`/`sendMedia`, and records via `OutboundMessageRecorder::recordText/recordMedia` with a metadata shape identical to `buildCampaignTemplateChatMessage` output (`type:text|image|video|document`, `header.text?`, `{type}.body|caption`, `footer?`, `buttons?`) so the campaign history / chat bubble render unchanged.

## 6. Deletion candidates (do not extend)
`ProcessMediaUploadJob` (no dispatchers), `MediaService::upload` (broken `$contact`), `routes/web.php:200` dead route, commented `sendMessage` at `WhatsappService.php:353-452`.

## risks

- **Global unique `wam_id`** (`2026_05_31_000002...:33-42`): Telegram `message_id` is per-chat sequential; storing it raw will throw `1062` on `Chat::create` **after** the provider already delivered the message → message delivered but not recorded, `SendMediaJob` throws (`:200`) and the retry re-sends → duplicates. Namespace provider ids, and catch persistence failures separately from send failures.
- **Send-then-persist ordering**: `executeSendMessage :296→318`, `sendTemplateMessage :490→498`, `sendMedia :922→979` all POST first and write the DB afterwards, with no transaction and no idempotency key. Any exception between (uuid collision, DB timeout, broadcast build) loses the record; queue retries (`SendMediaJob` throws on non-success only, but a PHP exception after success also retries) cause double sends. For new drivers prefer "create chat row with `status='pending'` → send → update wam_id/status" (the `existingChatId` path at `:934-977` already models this) or an idempotency key stored on the job.
- **`isSendableNumber` in every path** (`:243,:470,:894`, `SendMediaJob :128`): forgetting one site means non-WA sends silently fail with "no phone number" — the tests in `ContactWithoutPhoneTest` and `OutboundSendCycleTest` will now assert the *WA* behavior only; add channel-aware variants.
- **Response object contract**: callers read `$responseObject->data->messages[0]->id` (`:320,:500,:928`) and `ApiController` returns `$responseObject` verbatim to mobile (`:1015,:1130,:1234,:1375`); flows/IntelliReply/rating service call `sendMessage/sendMedia` and inspect `->success`. Drivers must return the same stdClass shape or every consumer must be touched.
- **Messaging window depends on `Chat::create` observer** (`app/Models/Chat.php:21-32` sets `contacts.last_inbound_chat_created_at`). If the new inbound webhooks insert via `DB::table('chats')->insert` or bypass the model, `MessagingWindowHelper::resolveLastInboundAtUtc :28-43` never opens the window and all replies are 422.
- **Queued job payload compatibility**: `SendTextMessageJob` (11 ctor args, `mixed $tempMessageId`) and `SendMediaJob` (10 promoted props) are serialized into Redis; renaming/removing properties breaks jobs already queued at deploy time. Add new optional trailing params only; never remove.
- **Closure jobs**: `sendTemplateMessage($sendByQueue=true)` dispatches a serialized closure (`:536-537`) capturing `$this` (WhatsappService with token). Do not replicate for new drivers; use a real job class.
- **Hidden request coupling**: `Request()->get('tempMessageId'|'msg_uuid')` inside `WhatsappService::sendMessage :179-180` and `ChatService :728`; `ApiController` forces `tempMessageId=-1` (`:938,:1389`). Behavior differs between HTTP and job/CLI contexts (sync vs queued). If drivers copy this, campaign/flow sends will behave differently than dashboard sends.
- **Bus::chain semantics** (`ChatService :917`): chain stops on first exception unless `continueBatchOnFailure`; a driver that throws instead of returning `success=false` will swallow the rest of the batch.
- **Status vocabulary**: WA writes `'delivered'` for text immediately (`:325`) but `'sent'` for media; mobile accepts only `accepted|sent|delivered|read|failed` (`ChatStatus.php:15`). Telegram has no delivery receipts (rows stay `sent` forever), Messenger/Instagram do; write only these five literals.
- **Metadata `type` contract**: mobile falls into its default branch on unknown `metadata.type` (`WhatsappService.php:299-305` comment; `RatingSurveyButtonTest :238` asserts `type==='text'`). New interactive kinds must be stored as `type:'text'` + flags, never as e.g. `type:'telegram_keyboard'`.
- **Frontend/mobile expect `phone`** in broadcast (`ChatBroadcastPayloadBuilder :128-129`), `formatChatValue :2973-2974`, `ContactResource :30,40`, `contactPayloadForChatView :1397`; `full_name` can be `''` (`Contact.php:360-373`) so a contact with no name and no phone renders blank unless a display handle (Telegram username / IG handle) is provided.
- **Media size/HEAD calls in the request thread**: `sendMedia :929-932` performs HEAD + full GET of the media URL synchronously (`ApiController::sendMediaMessage :1362` is synchronous); a driver that uploads bytes instead of a public link should carry `size`/`mime` from the upload instead of re-fetching.
- **Storage `local` URLs** (`APP_URL/media/public/...`, `ChatService :793`, `SendMediaJob :81`) must be publicly reachable by the provider; Messenger/Instagram fetch by URL like WA, Telegram can fetch by URL too (≤20MB for photos via URL) — otherwise upload multipart. Do not assume `location='amazon'` as `ApiController :1368` does.
- **Access/authorization**: `ChatService::assertConversationAccess :105` is applied only in `ChatService` paths; `ApiController` send paths do not check ticket assignment. Keep parity per channel.
- **Pre-existing bugs to not build on**: `sendHttpRequest :2363` `catch (Exception $e)` is unqualified inside `namespace App\Services` (resolves to non-existent `App\Services\Exception`; generic throwables propagate); `ProcessMediaUploadJob` dead; `MediaService` broken; dead route `web.php:200`.
- **Text-matching tests will break on refactor**: `MediaRetryAndLocaleVersionTest :23-67` greps `SendMediaJob` source (`cleanupTempFiles`, throw regex), `ChatMediaAlbumTest :197-210` greps for `continueBatchOnFailure`, `WhatsappImageAcceptanceTest :314-324` asserts both send paths call `prepareForWhatsapp`, `ContactWithoutPhoneTest :246-256` asserts inbound paths contain `PhoneService::fromWhatsappId(`. Update these deliberately, not accidentally.
- **DB**: adding `channel VARCHAR(20) NOT NULL DEFAULT 'whatsapp'` on `contacts` (817K) and `chats` (3.2M) is `ALGORITHM=INSTANT`-eligible on MySQL 8.4 (column appended, constant default) — verify with `ALTER ... ALGORITHM=INSTANT` explicitly so it fails fast instead of rebuilding; adding a composite index `(organization_id, channel)` on contacts is an online (INPLACE) operation but takes I/O — run off-peak. Never alter `wam_id`/`phone` or the virtual unique columns.

## tests

## Existing tests touching this subsystem (all are guard/shape tests; none exercises a successful HTTP send end-to-end)
- `tests/Feature/Cycle/OutboundSendCycleTest.php` (extends `CycleTestCase`): 24h window open/closed/ages/outbound-doesn't-open (`:51-97`), `payloadForContact` keys (`:100-116`), `executeSendMessage` refuses empty text / no phone / other-org contact / bad location before network (`:126-185`), `SendMediaJob` refuses closed window (and deletes temp), no phone (logs), missing temp, deleted contact (`:190-236,:279-293`), `SendTextMessageJob` quiet on missing org / no phone / empty text (`:245-276`).
- `tests/Feature/ChatMediaBatchTest.php` (`Bus::fake`): `POST /chats` with `files[]` queues one `SendMediaJob` per file in order, caption on first only, `continueBatchOnFailure=true`, temp files persisted before dispatch, mismatched `tempMessageIds` → 422, closed window → 422, other-org → 404, single-file and plain-text branches untouched (`:117-330`); plus Vue-source string assertions (`:337-420`).
- `tests/Feature/MobileMediaBatchTest.php` (`Bus::fake`): `/api/v1/send-msg` unified endpoint: ordered chain, per-file `msg_uuid`, per-file type, caption rules, `file`/`files` aliases, `type=text` with a file goes media path, closed window returns `statusCode` shape, unsupported file 400, over-limit 400, standalone `/send-media` route is 404 (`:150-392`).
- `tests/Feature/SendLocationTest.php`: 404 unknown/other-org contact, 422 closed window (message contains "24 hours"), invalid coordinates 422 with exact message, window check precedes coordinate check, `getOrganizationLocation` resolution/address label, Arabic translations, routes registered (`:108-369`).
- `tests/Unit/LocationMessageTest.php`, `tests/Unit/LocationRequestMessageTest.php`: exact Meta payload shapes for `buildLocationPayload`/`buildLocationRequestPayload`, truncation by characters, `isUsableLocation`, inbound location storage shape.
- `tests/Feature/ContactWithoutPhoneTest.php`: `PhoneService::fromWhatsappId` cases, `isSendableNumber` truth table (`:174-180`), text/media sends to phone-less contact never reach Meta (`:194-236`), inbound paths use `fromWhatsappId` (source grep `:246-256`).
- `tests/Feature/ChunkedUploadTest.php`: `ChunkedUploadService` assemble/order/missing chunk/traversal/scoping/prune/discard.
- `tests/Feature/MobileChunkedUploadTest.php` (`Bus::fake`): `/api/v1/chats/upload/chunk` responses, assembled bytes reach `SendMediaJob` with `fileName/fileType/caption/messageUUID`, idempotent resend, out-of-order, closed window rejected before storing bytes, inactive subscription 403, disconnected WhatsApp 403, unsupported ext 400, over-limit after assembly, cancel.
- `tests/Feature/WhatsappImageAcceptanceTest.php`: `ImageCompressionService` CMYK/16-bit/animated/WebP/PNG-disguised normalization, oversize compression, corrupt file, and both send paths call `prepareForWhatsapp` (source grep `:314`).
- `tests/Feature/MediaRetryAndLocaleVersionTest.php :23-67`: source-text assertions on `SendMediaJob` (temp survives failed send, every other exit cleans, failure throws outside a batch).
- `tests/Feature/ChatMediaAlbumTest.php :176-210`: source-text assertions that the batch is a chain and `continueBatchOnFailure` exists.
- `tests/Unit/RetryMediaWithTranscodeJobTest.php`: `shouldRetryForChat` only (video, retryable codes, once).
- `tests/Unit/ChatAttachmentUploadTest.php`: `ChatMediaUploadHelper` PHP-limit math + Vue composer source assertions.
- `tests/Unit/ContactPhoneNormalizationTest.php`: phone normalization on save, `full_name` empty-string contract, `toE164` returns null rather than guessing.
- `tests/Feature/RatingSurveyButtonTest.php :72-247`: `buildInteractivePayload` cta_url/button shapes and `ctaUrlMetadata` stored `type:'text'` + `cta_url` contract.
- `tests/Feature/MarketingOptOutTest.php`, `tests/Feature/MobileApi/DeletedTemplateTest.php`: campaign audience / deleted-template behavior (template path).

## Gaps
- No test fakes Graph HTTP (`Http::fake`/Guzzle) to assert the success path of `executeSendMessage`, `sendMedia`, or `sendTemplateMessage`: `chats`/`chat_logs`/`chat_media` rows, `wam_id`, `status` literals, `uuid` assignment (`isAssignableMessageUuid`), `NewChatEvent` wrapper, `message.sent` webhook. This is exactly the block to extract; write these tests first (Event::fake + Http::fake) to lock the WA behavior before refactoring.
- No test of `ApiController::sendMessage/sendTemplateMessage/sendTemplateMessageByUUID/sendAuthTemplate/sendMediaMessage` happy paths or response `data` shapes; no test of `ChatController::sendTemplateMessage` redirect flash.
- No test that `SendTextMessageJob` success creates a chat; none for `ProcessMediaUploadJob` (dead) or `RetryMediaWithTranscodeJob::handle`.
- No test for `MessagingWindowHelper` text/`closedWindowApiJsonResponse` shape beyond "contains 24 hours".
- No test for `ChatBroadcastPayloadBuilder::buildMinimalValue` keys vs. `formatChatValue` keys (parity that mobile relies on).
- Several tests assert on PHP/Vue source text rather than behavior; a driver refactor must update them intentionally.

## data_shapes

## Organization config consumed (organizations.metadata JSON)
`{"whatsapp":{"access_token","app_id","phone_number_id","waba_id",...},"auth_template":"<template uuid>","auth_template_parameters":{"template":"<uuid>",...},"contacts":{"location":...},"tickets":{"active":bool,"auto_assignment":bool,"reassign_reopened_chats":bool},"ai":{"ai_chat_form_active":bool}}`; `organizations.address` JSON `{street,city,state,zip,country,latitude,longitude}` (`ChatService.php:654-686`). New channels: add sibling keys `telegram|messenger|instagram|tiktok`.

## Graph request payloads (what the WA driver sends)
- Text: `{"messaging_product":"whatsapp","recipient_type":"individual","to":"<contacts.phone>","type":"text","text":{"preview_url":true,"body":"<clean()ed>"}}` (`WhatsappService.php:267-273`).
- Interactive: `{"type":"interactive","interactive":{"type":"button|cta_url|list","header"?:{...},"body":{"text"},"footer"?:{"text"},"action":{"buttons":[{"type":"reply","reply":{"id","title"}}]|"name":"cta_url","parameters":{display_text,url}|"sections":[...],"button":label}}}` (`:2262-2304`).
- Location request: `{"type":"interactive","interactive":{"type":"location_request_message","body":{"text"},"action":{"name":"send_location"}}}` (`:90-108`).
- Location: `{"type":"location","location":{"latitude":float,"longitude":float,"name"?:≤1000,"address"?:≤1000}}` (`:123-146`).
- Media: `{"type":"image|video|audio|document|sticker","<type>":{"link":"<public URL>","filename"?:"<document only>","caption"?:"≤1024, not audio"}}` (`:909-920`).
- Template: `{"type":"template","template":{"name","language":{"code"},"components":[{"type":"header|body|button","parameters":[...]}]}}` (`:484-488`, `TemplateTrait.php:30-59`).
- Block: `POST/DELETE /{phone_number_id}/block_users {"messaging_product":"whatsapp","block_users":[{"user":"<phone>"}]}` (`ChatService.php:1248-1255`).

## Provider response object (`sendHttpRequest`, `:2331-2371`) — the contract every driver must reproduce
`stdClass{ success: bool, data: stdClass, message?: string }` where on success `data = {"messaging_product":"whatsapp","contacts":[{"input","wa_id"}],"messages":[{"id":"wamid...","message_status"?:"accepted"}]}` and after recording `data->chat = Chat(with contact, media)` (`:334,:512,:963,:1014`); on failure `data = {"error":{"code":int,"message","type","error_data":{"messaging_product","details"},"fbtrace_id","error_user_msg"?}}`. `describeSendError()` returns `{code?,title?,message?,details?,trace?}` (`:1057-1072`).

## Rows written
- `chats`: `{organization_id, wam_id (varchar128, global-unique when not deleted), contact_id, type:'outbound', user_id (0 for API, null for jobs w/o user), metadata (JSON text), media_id?, status:'delivered'|'sent'|'failed', is_read, uuid (char50 unique; client `msg_uuid` if free), created_at (UTC)}`.
- `chat_logs`: `{contact_id, entity_type:'chat', entity_id: chats.id, created_at}` — the thread reads through `chat_logs` (`ChatService::getChatMessages :1132-1184`; `ChatLog::relatedEntities` `app/Models/ChatLog.php:35-54`).
- `chat_media`: `{name, path (absolute URL), location:'local'|'amazon', type (mime), size (bytes), created_at}`.
- `chat_status_logs` (retry path): `{chat_id, metadata:'{"id":"<wam_id>","status":"sent"}', created_at}`.
- `contacts.latest_chat_created_at` updated by `Chat::created` observer.

## Stored `chats.metadata` shapes (must be reproduced byte-for-byte in keys)
- Text: `{"type":"text","text":{"body":"..."}}`; location request adds `"location_request":true`; CTA URL: `{"type":"text","text":{"body":"<body>\n\n<label>: <url>"},"cta_url":{"display_text","url"}}` (`:302-308,:2315-2329`).
- Location: `{"type":"location","location":{"latitude","longitude","name"?,"address"?}}` (`:313-316`).
- Media: `{"id":"<wam_id>","type":"image|video|audio|document|sticker","<type>":{"mime_type":"...","caption"?:"..."},"transcript"?:"..."}` (`:1096-1114`); retry adds `"transcode_retry_count":1,"transcode_retry_status":"retrying|failed"` (`RetryMediaWithTranscodeJob.php:95-96,169`); `ProcessMediaUploadJob` adds `"<type>":{"link"}` (`:117`).
- Template (non-campaign, `:642-755`): `{"type":"text|image|video|document|location","header"?:{"text"},"<type>":{"body"|"caption":"...","footer"?:"..."},"buttons"?:[{"type","text","value","parameters"?}]}`; campaign variant identical but `buttons[].value` from campaign metadata (`:557-640`).
- Fallback when template row missing: `{"type":"text","text":{"body":"<template name>"}}` (`:649-656`).

## Broadcast (`NewChatEvent`) 
- Input wrapper: `[['type'=>'chat','value'=>ChatLog->relatedEntities (Chat with media,user,logs),'tempMessageId'=>$tempMessageId]]`; ctor `(chat, organizationId, isNewContact=false, statusChanged=false, sendToFirestore=false)` (`app/Events/NewChatEvent.php:60-66`); queue `high`; channels `chats.ch{org}.{userId}`.
- Wire payload (`ChatBroadcastPayloadBuilder::buildMinimalValue :122-146`): `{"chat":[{"type":"chat","value":{"id","uuid","contact_uuid","contact_id","is_new_contact","phone","formatted_phone_number","organization_id","latest_chat_created_at","is_blocked","is_favorite","contact_full_name","unread_messages_count","created_at","deleted_at","metadata":"<JSON string>","type":"outbound","wam_id","status":<forApi>,"media":{"type","size","path"≤200B,"name"≤80B}|null,"logs":[{"metadata":"{status?,errors?,id?}"}],"user":{"full_name"}|null},"tempMessageId"?}],"statusChanged"?:true}`.
- Webhook: `WebhookHelper::triggerWebhookEvent('message.sent', ['data' => $responseObject], $orgId)` (`app/Helpers/WebhookHelper.php:10-22`).

## Web responses
- `POST /chats` text: raw `$responseObject` JSON (WA object incl. `data.chat`) or `null` body when queued via `tempMessageId`; batch: `{"success":true,"queued":n}`; errors `{"success":false,"message"}` 404/422 (`ChatService :692-696,:701-703,:743-747,:814-820,:847-852`).
- `POST /chat/{uuid}/send/template`: redirect back with flash `status = {type:'success'|'error', message, res: $responseObject}` (`ChatController :170-176`).
- `POST /chat/{uuid}/request-location|send-location`: `$responseObject` or `{"success":false,"message"}` 404/422.
- Chat page Inertia props (`ChatService :342-368`): `rows` (ContactListResource), `rowCount`, `pusherSettings`, `settings` (Organization), `templates:[{uuid,name,language}]`, `contact` = `{id,uuid,is_blocked,first_name,last_name,email,metadata,is_favorite,address,avatar,last_inbound_chat_created_at,last_inbound_chat_created_at_iso,last_inbound_chat:{created_at,created_at_iso}|null,is_messaging_window_open,full_name,formatted_phone_number,contact_groups:[{id,name}],contact_categories:[{id,uuid,name,background_color,text_color}]}` (`:1377-1412`), `chatThread:[[{type:'chat'|'ticket'|'notes', value}]]`, `hasMoreMessages`, `nextPage`, `isChatLimitReached`, `hasMoreContacts`, `nextContactsPage`.
- `ContactListResource` row (`app/Http/Resources/ContactListResource.php:19-50`): `{id,uuid,full_name,avatar,latest_chat_created_at,is_blocked,ticket_status,ticket_assigned_to,ticket_assigned_seen,unread_messages,last_chat:{metadata:<preview JSON string>,deleted_at,created_at},contact_categories}` — no phone; the platform icon must come from a new `channel` key here.

## Mobile API responses (document for the mobile dev)
- `POST /api/v1/send-msg` (text) / `POST /api/send`: `{"statusCode":200,"success":true,"message":null|"Message queued for sending.","data": <responseObject> | {"queued":true,"contact_id","contact_uuid","phone"}}` (`ApiController :1001-1016`).
- `POST /api/v1/send-msg` (files): `{"statusCode":200,"success":true,"message":"Message sent successfully","data":{"queued":true,"files":n,"contact_id","contact_uuid","phone"}}` (`:1512-1523`); failure `{"statusCode":422,"success":false,"message"}`.
- `POST /api/send/template`, `/api/v1/send-template`, `/api/v1/send-auth-template`: `{"statusCode":200,"success":true,"message":"Template sent successfully","data": <responseObject>|null when queued}` (`:1126-1131,:1230-1235`).
- `POST /api/send/media`: `{"statusCode":200,"success":true,"data": <responseObject>}` (`:1372-1376`).
- `POST /api/v1/chats/upload/chunk`: `{"statusCode":200,"success":true,"message":null|"Message sent successfully","data":{"completed":bool,"received","total","queued"?:true,"contact_id"?,"contact_uuid"?,"phone"?}}`; `DELETE` → `data:{discarded:true}` (`Api/ChunkedUploadController :113-122,:210-223,:152-157`).
- `POST /api/v1/request-location|send-location`: web-shaped `{"success","message"}` or `$responseObject` (delegated to `ChatService`), except closed window → `{"statusCode":422,"success":false,"message"}` (`:2513-2515,:2561-2563`). `GET /api/v1/organization-location`: `{"statusCode":200,"success":true,"data":{latitude,longitude,name,address}|null,"is_set":bool}`.
- Error envelopes: validation `{"statusCode":400,"success":false,"message":"The provided data is invalid.","errors":{...}}`; subscription `{"statusCode":403,...}`; not connected `{"statusCode":403,"success":false,"message":<WhatsappConnectionHelper text>}`; closed window `{"statusCode":422,"success":false,"message":"Whatsapp does not allow…"}`.
- Chat rows in sync endpoints: `formatChatValue :2967-2991` = `{id,uuid,contact_uuid,contact_id,is_new_contact:false,phone,formatted_phone_number,organization_id,latest_chat_created_at,is_blocked,is_favorite,contact_full_name,unread_messages_count,created_at,deleted_at,metadata:<JSON string with "<type>":null when empty>,type,wam_id,status:<accepted|sent|delivered|read|failed|null>,media:{type,size,path,name}|null,logs:[{metadata}],user:{full_name}|null}`; `formatChatMessageV2 :2435-2448` same minus contact fields. `ContactResource :25-81` includes `phone`, `formatted_phone_number`, `last_inbound_chat_created_at(_iso)`, `last_inbound_chat`, `is_messaging_window_open`.

## Job constructor signatures (serialized in Redis — append-only)
- `SendTextMessageJob(int $organizationId, string $contactUuid, string $messageContent, ?int $userId=null, string $type='text', ?array $buttons=null, ?array $header=null, ?string $footer=null, ?string $buttonLabel=null, ?string $messageUUID=null, mixed $tempMessageId=null)` (`:29-41`).
- `SendMediaJob(int $organizationId, string $uuid, string $fileType, string $fileName, string $tempFilePath, ?int $userId, ?string $tempMessageId, ?string $messageUUID, ?string $caption=null, bool $continueBatchOnFailure=false)` (`:26-45`); `$timeout=300`.
- `RetryMediaWithTranscodeJob(int $chatId, int $organizationId)`; unique id `retry-media-transcode-{chatId}`, `$uniqueFor=300`, `$tries=1`, `$timeout=600` (`:26-40`).

## Helper contracts
- `PhoneService::fromWhatsappId(?string): ?string` → `+digits` E.164 or `+digits` fallback (`:209-221`); `toE164` never guesses a country (`:160-188`); `formatForDisplay` (`:86`).
- `ChatMediaUploadHelper::extensionsByType()` → `{image:[jpg,jpeg,png,gif,bmp,webp,svg,ico,heic,heif], video:[mp4,avi,mov,wmv,flv,mkv,webm,3gp,mpeg,mpg], audio:[mp3,wav,ogg,aac,m4a,flac,wma,amr,opus], document:[pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,rtf,odt,ods,odp], gif:[gif]}` (`:164-173`); limits image=PHP limit only, video/audio 16MB, document 100MB (`:100-118`), batch max `config('chat.max_batch_files')=10`, request cap 90MB (`config/chat.php`).
- `whatsapp_media_content_type(fileType, fileName)` → S3 ContentType; `whatsapp_acceptable_content_type(mime, mediaType)` → normalized mime (`app/Helpers/Helpers.php:17-73`).
