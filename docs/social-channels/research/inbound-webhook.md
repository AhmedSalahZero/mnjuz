# Subsystem: inbound-webhook


## summary

The inbound-webhook subsystem is the single ingress for everything Meta pushes to the app. WhatsApp Cloud API webhooks hit `/webhook/whatsapp/{identifier}` (routes/web.php:90), `WebhookController::handle` resolves the `Organization` by `identifier`, and `handleAjaxPostRequest` (app/Http/Controllers/WebhookController.php:137-212) reads only `entry[0].changes[0]` and fans out by `field` to six queued jobs: `ProcessIncomingMessageJob` (queue `high`), `ProcessMessageStatusJob` (`messageStatus`), `ProcessTemplateStatusJob` (`low`), `ProcessMessageEchoJob` (`high`, coexistence), `ProcessContactSyncJob` (`low`), and `ProcessAccountUpdateJob` (`low`, the catch-all for any unknown field). `ProcessIncomingMessageJob` turns `message.from` into an E.164 phone, `firstOrCreate`s the Contact by `(organization_id, phone)`, inserts the `chats` row (dedup on `wam_id` via a 1h cache key plus the global unique virtual index), inserts a `chat_logs` row, runs ticket assignment synchronously, then dispatches media download (`media` queue, Graph API), auto-reply (`autoreplies`), the working-hours away notice (a WhatsApp send), the `NewChatEvent` Pusher broadcast + FCM push, and the customer-facing outgoing webhook `message.received` (raw Meta message). `ProcessMessageStatusJob` applies a forward-only status ladder to `chats.status` with an atomic `FIELD()` UPDATE, always appends to `chat_status_logs`, and hooks campaign retry and video-transcode retry on `failed`. `modules/Webhook/**` is the *outgoing* side: customer URLs per organization receive `{event, data}` POSTs synchronously from inside the jobs. Everything except the ingress controller, contact identity, media download, `ChatMetadataHelper` and the WhatsApp-only maintenance jobs is already channel-agnostic.

## current_flow

## 1. Routing and ingress
- routes/web.php:90 `Route::match(['get','post'], '/webhook/whatsapp/{identifier?}', WebhookController@handle)`.
- routes/web.php:91 `/webhook/waba` → `WebhookController::whatsappWebhook` (app/Http/Controllers/WebhookController.php:43-57): GET-style verification only, token from `Setting key='whatsapp_callback_token'` (line 46, `->first()->value` throws if the row is missing). A POST here returns `{"error":"Forbidden"}` with HTTP 200 (line 55) and processes nothing.
- routes/web.php:92 `/webhook/{processor}` → `processWebhook` (WebhookController.php:313-319) → `PaymentPlatformResolver::resolveService` (app/Resolvers/PaymentPlatformResolver.php:7-16) which THROWS for unknown names. This single-segment catch-all sits after the WhatsApp route; any new `/webhook/telegram` (one segment) would land here and 500.
- CSRF exemption: app/Http/Middleware/VerifyCsrfToken.php:15 `'webhook/*'` (wildcard, covers future channel routes).
- WebhookController::__construct (:34-41) calls `BroadcastProvider::apply()` so events emitted synchronously use the configured broadcaster (guarded by tests/Feature/BroadcastProviderTest.php:278 source check).

## 2. Organization resolution and method dispatch
- `handle` (:59-69) → `getOrganizationByIdentifier` (:71-74) `Organization::where('identifier', $identifier)->first()`; none → 403 `{"error":"Forbidden"}` (:102-105).
- `handleMethod` (:76-100): GET → `handleGetRequest` (:117-135): `hub_mode === 'subscribe' && hub_verify_token === $organization->identifier` → returns `hub_challenge` as text 200; else 404. POST → requires `json_decode($organization->metadata)` non-empty (:81-85) else 403; X-Hub-Signature-256 verification with `metadata->whatsapp->app_secret` is COMMENTED OUT (:87-94); → `handlePostRequest` (:214-219) → `handleAjaxPostRequest`. Other methods → 405.

## 3. Fan-out (`handleAjaxPostRequest`, WebhookController.php:137-212)
- :139 `$res = $request->entry[0]['changes'][0] ?? null`; only the first entry/change is ever read. null → 200 `{"status":"success"}` (:147-149).
- :151 `field === 'messages'`: `$messages = value.messages`, `$statuses = value.statuses` (:153-154). Statuses → `ProcessMessageStatusJob::dispatch($statuses, $organization->id)->onQueue('messageStatus')` (:157-160). Messages only if `!isLimitReached` (:163) → per message `ProcessIncomingMessageJob::dispatch($message, $res['value']['contacts'][0] ?? null, $organization->id)->onQueue('high')` (:164-170).
- :173 `message_template_status_update` → `ProcessTemplateStatusJob($res['value'], orgId)` on `low`.
- :180 `smb_message_echoes` → per echo `ProcessMessageEchoJob($echo, $res['value'], orgId)` on `high` (:183-189).
- :193 `smb_app_state_sync` → `ProcessContactSyncJob($res['value']['state_sync'] ?? [], orgId)` on `low`.
- :200 `history` → `Log::info` only.
- :205-210 anything else → `ProcessAccountUpdateJob($res, orgId)` on `low`.
- :211 always 200 `{"status":"success"}`.
- `isLimitReached` (:320-324) → `SubscriptionService::isSubscriptionLimitReachedForInboundMessages` (app/Services/SubscriptionService.php:604-632): no subscription → true; plan metadata `receive_messages_after_expiration` truthy → false; `valid_until < now()` → true; else false. One `Subscription::with('plan')` query per webhook.

## 4. ProcessIncomingMessageJob (app/Jobs/ProcessIncomingMessageJob.php)
- Config: `$timeout=120`, `$tries=3`, `$backoff=[1,3,5]` (:28-30). Ctor `($message, $contactData, $organizationId)` (:34-40).
- `handle` (:42-138):
  1. `getOrCreateContact` (:172-225): `$from = message['from']` (:174); missing → `Log::warning` + return null (:176-185) → job exits silently (:56-58). `PhoneService::fromWhatsappId($from)` (:187; app/Services/PhoneService.php:209-221: strip non-digits, `getE164Format('+digits') ?? '+digits'`). `Contact::firstOrCreate(['organization_id','phone'], ['first_name' => contactData['profile']['name'] ?? null, 'last_name'=>null,'email'=>null,'created_by'=>0,'created_at','updated_at'])` (:199-212). MySQL 1062 → re-select by `(organization_id, phone)` (:213-222). Returns `[$contact, $contact->wasRecentlyCreated]` (:224). `uuid` auto-filled by `HasUuid::creating` (app/Http/Traits/HasUuid.php:16-19).
  2. `updateContactNameIfNull` (:235-248): if `trim(first_name)===''` and `contactData.profile.name` non-blank → `$contact->update(['first_name'=>...])`.
  3. `createChat` (:250-284): `$wamId = message['id']` (:252). Cache guard `Cache::has("wam_processed_{$wamId}")` → return null (:255-258). `Chat::create([organization_id, wam_id, contact_id, type:'inbound', metadata: JsonText::encode(ChatMetadataHelper::minimalPayloadForStorage($message)), created_at: now(), status:'delivered', is_read:0])` (:261-270). `Cache::put(..., 3600)` (:272). 1062 → log duplicate, cache, return null (:275-283). `Chat::created` hook (app/Models/Chat.php:21-32) sets `contact.latest_chat_created_at` and, for `type==='inbound'`, `contact.last_inbound_chat_created_at` (this is what opens the 24h window: app/Helpers/MessagingWindowHelper.php:11-48).
  4. `createChatLog` (:301-309): `ChatLog::insertGetId(['contact_id','entity_type'=>'chat','entity_id'=>$chat->id,'created_at'])`.
  5. `ProcessTicketAssignmentJob::dispatchSync($contact->id, orgId, true)` (:69-73) — reads only `organization.metadata.tickets` (app/Jobs/ProcessTicketAssignmentJob.php:47-72); channel-agnostic.
  6. `hasMedia` (:286-292: type ∈ image/video/audio/document/sticker) → `ProcessMediaDownloadJob::dispatch($chat->id, $this->message, orgId, $isNewContact, $contact->uuid)->onQueue('media')` (:78-84).
  7. `SubscriptionService::isSubscriptionFeatureLimitReached(orgId,'message_limit')` (:91; SubscriptionService.php:502-602 counts ALL `chats` rows of the org) → if not reached `maybeSendWorkingHoursAwayNotice` (:311-331): outside hours + cache `working_hours_away_{org}_{contact}` miss → body from `WorkingHoursService::resolveAwayNoticeBody` + `ContactPlaceholderService::replace` → `SendTextMessageJob::dispatch(orgId, contact->uuid, body)->onQueue('high')` (WhatsApp sender) + cache 1h.
  8. `shouldCheckAutoReply` (:294-299: type ∈ text/button/audio/interactive) && limit not reached && inside hours → `ProcessAutoReplyJob::dispatch($chat->id, orgId, $isNewContact)->onQueue('autoreplies')` (:97-107). That job → `AutoReplyService::checkAutoReply($chat)` which reads `chats.metadata` (app/Services/AutoReplyService.php:192-215: text.body / button.payload / interactive.button_reply.title / list_reply.title) and replies via `WhatsappService` built from `metadata.whatsapp.*` (:369-378).
  9. If no media: `event(new NewChatEvent($this->formatChatForEvent($chat,$isNewContact,$contact->uuid), orgId, $isNewContact, false, true))` (:110-116) → push + broadcast; then `WebhookHelper::triggerWebhookEvent('message.received', ['data' => $this->message], orgId)` (:122-126).
  10. Any exception → `Log::error` + rethrow for retry (:129-137).
- `formatChatForEvent` (:333-353): `[[ 'is_new_contact'=>bool, 'contact_uuid'=>uuid, 'type'=>'chat', 'value'=> ChatLog.relatedEntities ?? $chat ]]`; `ChatLog::getRelatedEntitiesAttribute` (app/Models/ChatLog.php:35-54) → `Chat::with('media','user','logs')->find(entity_id)`.

## 5. ProcessMediaDownloadJob (app/Jobs/ProcessMediaDownloadJob.php)
- `$timeout=180`, `$tries=4`, `$backoff=[10,30,60]` (:29-33). Ctor `($chatId, $message, $organizationId, $isNewContact=false, $contactUuid=null)` (:40-47).
- `handle` (:49-120): `Chat::find`; `Organization::find`; `$type = message['type']`, `$mediaId = message[$type]['id']` (:66-75); skip if `chat.media_id` already set (:78-80); `getMedia` (:251-297) = GET `https://graph.facebook.com/v18.0/{mediaId}` with `Bearer metadata->whatsapp->access_token`, `http_errors=false`, throws `RuntimeException` on `error` body (:283-294), returns null when token missing; `downloadMedia` (:167-233) = GET `media.url` with same Bearer (connect 15s / 90s), filename `sha1(content)_time.ext` (:234-249), storage by `Setting storage_system`: `local` → `Storage::disk('local')->put('public/'.$fileName)` and URL `{app.url}/media/public/{file}` (:207-210), `aws` → `uploads/media/received/{orgId}/{rand40}{time}` on `s3` (:211-218); `ChatMedia::create(['name' => document filename or 'N/A', 'path'=>media_url, 'type'=>mime_type, 'size'=>file_size, 'location'=>'local'|'amazon', 'created_at'])` (:101-110); `$chat->update(['media_id'])` (:111); `broadcastChat` (:156-165: NewChatEvent with sendToFirestore=true); outgoing webhook `message.received` (:115-119).
- `failed()` (:126-154): after retries, still broadcasts the chat without media and fires the outgoing webhook so the message is not invisible.

## 6. ProcessMessageStatusJob (app/Jobs/ProcessMessageStatusJob.php)
- `$timeout=60`, `$tries=2`, `$backoff=[5,10,20]` (:26-29). `STATUS_LADDER = accepted, sent, delivered, read, played` (:36), `LADDER_SQL` / `DELIVERED_SQL` `FIELD()` expressions (:38-41).
- `handle` (:52-143): per status: `id` must be non-empty string and `ChatStatus::forStorage(status)` a string, else warn+continue (:63-73); `played`→`delivered` mapping (:77-78; app/Support/ChatStatus.php:23-41); `Chat::where('wam_id',$id)->where('organization_id',...)->first($columns)` (:86-88, columns `id` only unless failed); `applyStatus` (:151-165) does `UPDATE chats SET status=? WHERE id=? AND FIELD(status,...) < rank` (forward-only), `failed` only when `FIELD(status,'delivered','read','played') = 0`; ALWAYS `ChatStatusLog::create(['chat_id','metadata'=>JsonText::encode($status),'created_at'=>$now])` (:94-98); if applied → `NewChatEvent([[ 'type'=>'chat','value'=>$chat->chatLog->relatedEntities,'tempMessageId'=>$status['id'] ]], orgId, false, true)` (:101-109); applied && failed → `CampaignRetryService::handleWebhookFailure($chat->id, $status['errors'] ?? [])` (:111-117; app/Services/CampaignRetryService.php:116-155 marks `campaign_logs.status='failed'`, reopens campaign, schedules retry or moves contact to failed group); failed && `RetryMediaWithTranscodeJob::shouldRetryForChat($chat, errors)` (:119-125; app/Jobs/RetryMediaWithTranscodeJob.php:42-69 requires outbound + media_id + metadata.type video + retryable error code) → dispatch on `high`. After the loop → outgoing webhook `message.status.update` with `['data' => $this->statuses]` (:129-133).

## 7. ProcessMessageEchoJob (app/Jobs/ProcessMessageEchoJob.php) — WhatsApp coexistence
- Ctor `($echo, $value, $organizationId)`. `$recipient = echo['to']` (:47-54) → `getOrCreateContact` by phone (:98-137, same firstOrCreate/1062 pattern); `createChat` (:139-173) `type:'outbound'`, `status:'sent'`, `is_read:1`, `wam_id = echo['id']`, same `wam_processed_` cache; ChatLog (:182-190); media types → `ProcessMediaDownloadJob` else `NewChatEvent(..., $isNewContact, false, false)` (no push) (:70-86). No ticket, no auto-reply, no outgoing webhook, does not open the window (type outbound).

## 8. ProcessContactSyncJob (app/Jobs/ProcessContactSyncJob.php:36-100)
- Entries with `type==='contact'`; `contact.phone_number` → `fromWhatsappId` → `Contact::firstOrCreate` by phone with `first_name = full_name ?? first_name`; fills missing name; `action==='remove'` logged only.

## 9. ProcessTemplateStatusJob (app/Jobs/ProcessTemplateStatusJob.php:30-58)
- `Template::withTrashed()->where('meta_id', templateData['message_template_id'])` → `status = templateData['event']`.

## 10. ProcessAccountUpdateJob (app/Jobs/ProcessAccountUpdateJob.php:31-100)
- `value.event ∈ {PARTNER_REMOVED, ACCOUNT_OFFBOARDED}` → `teardownIntegration` (:102-121): `unset($metadata['whatsapp'])`, soft-deletes all org templates. Otherwise switch on `field`: `account_review_update` → `metadata.whatsapp.account_review_status`; `phone_number_name_update` (APPROVED) → `verified_name`; `phone_number_quality_update` → `messaging_limit_tier`; `business_capability_update` → `max_daily_conversation_per_phone`, `max_phone_numbers_per_business` (:58-77). Saves `organizations.metadata`, forgets `org_settings_{id}` / `org_config_{id}` (:80-85).

## 11. Outgoing customer webhooks (modules/Webhook)
- app/Helpers/WebhookHelper.php:10-22 `triggerWebhookEvent($event, $data, $organizationId=null)` → `CustomHelper::isModuleEnabled('Webhooks', $orgId)` (app/Helpers/CustomHelper.php:35-78: addon row active AND (trial OR plan metadata `addons.Webhooks === true`)) → `new \Modules\Webhook\Controllers\MainController()->trigger($event, $orgId, $data)`. Line 12 has a `=`/`==` typo (`$organizationId = $organizationId = NULL ? … : $organizationId`) which is a no-op; harmless.
- `MainController::trigger` (modules/Webhook/Controllers/MainController.php:375-390): `Webhook::where('organization_id')->whereHas('events', event=?)` → for each `sendNotification($url, ['event'=>$event,'data'=>$data])`; `sendNotification` (:392-410) synchronous `Http::withOptions(curl HTTPS-only)->post()` with NO timeout, exceptions swallowed. Runs inside the queue job, so slow customer endpoints stall `high`/`messageStatus` workers.
- Event catalogue (:26): `message.received, message.sent, message.status.update, contact.created, contact.updated, contact.deleted, group.created, group.updated, group.deleted, autoreply.created, autoreply.updated, autoreply.deleted`. Other call sites: app/Services/WhatsappService.php:348,530,1042 (`message.sent`), app/Services/ContactService.php:201,262, app/Http/Controllers/ApiController.php:497,556,870,905, app/Http/Controllers/User/ContactGroupController.php:155,181,223, app/Services/AutoReplyService.php:98,109.
- Tables: modules/Webhook/Database/Migrations/2024_09_26_080857_create_webhooks_table.php: `webhooks(id, uuid, organization_id, url, timestamps, UNIQUE(organization_id,url))`, `webhook_events(id, webhook_id FK cascade, event, timestamps)`. Routes modules/Webhook/routes.php:5-12 (`/developer-tools/webhooks` CRUD, `/webhooks/trigger/{event}` and `/{event}/test`).

## 12. Queues / infra
- config/queue.php:16 default connection `database` (`jobs` table), `retry_after` 660s (:43), `after_commit=false` (:44). Queue names used app-wide: `high` (11 sites incl. NewChatEvent `$queue='high'` app/Events/NewChatEvent.php:38 and SendNewChatPushNotificationJob app/Jobs/SendNewChatPushNotificationJob.php:44), `low` (3), `media` (2), `messageStatus` (1), `autoreplies` (1); campaigns use `campaign-messages`/`campaign-logs` (app/Console/Kernel.php:56 commented). No supervisor/worker config lives in the repo.
- Broadcast: `NewChatEvent` (app/Events/NewChatEvent.php:60-95) builds the minimal payload at construct time via `ChatBroadcastPayloadBuilder::buildWrappedChat`, extracts `contact_id`, skips unusable chats, and when `sendToFirestore` dispatches `SendNewChatPushNotificationJob(orgId, contactId, value)`; channels `chats.ch{org}.{userId}` resolved by `ChatChannelRecipients::resolveUserIds` (app/Services/Chat/ChatChannelRecipients.php:31-72).

## whatsapp_assumptions

## Routing / controller
- routes/web.php:90 — URL literally `/webhook/whatsapp/{identifier?}`; org resolved from URL segment (Meta lets each org have its own callback URL). Messenger/Instagram apps have ONE app-level callback, so org must be resolved from the payload (`entry[].id` = Page ID / IG account id), not the URL.
- routes/web.php:91 — `/webhook/waba` shared verification uses Setting `whatsapp_callback_token` (WebhookController.php:46).
- routes/web.php:92 — `/webhook/{processor}` catch-all (payments) after the WhatsApp route; a one-segment `/webhook/telegram` would be routed to `PaymentPlatformResolver` and throw (app/Resolvers/PaymentPlatformResolver.php:15).
- WebhookController.php:48-52, :122-126 — `hub_mode` / `hub_verify_token` / `hub_challenge` Meta handshake; verify token = `organization.identifier`. Messenger/Instagram use the identical handshake; Telegram (`setWebhook` + `X-Telegram-Bot-Api-Secret-Token` header) and TikTok (signature header) do not.
- WebhookController.php:81-85 — POST requires non-empty `organizations.metadata` JSON; semantically "has a WhatsApp integration".
- WebhookController.php:87-94 — (disabled) HMAC check with `metadata->whatsapp->app_secret` and header `X-Hub-Signature-256`.
- WebhookController.php:139 — `entry[0].changes[0]` Meta/WABA envelope; ignores extra entries/changes. Messenger and Instagram messaging use `entry[].messaging[]` (not `changes`), Telegram posts a flat `Update` object (`update_id`, `message`, `edited_message`, `callback_query`…), TikTok its own envelope.
- WebhookController.php:151-171 — `field === 'messages'`, `value.messages[]`, `value.statuses[]`, `value.contacts[0]` (profile block passed to the job).
- WebhookController.php:173-209 — WhatsApp-only field names `message_template_status_update`, `smb_message_echoes` (`value.message_echoes`), `smb_app_state_sync` (`value.state_sync`), `history`; the `else` branch sends ANY unknown field to `ProcessAccountUpdateJob`, which writes `metadata.whatsapp.*` and can tear down the WhatsApp integration — dangerous if non-WhatsApp payloads ever reach this controller.
- WebhookController.php:221-311 — dead-code `downloadMedia`/`getMedia` using `metadata->whatsapp->access_token` and `https://graph.facebook.com/v18.0/{mediaId}` (:305).
- WebhookController.php:320-324 — inbound subscription gate is channel-agnostic (reusable).

## ProcessIncomingMessageJob (app/Jobs/ProcessIncomingMessageJob.php)
- :34 — ctor `($message, $contactData, $organizationId)`; `$contactData` is the WhatsApp `contacts[0]` block (`profile.name`, `wa_id`). Test files pin this arity and the private method names via reflection (tests/Feature/ContactWithoutPhoneTest.php:100-110, tests/Feature/ContactNameCaptureTest.php:56-64).
- :132, :179, :192, :252 — `message['id']` is the message identity (`wamid.…`).
- :174 — `message['from']` is the sender identity and is a phone number (wa_id).
- :180, :289, :296 — `message['type']` WhatsApp enum: text, image, video, audio, document, sticker, location, contacts, button, interactive, reaction, system, edit, revoke, unsupported, template, order.
- :187 — `PhoneService::fromWhatsappId($from)` (app/Services/PhoneService.php:209-221) converts to `+E164`; a Telegram chat id / Messenger PSID / IGSID / TikTok user id has no phone.
- :199-212 — contact identity = `Contact::firstOrCreate(['organization_id','phone'])`. With `phone => null` Eloquent emits `WHERE phone IS NULL`, so ALL phone-less contacts of the org collapse into the first one (the exact production bug described at tests/Feature/ContactWithoutPhoneTest.php:26-31, 121-135). Cannot be reused for channel contacts.
- :205, :241 — `contactData['profile']['name']` fills `first_name`.
- :213-222 — 1062 recovery re-selects by `(organization_id, phone)`.
- :252-283 — `chats.wam_id` = `message['id']`; unique index `chats_wam_id_active_unique` on the virtual column `wam_id_active_key` is GLOBAL (not scoped by organization or channel; database/migrations/2026_05_31_000002_add_virtual_unique_indexes_for_contacts_and_chats.php:19-27). Telegram `message_id` is a small per-chat sequential integer → guaranteed collisions across contacts/orgs → silently treated as duplicate (:277-281) = lost messages. `wam_id` is VARCHAR(128) (database/migrations/2024_03_20_051154_create_chats_table.php); Instagram/Messenger `mid` values are long base64 strings that may exceed 128.
- :255, :272, :279 — cache key `wam_processed_{wamId}` shares one global namespace across orgs/channels.
- :265-266 — `metadata` = `ChatMetadataHelper::minimalPayloadForStorage($message)`; shape is the WhatsApp type-keyed shape consumed by Vue ChatBubble, mobile app, `AutoReplyService::extractMessageText` (app/Services/AutoReplyService.php:192-215), `ChatNotificationContentBuilder` (app/Services/Chat/ChatNotificationContentBuilder.php:55-121) and `RetryMediaWithTranscodeJob` (`metadata.type === 'video'`).
- :268 — inbound rows are stored with `status = 'delivered'`.
- :286-292 — media detection by WhatsApp type list; media payload must carry `message[$type]['id']` (Graph media id) for the download job.
- :294-299 — auto-reply trigger types `text/button/audio/interactive`.
- :325-329 — away notice uses `SendTextMessageJob` → `WhatsappService` (WhatsApp send).
- :122-126 — outgoing webhook `message.received` payload = raw WhatsApp message object under `data`.
- :69-73 ticket assignment, :91 `message_limit`, :301-309 chat log — channel-agnostic.

## ChatMetadataHelper (app/Helpers/ChatMetadataHelper.php)
- :20-25 — keyed on WhatsApp `type`.
- :28-36 `text.body` (+`header`, `buttons`); :38-40 `button.text/payload`; :42-44 `interactive` raw (`button_reply`/`list_reply`); :46-70 media blocks keep only `caption` / `voice` / `filename`; :72-87 `location.{latitude,longitude,name,address,url}` + `context.id`; :89-102 `contacts[]` minus `vcard`; :104-106 `template`; :108-112 `unsupported.errors`; :120-126 `system.{body,type,wa_id}` (number-change notice); :128-138 `edit.{original_message_id,message}`; :140-145 `revoke.original_message_id`; :147-148 DEFAULT returns the RAW message (including `from`, `id`, `timestamp`) — a non-WhatsApp payload with an unknown `type` would be stored raw and could leak platform ids/tokens; :151-153 preserves `transcript`.
- :164-179 `minimalMediaBlock` drops `id/url/sha256/mime_type` on purpose (file comes from `chat_media`).

## ProcessMediaDownloadJob (app/Jobs/ProcessMediaDownloadJob.php)
- :30-33 comments and retry tuning assume WhatsApp media URL expiry.
- :66-67 — `message[$type]['id']` Graph media id.
- :82-84, :251-297 — `GET https://graph.facebook.com/v18.0/{mediaId}` (:272) with `metadata->whatsapp->access_token` (:253-256, :263); response fields `url`, `mime_type`, `file_size`; error object `error.{message,code,error_subcode}` (:283-294, message text says "WhatsApp error").
- :177-181, :187 — download URL fetched with the WhatsApp bearer token (Messenger/Instagram attachment URLs are unauthenticated CDN links; Telegram needs `getFile` + `api.telegram.org/file/bot{token}/{path}`).
- :102-104 — `message['document']['filename']`.
- :106-107 — `chat_media.type = mime_type`, `size = file_size` from the Graph media object.
- :198-232 storage logic (local/S3) is channel-agnostic and reusable.

## ProcessMessageStatusJob (app/Jobs/ProcessMessageStatusJob.php)
- :32-41 — status vocabulary `accepted/sent/delivered/read/played/failed` and WhatsApp lifecycle ordering.
- :63, :86-88 — `status['id']` is a wam_id looked up on `chats.wam_id` + org.
- :77-78 — `ChatStatus::forStorage` (app/Support/ChatStatus.php:15 `SUPPORTED = accepted,sent,delivered,read,failed`, :23-25 alias `played→delivered`) — mobile app rejects anything else.
- :96 — `chat_status_logs.metadata` = raw WhatsApp status object (`id,status,timestamp,recipient_id,conversation,pricing,errors`), later trimmed to `status,errors,id` by `ChatBroadcastPayloadBuilder::minimalLogs` (app/Services/Chat/ChatBroadcastPayloadBuilder.php:34, :280-302).
- :106 — `tempMessageId => $status['id']`.
- :116, :121 — `status['errors'][].code` are WhatsApp error codes consumed by `CampaignRetryService::isRetryableFailure` and `RetryMediaWithTranscodeJob::RETRYABLE_ERROR_CODES`.
- :129-133 — outgoing webhook `message.status.update` = raw statuses array.

## Other jobs (WhatsApp-only by nature)
- ProcessMessageEchoJob.php:47 `echo['to']` phone; :100 `fromWhatsappId`; :112-125 firstOrCreate by phone; :141 `wam_id = echo['id']`; :153 `minimalPayloadForStorage`; :177-179 media type list. Coexistence feature — keep untouched.
- ProcessContactSyncJob.php:45 `contact.phone_number`; :65 `fromWhatsappId`; :74 `full_name/first_name`; :77-90 firstOrCreate by phone.
- ProcessTemplateStatusJob.php:36 `message_template_id` ↔ `templates.meta_id`; :40 `event` → `templates.status`. Templates are a WhatsApp concept.
- ProcessAccountUpdateJob.php:34 `field`; :49-55 `PARTNER_REMOVED`/`ACCOUNT_OFFBOARDED`; :58-77 writes `metadata['whatsapp'][account_review_status|verified_name|messaging_limit_tier|max_daily_conversation_per_phone|max_phone_numbers_per_business]`; :104-106 `unset($metadata['whatsapp'])`; :111-113 soft-deletes all templates.

## Shared collaborators touched by this flow
- app/Models/Chat.php:27-29 — `type === 'inbound'` updates `last_inbound_chat_created_at`; app/Helpers/MessagingWindowHelper.php:11 `WINDOW_HOURS = 24` hard-coded (WhatsApp/Messenger/Instagram = 24h customer-service window; Telegram has no window; TikTok has its own — must become per-channel).
- app/Services/Chat/ChatBroadcastPayloadBuilder.php:128-129, :340-346, :366-368 — broadcast value carries `phone` and `formatted_phone_number` from the contact; app/Services/Chat/ChatNotificationContentBuilder.php:42-44 push title falls back to phone when name is missing; app/Jobs/SendNewChatPushNotificationJob.php:74 `'phone' => …` in FCM data. For channel contacts these are null/empty.
- app/Models/Contact.php:23 appends `formatted_phone_number` (:375-388) — null-safe already.
- app/Models/Organization.php:24-47 `toArray` redacts only `whatsapp.access_token` and `ai.api_key`; any new channel token stored in `metadata` would leak to Inertia/mobile unless added here.
- app/Services/SubscriptionService.php:583-585 `message_limit` counts every `chats` row of the org (channel-blind).
- modules/Webhook/Controllers/MainController.php:97-229 — the "test" payloads sent to customers are WhatsApp-shaped (`messaging_product: whatsapp`, `wa_id`, `wamid.`, `phone_number_id`); customers' integrations were built against this.
- tests/Feature/Cycle/CycleTestCase.php:72-80, :115-140 — fixtures assume `metadata.whatsapp` and `from`-based messages.
- config/queue.php:41-43 comment references ProcessMediaDownloadJob 300s (actual is 180s).

## required_changes

## A. Ingress (additive; WhatsApp route untouched)
1. routes/web.php — insert BEFORE line 92 (`/webhook/{processor}` catch-all): `/webhook/telegram/{identifier}`, `/webhook/messenger`, `/webhook/instagram`, `/webhook/tiktok` (GET+POST) → a new `ChannelWebhookController@handle($channel, $identifier = null)` (or one controller per channel). Never reuse `WebhookController::handle` for other channels: its `field` switch would misroute payloads and its `else` branch mutates `metadata.whatsapp` (ProcessAccountUpdateJob). CSRF is already exempt (`webhook/*`).
2. Org resolution per channel: Telegram → `identifier` in URL (like WhatsApp) plus header `X-Telegram-Bot-Api-Secret-Token` compared to a per-org secret; Messenger/Instagram → app-level URL, org looked up by `entry[].id` (Page ID / IG account id) and the Meta `hub_*` handshake with a per-app verify token; TikTok → per their signature scheme. Add a small lookup table `organization_channels (id, organization_id, channel, external_account_id, credentials JSON, is_active, timestamps, UNIQUE(channel, external_account_id))` rather than scanning `organizations.metadata` JSON on every webhook. Keep WhatsApp config where it is (`metadata.whatsapp`) — do not migrate it.
3. Enable HMAC/signature verification for the new channels (the WhatsApp check at WebhookController.php:87-94 is disabled; leave it as-is).
4. Reuse `SubscriptionService::isSubscriptionLimitReachedForInboundMessages` (SubscriptionService.php:604-632) as the inbound gate; it is channel-agnostic.
5. Always answer 200 quickly and defer all work to jobs, exactly like `handleAjaxPostRequest` (Telegram and TikTok also retry on non-2xx).

## B. Channel driver abstraction (new, e.g. `app/Channels/`)
6. `ChannelDriver` interface with: `verifyRequest(Request, ?Organization)`, `parse(array $payload, Organization): ParsedWebhook` (lists of `InboundMessage`, `StatusUpdate`, `AccountEvent`), `fetchMedia(InboundMessage $m, Organization $o): ?MediaFile` (`{stream|bytes, mime_type, file_size, filename}`), `fetchProfile(externalId, Organization): ?array{name, username, avatar}`, `messagingWindowHours(): ?int`. `WhatsappDriver` is a thin wrapper around the existing code and must not change WhatsApp behavior.
7. `InboundMessage` DTO: `channel`, `externalMessageId` (already namespaced, see D), `externalContactId`, `profileName`, `type` (normalized to the existing vocabulary text/image/video/audio/document/sticker/location/contacts/button/interactive/unsupported), `metadata` (already in the exact `chats.metadata` shape `ChatMetadataHelper` produces — see data_shapes), `mediaRef` (platform id or URL), `timestamp`, `raw`. `StatusUpdate` DTO: `externalMessageId`, `status ∈ accepted|sent|delivered|read|failed`, `errors[] {code,title,message}`, `raw`.
8. Type/status mapping lives in each driver: Telegram `message.text`→text, `photo[]`→image, `video`, `voice`/`audio`→audio (`voice:true` for voice), `document`, `sticker`, `location`/`venue`→location, `contact`→contacts, `callback_query`→interactive `button_reply {id: data, title}`; Messenger/IG `message.text`, `attachments[].type image|video|audio|file`→document, `quick_reply.payload`/`postback`→button `{text: title, payload}`, `message.reply_to`→`context.id`; TikTok per docs. Statuses: Telegram none (treat send success as `sent`); Messenger/IG `message_deliveries`→delivered, `message_reads`→read, `message_echoes`→ignore or map to echo; TikTok per docs.

## C. Inbound persistence — extract a shared pipeline, keep the WhatsApp job byte-for-byte
9. Extract from `ProcessIncomingMessageJob::handle` (:63-127) an `InboundMessagePipeline::afterChatCreated(Chat, Contact, bool $isNew, InboundMessage)` service that performs: chat log, `ProcessTicketAssignmentJob::dispatchSync`, media dispatch, away notice, auto-reply dispatch, `NewChatEvent`, outgoing webhook. `ProcessIncomingMessageJob` keeps its constructor arity (3) and private method names `getOrCreateContact`/`updateContactNameIfNull` (tests pin them via reflection and source checks: tests/Feature/ContactWithoutPhoneTest.php:246-262, tests/Feature/ProductionErrorFixesTest.php:42-57) and delegates to the pipeline. Add a NEW `ProcessChannelInboundMessageJob(InboundMessage $dto, int $organizationId)` on queue `high` for the other channels using the same pipeline. Jobs already serialized in the `jobs` table keep deserializing because no WhatsApp ctor signature changes.
10. Contact identity for channels: `Contact::firstOrCreate(['organization_id'=>…, 'channel'=>…, 'external_id'=>…], ['phone'=>null, 'first_name'=>profileName, 'created_by'=>0, …])` with the same 1062 re-select pattern (by org+channel+external_id). NEVER key on `phone` when it is null (ContactWithoutPhoneTest.php:121-135).
11. Schema (additive, two options; decide with the DB plan):
    - Option 1 (columns): `contacts.channel VARCHAR(16) NOT NULL DEFAULT 'whatsapp'`, `contacts.external_id VARCHAR(191) NULL`, virtual `external_id_active_key = IF(deleted_at IS NULL, external_id, NULL)` + `UNIQUE(organization_id, channel, external_id_active_key)` mirroring database/migrations/2026_05_31_000002 (:12-17). `chats.channel VARCHAR(16) NOT NULL DEFAULT 'whatsapp'` for the chat-list filter. `contacts` has a FULLTEXT index (database/migrations/2025_01_24_091115_add_fulltext_index_to_contacts_table.php), which blocks `ALGORITHM=INSTANT`, so the contacts ALTER is an online INPLACE rebuild of 817K rows — run with explicit `ALGORITHM=INPLACE, LOCK=NONE` in a low-traffic window; `chats` (3.2M) ADD COLUMN with DEFAULT should be INSTANT (verify by passing `ALGORITHM=INSTANT` so MySQL refuses instead of silently rebuilding).
    - Option 2 (side table, zero ALTER on big tables): `contact_channels (id, contact_id UNIQUE, organization_id, channel, external_id, profile JSON, UNIQUE(organization_id, channel, external_id))`; WhatsApp contacts have no row and are `whatsapp` by absence. Chat-list channel filter then needs a join or a `chats.channel` column anyway. Option 1 is simpler for the filter and the mobile API; Option 2 is safer for the production ALTER.
12. Message identity: `chats.wam_id` stays the column (rename is not additive) but channel drivers MUST namespace ids because the unique index is global: e.g. `tg:{chat_id}:{message_id}`, `fb:{mid}`, `ig:{mid}`, `tt:{id}`; Messenger/IG `mid` can be long — either widen `wam_id`/`wam_id_active_key` to 191/255 (index rebuild on 3.2M rows) or store `fb:` + sha1(mid) when `strlen > 120` (no schema change; lookups on status must hash the same way). Keep the `wam_processed_{id}` cache key — it becomes safe once ids are namespaced.
13. `ChatMetadataHelper::minimalPayloadForStorage` stays WhatsApp-only; channel drivers emit metadata already in that shape so ChatBubble, mobile app, AutoReplyService and push builder work unchanged. Add a defensive `ChatMetadataHelper::assertNormalized()` or make the channel job never call the raw-passthrough default (:147-148).
14. Auto-reply / away notice senders: `ProcessAutoReplyJob` → `AutoReplyService::initializeWhatsappService` (app/Services/AutoReplyService.php:369-378) and `SendTextMessageJob` (ProcessIncomingMessageJob.php:325-329) are WhatsApp senders. The pipeline must resolve a sender by `contact.channel` (outbound subsystem's `MessageSender` abstraction) — the inbound side only needs to pass the channel through.
15. Outgoing customer webhooks: keep WhatsApp payloads identical. For other channels send `['channel' => 'telegram', 'data' => <raw platform message>]` under the same event names (`message.received`, `message.status.update`), and add `channel` to the docs/test payloads in modules/Webhook/Controllers/MainController.php:97-229. Consider dispatching `sendNotification` to a queue with a timeout (currently synchronous with no timeout, :396-402) — pre-existing risk that new channels multiply.

## D. Media
16. Extract `downloadMedia`'s storage half (ProcessMediaDownloadJob.php:198-232 + `generateFilename` :234-249) into `MediaStorageService::store(string|StreamInterface $content, string $mime, int $orgId): array{media_url, location}`; `ProcessMediaDownloadJob` keeps its Graph fetch and calls the service (no behavior change). New `ProcessChannelMediaDownloadJob($chatId, $channel, $mediaRef, $orgId, $isNew, $contactUuid)` on queue `media` that calls `driver->fetchMedia()` then the same service and writes the same `chat_media` row shape and the same `broadcast + failed()` semantics.
17. Telegram: `getFile(file_id)` → `https://api.telegram.org/file/bot{token}/{file_path}`, mime from `file_path` extension (Telegram gives no mime for photos). Messenger/IG: `attachments[].payload.url` direct GET (IG may require the page token). TikTok: per docs. Keep `chat_media.name` = filename or `'N/A'`.

## E. Status updates
18. Feed normalized `StatusUpdate`s into the EXISTING `ProcessMessageStatusJob` (it needs only `id`, `status`, optional `errors[]`): `ProcessMessageStatusJob::dispatch([['id'=>'fb:'.$mid,'status'=>'read',...]], $orgId)->onQueue('messageStatus')`. The ladder, the log, the broadcast and campaign-retry hook then work unchanged. `CampaignRetryService::isRetryableFailure` and `RetryMediaWithTranscodeJob` key on WhatsApp error codes; drivers should map platform errors to `{code, title, message}` and the campaign subsystem decides retryability per channel.

## F. Profiles, window, credentials, API docs
19. Profile names: Telegram from `from.first_name/last_name/username`; Messenger/IG via Graph `/{psid}?fields=first_name,last_name,profile_pic` with the page token (do it inside the job with a cache, never in the controller). Reuse `updateContactNameIfNull` semantics (fill only blank names).
20. `MessagingWindowHelper::WINDOW_HOURS` → `MessagingWindowHelper::hoursFor(string $channel): ?int` (WhatsApp 24, Messenger 24, Instagram 24, Telegram null = always open, TikTok per docs); `Contact.last_inbound_chat_created_at` already updates for any inbound row (Chat.php:27-29), so only the helper needs the channel.
21. Credentials: `organizations.metadata.telegram|messenger|instagram|tiktok` (or `organization_channels.credentials`); extend `Organization::toArray` (app/Models/Organization.php:32-35) to redact every new token like `whatsapp.access_token` and expose `*_is_set` booleans.
22. Mobile/web contracts (document for the mobile dev): contacts gain `channel`, `external_id`; chats gain `channel`; broadcast minimal value (ChatBroadcastPayloadBuilder.php:122-146) gains `channel` and `contact_external_id`; `phone`/`formatted_phone_number` can be null for channel contacts; push `additionalData` (SendNewChatPushNotificationJob.php:72-78) gains `channel`; `ChatNotificationContentBuilder::buildTitle` (:42-44) needs a fallback to username/external id when both name and phone are empty.
23. Queues: reuse `high`, `messageStatus`, `media`, `low`, `autoreplies` so no supervisor change is needed; only introduce channel-specific queues if isolation is required (that needs a worker config change outside the repo).

## Already channel-agnostic (leave as-is)
- `ProcessTicketAssignmentJob`, `ChatLog`/`ChatStatusLog`/`ChatMedia` models and tables, `chat_logs` insert, `ProcessMessageStatusJob` ladder/logging/broadcast (given namespaced ids), `NewChatEvent` + `ChatBroadcastPayloadBuilder` (except phone fallbacks), `ChatChannelRecipients`, `SendNewChatPushNotificationJob`, `SubscriptionService` gates, `WebhookHelper`/`MainController::trigger`/`sendNotification`, `webhooks`/`webhook_events` tables, `config/queue.php`, CSRF exemption, `WorkingHoursService`, `ContactPlaceholderService`, `Chat::created` hook, `HasUuid`.

## risks

- **Contact collapse on NULL phone**: any `firstOrCreate` that includes `phone => null` matches `WHERE phone IS NULL` and returns the first phone-less contact of the org (production incident documented at tests/Feature/ContactWithoutPhoneTest.php:26-31,121-135). Channel contacts must be keyed on `(organization_id, channel, external_id)` with its own unique index; otherwise Telegram/Messenger users share one thread.
- **Silent message loss via global `wam_id` uniqueness**: `chats_wam_id_active_unique` is on `wam_id` alone (database/migrations/2026_05_31_000002:22-27) and the 1062 branch (ProcessIncomingMessageJob.php:275-283) treats a duplicate as "already processed" and returns success — no error, no retry. Telegram `message_id`s (1,2,3…) collide across every chat and org. The `wam_processed_{id}` cache (:255,:272) has the same global scope. Namespace ids before anything else.
- **`wam_id` length**: VARCHAR(128); an Instagram/Messenger `mid` longer than 128 chars makes `Chat::create` fail 3× under strict mode and the message ends in `failed_jobs`. Hash or widen.
- **Production ALTERs**: `contacts` (817K, has FULLTEXT index → INSTANT not allowed) and `chats` (3.2M). ADD COLUMN with DEFAULT on `chats` should be INSTANT; on `contacts` it is an online INPLACE rebuild. Adding a UNIQUE index on a new virtual column is INPLACE with a brief metadata lock at start/end. Run with explicit `ALGORITHM=…, LOCK=NONE` so MySQL refuses rather than silently copying; never use doctrine `->change()` on these tables (the repo already avoids it: database/migrations/2026_07_29_013859_change_chats_metadata_to_mediumtext.php comment). Existing rows get `channel='whatsapp'` by default — no backfill needed.
- **Misrouted payloads**: if a Messenger/Instagram/TikTok app is ever pointed at `/webhook/whatsapp/{id}`, `entry[0].changes[0]` might parse (Instagram comment webhooks use `changes[]`) and unknown `field`s fall into `ProcessAccountUpdateJob`, which writes `metadata.whatsapp.*` and, on `value.event ∈ {PARTNER_REMOVED, ACCOUNT_OFFBOARDED}`, deletes the WhatsApp integration and soft-deletes all templates (ProcessAccountUpdateJob.php:49-55,102-121). Keep channel ingress on separate routes and never share the fan-out switch.
- **Route shadowing**: new one-segment routes placed after routes/web.php:92 are swallowed by `/webhook/{processor}` and throw in `PaymentPlatformResolver`.
- **Token leakage**: `Organization::toArray` (app/Models/Organization.php:24-47) redacts only `whatsapp.access_token`; new bot/page tokens in `metadata` would be shipped to Inertia props and mobile API responses unless redacted.
- **Outgoing customer webhooks**: customers parse `message.received`/`message.status.update` `data` as WhatsApp objects; sending Telegram raw payloads under the same event without a `channel` discriminator breaks their integrations. Also `sendNotification` is synchronous with no timeout (MainController.php:396-402) inside `high`/`messageStatus` workers — more channels = more stalls.
- **Push/broadcast assumptions**: `phone` null and `contact_full_name` null yields an empty FCM title (ChatNotificationContentBuilder.php:42-44); `ChatBroadcastPayloadBuilder::buildMinimalValue` returns `[]` when the chat cannot be resolved and the event skips broadcasting — keep chat + chat_log creation ordering identical (chat_log inserted before the event).
- **Ordering / concurrency**: jobs run in parallel on `high`; the ladder guard in `applyStatus` (ProcessMessageStatusJob.php:151-165) is the only protection against out-of-order statuses — reuse it, do not write statuses directly. `after_commit=false` (config/queue.php:44) means dispatched jobs can run before an enclosing transaction commits; the inbound path does not use a transaction today, keep it that way or add `afterCommit()`.
- **Retries and duplicates from platforms**: Meta, Telegram and TikTok all redeliver on non-2xx or slow responses; idempotency relies solely on the `wam_id` unique index + cache. Media and status handlers are idempotent (`media_id` already set check at ProcessMediaDownloadJob.php:78-80; ladder). Outgoing customer webhooks and `NewChatEvent` are NOT idempotent — a re-run of the job after a mid-way crash re-fires them (pre-existing).
- **Subscription accounting**: `message_limit` (SubscriptionService.php:583) counts all `chats`; new channels will consume WhatsApp-priced limits unless the plan logic is made channel-aware.
- **Test pins**: source-string and reflection tests lock the private method names, ctor arity and the `PhoneService::fromWhatsappId(` call in the three WhatsApp jobs (ContactWithoutPhoneTest.php:246-262, ProductionErrorFixesTest.php:42-57, ContactPlaceholderEmptyValueTest.php:250-265, BroadcastProviderTest.php:278). Refactors that move that code into a service will fail these tests unless updated deliberately.
- **Telegram has no delivery/read receipts**: campaign reporting that expects `delivered/read` counts must treat `sent` as terminal for Telegram or the campaign UI shows 0% delivered.

## tests

## Existing coverage
- tests/Feature/Cycle/CycleTestCase.php (:28-166): fixture — org with `identifier` + `metadata.whatsapp` (:69-81), owner team, `storage_system=local`, `whatsapp_callback_token`, plan `message_limit=-1` + active subscription; helpers `webhookUrl()`, `payload($field,$value)` (Meta envelope :116-128), `textMessage()` (:131-140), `contact()`, `chat()`. Runs FlowBuilder migrations before the transaction (:46-60).
- tests/Feature/Cycle/WebhookIntakeTest.php (:24-364): GET verification with org identifier / wrong token / wrong mode (:29-46), `/webhook/waba` global token OK and refused (:49-61), unknown identifier 403 + nothing dispatched (:65-74), org without metadata 403 (:77-87), PUT 405 (:89-92), payload without `changes` / empty body → 200 and nothing dispatched (:97-115), one `ProcessIncomingMessageJob` per message on queue `high` (:119-156), statuses → `ProcessMessageStatusJob` on `messageStatus` (:160-170), both in one payload (:173-184), expired subscription stops messages but not statuses (:196-209), `receive_messages_after_expiration` (:212-228), no subscription → nothing (:231-245), template/echo (2 jobs)/empty echo/contact-sync/history (no job)/unknown field → account job (:274-342), job carries the URL's org (:345-363).
- tests/Feature/Cycle/InboundMessageCycleTest.php (:18-242, sync queue end-to-end): contact+chat+chat_log created with E164 phone and profile name (:33-55), body stored (:57-65), `is_read=0` (:67-72), same number → one contact / two chats (:75-82), two numbers → two contacts (:85-91), same `wam_id` stored once (:99-107), no `from` → nothing created (:112-120), libphonenumber-rejected number stored as `+digits` (:123-131), dataProvider of 10 types asserting stored `metadata.type` and media job dispatched only for image/video/audio/document/sticker (:143-179), inbound opens the 24h window (:188-198), org isolation and same number in two orgs (:203-241).
- tests/Feature/Cycle/MessageStatusCycleTest.php (:15-229): status applied (:31-38), full ladder (:40-48), late `delivered`/`sent` never regress `read` (:55-64), late `failed` never overwrites delivered/read/played (:67-76), `failed` recorded on `sent` (:79-86), idempotent repeat (:89-97), several statuses in one payload (:101-115), unknown wam_id ignored (:120-127), cross-org isolation (:130-143), one `ChatStatusLog` per report including unapplied ones (:149-172), missing status / missing id do not 500 and neighbours still apply (:182-213), inbound + status in one payload (:216-228).
- tests/Feature/Cycle/InboundSideEffectsTest.php (:23-271): no ticket when feature off (:36-41), ticket opened once when `metadata.tickets.active` (:44-68), `ProcessAutoReplyJob` dispatched on `autoreplies` (:81-89) and not when `message_limit=0` (:97-110), `NewChatEvent` dispatched for text (:115-122) and NOT before media download (:125-137), away notice at most once per hour / never inside hours (:146-167), echo lands as outbound with its wam_id (:210-227), echo creates the contact (:229-241), repeated echo stored once (:244-255), echo does not open the window (:258-270).
- tests/Unit/ChatMetadataMinimizationTest.php (:18-195): `system`/`edit`/`revoke` minimization with real production payloads, text/location/button unchanged, unknown type (`order`) and `reaction` pass through raw, payload without type untouched, JSON round-trip.
- tests/Unit/LocationRequestMessageTest.php:116-155: location minimization keeps lat/long/name/address and `context.id`.
- tests/Feature/ContactNameCaptureTest.php:54-155: `updateContactNameIfNull` via reflection — blank/whitespace/null filled, existing name kept, exact UPDATE counts (0 or 1).
- tests/Feature/ContactWithoutPhoneTest.php:52-93 (`PhoneService::fromWhatsappId`), :97-159 (`getOrCreateContact` via reflection: rejected wa_id still has a phone, two rejected senders stay separate, valid sender idempotent, no sender → null), :246-262 (source guard: the three inbound jobs call `fromWhatsappId`).
- tests/Feature/ProductionErrorFixesTest.php:42-92: no-sender payload skipped (source string checks + `handle()` behaviour with `Log::spy`).
- tests/Feature/ContactPlaceholderEmptyValueTest.php:250-265: regex source guard on the away-notice empty-body early return in ProcessIncomingMessageJob.
- tests/Feature/BroadcastProviderTest.php:278: WebhookController must call `BroadcastProvider::apply()` (source check).
- tests/Unit/MyFatoorahWebhookSignatureTest.php: payment webhook signature (route `/webhook/{processor}`), not messaging.

## Gaps
- `ProcessMediaDownloadJob::handle` is never executed (always `Bus::fake`d); no test fakes Graph HTTP, storage writes, `chat_media` row shape, `failed()` fallback broadcast, or the `media_id`-already-set idempotency.
- `ProcessAccountUpdateJob`, `ProcessTemplateStatusJob`, `ProcessContactSyncJob` `handle()` bodies are untested (only dispatch is asserted), including the destructive teardown path.
- Outgoing Webhook module: no tests for `trigger` event filtering per org, `sendNotification`, module gating (`isModuleEnabled`), or that `message.received` / `message.status.update` are fired with the raw payload.
- `NewChatEvent` content from inbound is never asserted (only `assertDispatched`); no test for `ChatBroadcastPayloadBuilder` output from an inbound chat, nor for FCM push data.
- `chat_status_logs.metadata` content and `ChatStatusLog` → `minimalLogs` trimming untested.
- No concurrency test for the 1062 recovery branches (contact and chat) — only sequential duplicates.
- No test guards route ordering (`/webhook/{processor}` after channel routes) or the `/webhook/waba` POST no-op.
- No test for `wam_id` length overflow or cross-org `wam_id` collision behaviour (silent skip).
- No tests for `MessagingWindowHelper` beyond "opens on inbound"; window length is a constant.
- For multi-channel work: create `tests/Feature/Cycle/Channels/{Telegram,Messenger,Instagram,TikTok}*CycleTest.php` mirroring InboundMessageCycleTest/MessageStatusCycleTest/InboundSideEffectsTest with per-channel payload builders, plus a regression run of the four existing Cycle tests to prove the WhatsApp path is byte-identical (same rows, same queues, same events).

## data_shapes

## 1. Consumed: WhatsApp webhook envelope (WebhookController.php:139-209)
```
{ "object":"whatsapp_business_account",
  "entry":[{ "id":"<WABA id>", "changes":[{ "field": "messages" | "message_template_status_update" | "smb_message_echoes" | "smb_app_state_sync" | "history" | "<account field>",
    "value":{
      "messaging_product":"whatsapp", "metadata":{"display_phone_number":"…","phone_number_id":"…"},
      "contacts":[{"profile":{"name":"…"},"wa_id":"966502486051"}],
      "messages":[{ "from":"966502486051","id":"wamid.…","timestamp":"1727857481","type":"text|image|video|audio|document|sticker|location|contacts|button|interactive|reaction|system|edit|revoke|unsupported|template|order",
        "text":{"body":"…"}, "image":{"id","mime_type","sha256","caption"}, "video":{…}, "audio":{"id","mime_type","voice":true}, "document":{"id","mime_type","filename","caption"}, "sticker":{"id","mime_type"},
        "location":{"latitude","longitude","name","address","url"}, "contacts":[{"name":{"formatted_name",…},"phones":[…],"emails":[…],"vcard":"…"}],
        "button":{"text","payload"}, "interactive":{"type":"button_reply|list_reply","button_reply":{"id","title"},"list_reply":{"id","title","description"}},
        "reaction":{"message_id","emoji"}, "system":{"body","type":"user_changed_number","wa_id"}, "edit":{"original_message_id","message":{"type":"text","text":{"body"}}}, "revoke":{"original_message_id"},
        "errors":[{"code","title","message"}], "context":{"from","id"} }],
      "statuses":[{ "id":"wamid.…","status":"accepted|sent|delivered|read|played|failed","timestamp":"…","recipient_id":"966…",
        "conversation":{"id","origin":{"type"}}, "pricing":{"billable","pricing_model","category"}, "errors":[{"code":131047,"title","message","error_data":{"details"}}] }],
      "message_echoes":[{"id","to","timestamp","type",…same as message…}],
      "state_sync":[{"type":"contact","action":"add|remove","contact":{"phone_number","full_name","first_name"}}],
      "message_template_id":"…","event":"APPROVED|REJECTED|…",
      "event":"PARTNER_REMOVED|ACCOUNT_OFFBOARDED","disconnection_info":{"reason","initiated_by"},
      "decision":"APPROVED","requested_verified_name":"…","current_limit":"TIER_1K","max_daily_conversation_per_phone":…,"max_phone_numbers_per_business":… } }] }] }
```
Verification GET: `?hub_mode=subscribe&hub_verify_token=<organization.identifier | Setting whatsapp_callback_token>&hub_challenge=<n>` → body `<n>` (200). POST responses: `{"status":"success"}` 200; `{"error":"Forbidden"}` 403; `{"error":"Method Not Allowed"}` 405.

## 2. Job constructor contracts (queue payloads)
- `ProcessIncomingMessageJob($message: array /* one message object above */, $contactData: ?array /* contacts[0] */, $organizationId: int)` — queue `high`.
- `ProcessMessageStatusJob($statuses: array<status object>, $organizationId)` — queue `messageStatus`; needs only `id` (string), `status` (string), optional `errors[]`.
- `ProcessMediaDownloadJob($chatId, $message /* full message */, $organizationId, $isNewContact=false, $contactUuid=null)` — queue `media`; reads `message['type']`, `message[$type]['id']`, `message['document']['filename']`.
- `ProcessMessageEchoJob($echo, $value /* whole value block */, $organizationId)` — `high`.
- `ProcessContactSyncJob(array $stateSync, $organizationId)` — `low`. `ProcessTemplateStatusJob($templateData /* value */, $organizationId)` — `low`. `ProcessAccountUpdateJob($data /* {field, value} */, $organizationId)` — `low`.
- Downstream: `ProcessTicketAssignmentJob($contactId, $organizationId, $isNewChat)` (dispatchSync, returns `?int assigned_to`), `ProcessAutoReplyJob($chatId, $organizationId, $isNewContact)` (`autoreplies`), `SendTextMessageJob($organizationId, $contactUuid, $body)` (`high`), `RetryMediaWithTranscodeJob($chatId, $organizationId)` (`high`), `SendNewChatPushNotificationJob(int $organizationId, ?int $contactId, array $chatValueSnapshot)` (`high`).

## 3. Rows written
- `contacts` insert (ProcessIncomingMessageJob.php:199-212): `{organization_id, phone:'+966502486051' (E.164 or '+digits'), first_name: profile.name|null, last_name:null, email:null, created_by:0, created_at, updated_at, uuid(auto)}`; later `first_name` fill; `Chat::created` sets `latest_chat_created_at` (all) and `last_inbound_chat_created_at` (inbound). Columns available (create migration + adds): `id, uuid, organization_id, first_name, last_name, phone, formatted_phone, email, avatar, address, contact_group_id, is_favorite, is_blocked, marketing_opted_out_at, latest_chat_created_at, last_inbound_chat_created_at, metadata, created_by, created_at, updated_at, deleted_at, phone_active_key(virtual)`; unique `(organization_id, phone_active_key)`.
- `chats` insert inbound (:261-270): `{organization_id, wam_id:'wamid.…', contact_id, user_id:null, type:'inbound', metadata:'<JSON string>', media_id:null, status:'delivered', is_read:0, created_at: now(), uuid(auto)}`; echo: `type:'outbound', status:'sent', is_read:1`. Columns: `id, uuid, organization_id, wam_id VARCHAR(128), contact_id, user_id, type ENUM('inbound','outbound'), metadata MEDIUMTEXT NOT NULL, media_id, status VARCHAR nullable, is_read, deleted_by, deleted_at, created_at, wam_id_active_key(virtual)`; unique `(wam_id_active_key)` GLOBAL.
- `chats.status` values: `accepted, sent, delivered, read, failed` (`played` mapped to `delivered` before storage).
- `chat_logs` insert: `{contact_id, entity_type:'chat', entity_id: chats.id, created_at}` (columns: `id, contact_id, entity_type, entity_id, deleted_by, deleted_at, created_at, updated_at`).
- `chat_media` insert: `{name: document.filename | 'N/A', path: <absolute URL, TEXT>, type: <mime>, size: <bytes|null>, location: 'local'|'amazon', created_at}`; `chats.media_id` set afterwards.
- `chat_status_logs` insert: `{chat_id, metadata: JsonText::encode(<raw status object with status already normalized>), created_at: <org-tz datetime string>}`.
- `organizations.metadata.whatsapp` keys written by webhooks: `account_review_status, verified_name, messaging_limit_tier, max_daily_conversation_per_phone, max_phone_numbers_per_business`; whole `whatsapp` key removed on offboarding. `templates.status = <event>`.

## 4. `chats.metadata` JSON (ChatMetadataHelper output — the contract new channels must reproduce)
- text: `{"type":"text","text":{"body":"…"}}` (+ optional `"header":…`, `"buttons":[…]`)
- button: `{"type":"button","button":{"text":"…","payload":"…"}}`
- interactive: `{"type":"interactive","interactive":{"type":"button_reply","button_reply":{"id":"…","title":"…"}}}` or `list_reply:{id,title,description}`
- image: `{"type":"image","image":{"caption":"…"|null}}` (+`buttons`); video: same under `video`; audio: `{"type":"audio","audio":{"voice":true|null}}`; document: `{"type":"document","document":{"filename":"…"|null}}`; sticker: `{"type":"sticker","sticker":[]}` (empty allowed-keys list encodes as `[]`).
- location: `{"type":"location","location":{"latitude":21.5,"longitude":39.1,"name"?:"…","address"?:"…","url"?:"…"},"context"?:{"id":"wamid.…"}}`
- contacts: `{"type":"contacts","contacts":[{…contact minus vcard…}]}`; template: `{"type":"template","template":{…}}`; unsupported: `{"type":"unsupported","errors":[…]}`
- system: `{"type":"system","system":{"body":"…","type":"user_changed_number","wa_id":"…"}}`; edit: `{"type":"edit","edit":{"original_message_id":"…","message":{"type":"text","text":{"body":"…"}}}}`; revoke: `{"type":"revoke","revoke":{"original_message_id":"…"}}`
- any other type (reaction, order…): the RAW message object is stored as-is; optional top-level `"transcript"` preserved. Encoded with `JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES` (app/Support/JsonText.php:22).
- Consumers of this shape: Vue ChatBubble, mobile app, `AutoReplyService::extractMessageText` (text.body / button.payload / interactive.*.title), `ChatNotificationContentBuilder` (text.body, button.text|payload, interactive, image/video caption, document filename|caption, audio.voice, system.body, edit.message.text.body), `RetryMediaWithTranscodeJob` (`type==='video'`, `transcode_retry_count`, `transcode_retry_status`).

## 5. NewChatEvent inputs and broadcast payload
- Inbound / media / echo wrapper: `[[ "is_new_contact": bool, "contact_uuid": "<uuid>", "type":"chat", "value": <Chat model with media,user,logs> ]]`; ctor `(chat, organizationId, isNewContact, statusChanged=false, sendToFirestore)` → inbound `(…, $isNew, false, true)`, echo `(…, $isNew, false, false)`.
- Status wrapper: `[[ "type":"chat", "value": <Chat model>, "tempMessageId": "<wam_id>" ]]`, ctor `(…, false, true)`.
- `broadcastWith()`: `{"chat": [[{ "is_new_contact", "contact_uuid", "type":"chat", "value": { "id", "uuid", "contact_uuid", "contact_id", "is_new_contact", "phone", "formatted_phone_number", "organization_id", "latest_chat_created_at", "is_blocked", "is_favorite", "contact_full_name", "unread_messages_count", "created_at", "deleted_at", "metadata": "<JSON string>", "type": "inbound|outbound", "wam_id", "status": "accepted|sent|delivered|read|failed|null", "media": {"type","size","path"(≤200B),"name"(≤80B)}|null, "logs": [{"metadata": "{\"status\",\"errors\",\"id\"}"}] (≤6), "user": {"full_name"}|null }, "tempMessageId"? }]], "statusChanged"?: true}` on presence channels `chats.ch{organizationId}.{userId}`; shrunk stepwise to ≤10240 bytes (contact_full_name → phone → logs(2) → metadata '{}' → metadata null/logs []/media null).
- FCM `additionalData`: `{contactFullName, phone, chatId (= contact id as string), organizationId, createdAt 'Y-m-d H:i:s', userId}`; title = contact full name or phone; body derived from metadata as above.

## 6. Outgoing customer webhook POST body (`{event, data}`)
- `message.received` → `{"event":"message.received","data":{"data": <raw WhatsApp message object>}}` (note the nested `data.data`, ProcessIncomingMessageJob.php:124 / ProcessMediaDownloadJob.php:117,145).
- `message.status.update` → `{"event":"message.status.update","data":{"data": [<raw status objects>]}}`.
- `message.sent` → `{"event":"message.sent","data":{"data": <WhatsappService responseObject>}}` (outbound subsystem).
- The UI "test" payloads (MainController.php:101-229) show `data.value.messages[]` / `data.field` / `data.value.statuses[]` — a different (older) shape than production actually emits; customers may be coded against either.

## 7. Cache keys and queues
- `wam_processed_{wam_id}` (1h, inbound + echo), `working_hours_away_{orgId}_{contactId}` (1h), `message_limit_{orgId}` (60s, ProcessAutoReplyJob), `org_settings_{orgId}` / `org_config_{orgId}` (forgotten by ProcessAccountUpdateJob).
- Queues: `high`, `messageStatus`, `media`, `low`, `autoreplies` (this subsystem); `default`, `campaign-messages`, `campaign-logs` elsewhere. Connection `database`, `retry_after` 660s, `after_commit=false`.
