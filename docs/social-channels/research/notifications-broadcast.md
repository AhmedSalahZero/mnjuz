# Subsystem: notifications-broadcast


## summary

The notifications-broadcast subsystem is the fan-out layer that runs after a chat row is written: a single event `App\Events\NewChatEvent` (queue `high`) is fired from 8 call sites (inbound job, media-download job, status job, echo job, transcode-retry job, and 4 places in `WhatsappService`), and it (a) builds a size-bounded "minimal chat value" via `ChatBroadcastPayloadBuilder`, (b) resolves recipient user ids via `ChatChannelRecipients`, (c) broadcasts on presence channels `chats.ch{org}.{user}` through Pusher/Reverb (`BroadcastProvider` picks the transport from the `settings` table), and (d) when the 5th ctor arg is true, dispatches `SendNewChatPushNotificationJob` which builds title/body via `ChatNotificationContentBuilder` and pushes through `FcmNotification` (FCM HTTP v1). Web clients subscribe via `resources/js/echo.js::getOrJoinChatChannel` (one physical subscription shared by `Layout/App.vue` for sound+badge and `Chat/Index.vue` for list/thread updates); mobile fetches transport config from `/api/v1/broadcast-config` and authenticates channels with Sanctum. The transport layer (BroadcastProvider, channels.php, echo.js, FcmNotification, ChatChannelRecipients, ChatReadService) is already channel-agnostic; the channel-specific assumptions sit in the payload shape (`phone`, `formatted_phone_number`, `wam_id`, WhatsApp `metadata.type` taxonomy, `status` enum), in the push title fallback to phone, and in the web Echo handler that sets `is_messaging_window_open = true` and pushes new rows without a channel marker. `Firestore.php` is dead code and `NotificationService.php` is an unrelated admin-notifications lister.

## current_flow

### A. Inbound message (WhatsApp today)
1. `app/Jobs/ProcessIncomingMessageJob.php:42-138` `handle()` -> `getOrCreateContact()` (L172) -> `createChat()` L250-284: `Chat::create([... 'wam_id' => $this->message['id'], 'type' => 'inbound', 'metadata' => JsonText::encode(ChatMetadataHelper::minimalPayloadForStorage($this->message)), 'status' => 'delivered', 'is_read' => 0])`, dedupe via `Cache::has("wam_processed_{$wamId}")` (L255) and MySQL 1062 on the `wam_id` unique key (L277-282).
2. DB side effect: `app/Models/Chat.php:21-32` `static::created` writes `contacts.latest_chat_created_at` and (inbound only) `contacts.last_inbound_chat_created_at`.
3. `createChatLog()` L301 inserts the `chat_logs` row (entity_type `chat`).
4. `hasMedia()` L286-292 (type in image/video/audio/document/sticker) -> `ProcessMediaDownloadJob::dispatch(...)->onQueue('media')` (L77-84) and **no broadcast yet**; otherwise L110-116: `event(new NewChatEvent($this->formatChatForEvent($chat, $isNewContact, $contact->uuid), $orgId, $isNewContact, false, true))` (5th arg true = send push).
5. `formatChatForEvent()` L333-353 returns `[[ 'is_new_contact'=>bool, 'contact_uuid'=>uuid, 'type'=>'chat', 'value'=> $chatLog->relatedEntities ?? $chat ]]`; `app/Models/ChatLog.php:35-54` `relatedEntities` = `Chat::with('media','user','logs')->find(entity_id)` (Eloquent model, later `toArray()`d).

### B. Media message
`app/Jobs/ProcessMediaDownloadJob.php:49-120` downloads via Graph (`getMedia`, `downloadMedia` L166+ reads `organization.metadata.whatsapp.access_token` L177), creates `ChatMedia` L101-110, `chat->update(['media_id'])` L111, then `broadcastChat()` L156-164 -> `NewChatEvent(..., isNewContact, false, true)` (push on). `failed()` L125-153 also broadcasts (without media) so the message still surfaces.

### C. Status updates
`app/Jobs/ProcessMessageStatusJob.php:62-126`: finds `Chat::where('wam_id', $status['id'])->where('organization_id', ...)` L86-88, `applyStatus()`, inserts `ChatStatusLog` L94-98, then L103-108 `event(new NewChatEvent([[ 'type'=>'chat', 'value'=>$chatLog->relatedEntities, 'tempMessageId'=>$status['id'] ]], $orgId, false, true))` (statusChanged = true, no push).

### D. Outbound sends (WhatsappService)
`app/Services/WhatsappService.php:344-345` (text: `tempMessageId` from client), `:520-526` (template), `:965-975` (media re-send, statusChanged=true), `:1016-1023` (media send with `tempMessageId`). `ProcessMessageEchoJob.php:79-85` (coexistence echo, outbound, no push). `RetryMediaWithTranscodeJob.php:177-198` (statusChanged=true). `WhatsappService.php:49` and `WebhookController.php:40` call `BroadcastProvider::apply()` before broadcasting.

### E. NewChatEvent internals (`app/Events/NewChatEvent.php`)
- L38 `$queue = 'high'`.
- ctor L60-95: L75-76 `$this->chat = payloadBuilder()->buildWrappedChat($chat, $orgId, $isNewContact)` (runs DB queries: contact lookup + unread count, **in the dispatching thread**); L78 `extractContactId()`; L83-90 `hasUsableChat` guard logs and returns (no broadcast, no push); L92-94 `if ($sendToFirestore) dispatchPushNotification()`.
- L186-198 `dispatchPushNotification()` -> `SendNewChatPushNotificationJob::dispatch($orgId, $contactId, $value)` where `$value = $this->chat[0]['value'] ?? $this->chat['value']`.
- L104-129 `broadcastOn()` -> `ChatChannelRecipients::resolveUserIds()` -> `PresenceChannel('chats.ch'.$org.'.'.$userId)` per user (L115-117); any exception -> `[]`.
- L137-148 `broadcastWith()` -> `['chat' => $this->chat] + ['statusChanged' => true]?` -> `fitToPusherLimit()`.
- No `broadcastAs()` so the Pusher event name is the class basename `NewChatEvent` (matched by `echo.js:100`).

### F. Recipient resolution (`app/Services/Chat/ChatChannelRecipients.php:31-72`)
Reads `organizations` (ticketing flags via `Organization::getTicketingActive()` / `getAllowAgentsToViewAllChats()` `app/Models/Organization.php:85-110`), `teams` (user_id, role), and for the restricted case `chat_tickets.is_latest` assignee constrained to the org via `contacts` (L80-99).

### G. Transport (`app/Services/Broadcasting/BroadcastProvider.php`)
`active()` L48-53 reads `settings.broadcast_provider`; `connection()`/`connectionFor()` L63-81; `apply()` L92-105 writes `broadcasting.connections.pusher` and hot-swaps the cached Pusher client (`refreshDriver` L120-138); `clientConfig()` L145-162. `app/Providers/BroadcastConfigServiceProvider.php:30` applies at boot and L36-39 re-applies on every `Queue::looping`. `app/Providers/BroadcastServiceProvider.php:17-25` wraps the driver in `App\Broadcasting\ResilientPusherBroadcaster` (3 retries, then warn-only L32-37) and registers `Broadcast::routes(['web','auth'])` + `routes/channels.php`.

### H. Channel auth (`routes/channels.php:28-60`)
`chats.ch{organizationId}.{userId}`: refuse unless `$user->id === $userId` and a `teams` row exists (org, user, `deleted_at` null); returns `['id' => $user->id]`. Mobile: `routes/api.php:216-218` `POST /broadcasting/auth` under `auth:sanctum` (defined outside the block that closes at L204; I did not verify the enclosing prefix, but `BroadcastConfigController.php:41` advertises `url('/api/v1/broadcasting/auth')`).

### I. Push (`app/Jobs/SendNewChatPushNotificationJob.php`)
L39-45 ctor `(int $organizationId, ?int $contactId, array $chatValueSnapshot)`, queue `high`. L52 same recipients; L57-62 `User::whereIn(id)->where('current_mobile_organization_id', $org)->with('deviceTokens')`; L68-70 `buildTitle`, `buildBody(...,'en')`, `buildBody(...,'ar')`; L72-78 data payload; L82-91 `FcmNotification::isConfigured()`; L93-119 per user/token `fcm->send()`; L134-144 language pick (`users.language` then `getApiLang()`). `app/Services/Firebase/FcmNotification.php:69-152` POSTs to `https://fcm.googleapis.com/v1/projects/{id}/messages:send` with retries (L154-175) and deletes stale `device_tokens` rows on UNREGISTERED (L122-138).

### J. Web consumers
- `resources/js/echo.js:18-62` builds one Echo (`broadcaster:'pusher'`, `authEndpoint:'/broadcasting/auth'`, CSRF header; explicit `wsHost/wsPort/forceTLS/enabledTransports` only when `broadcast.host` is set). L71-118 `getOrJoinChatChannel(org, user, broadcast)` joins `chats.ch{org}.{user}` once and L100 `channel.listen('NewChatEvent', ...)` fans out to handlers.
- `resources/js/Pages/User/Layout/App.vue:148-164` handler: `shouldPlaySound(event.chat)` -> play; `shouldCountAsUnread(event.chat, pathname)` -> `unreadMessages++` (`resources/js/Composables/unreadBadge.js:26-46`).
- `resources/js/Pages/User/Chat/Index.vue:600-619` handler -> `updateSidePanel(event.chat, event.statusChanged)` L486-597 (details in whatsapp_assumptions). Thread merge in `resources/js/Composables/mergeChatIntoThread.js:15-49` keyed on `value.wam_id` / `tempMessageId`.
- `ContactChatDeletedEvent` (`app/Events/ContactChatDeletedEvent.php:29-41`) is fired from `app/Services/ChatService.php:1061` after soft-deleting `chats` and `chat_logs` (L1051-1059) on public channel `contact.chat.deleted.{org}` with `{contact_id}`; **no JS listener exists** (grep of resources/js found none).
- `NewPaymentEvent` (`app/Events/NewPaymentEvent.php:44,65`) -> `payments.ch{org}`; listened in `resources/js/Pages/User/Billing/Index.vue:179-181`. Unrelated to chats.

### K. Mobile REST twin of the broadcast shape
`app/Helpers/Helpers.php:137-155` `minimalChatValue()` -> same builder (used by `app/Http/Controllers/ApiController.php:3041` for the thread endpoint). `ApiController.php:2907-2992` `formatChatValue()` is a hand-copied duplicate of `buildMinimalValue` (identical keys) used for the chat list.

## whatsapp_assumptions

- `app/Services/Chat/ChatBroadcastPayloadBuilder.php:128-129` — payload keys `phone` and `formatted_phone_number` come from the contact; sourced at L343-346 (`Contact` select `phone`) and L367-368 (`formatted_phone_number` accessor). Non-WA contacts have `phone = NULL`, so both keys will be null and there is no other identifier in the payload for web/mobile to display or to show a platform icon.
- `app/Services/Chat/ChatBroadcastPayloadBuilder.php:140` — `'wam_id' => $arr['wam_id']` is the only message identifier used by the clients for dedupe (`mergeChatIntoThread.js:21-29`, `Index.vue:449`). New channels must populate `chats.wam_id` with a platform message id or the thread will duplicate bubbles.
- `app/Services/Chat/ChatBroadcastPayloadBuilder.php:139` — `'type' => $arr['type'] ?? 'outbound'` — direction enum `inbound|outbound` (channel-agnostic, but the default hides a missing value).
- `app/Services/Chat/ChatBroadcastPayloadBuilder.php:142` + `app/Support/ChatStatus.php:15,48-57` — `status` is whitelisted to `accepted|sent|delivered|read|failed` (`played` aliased to `delivered`); any other value is nulled. New channel drivers must map their statuses to this enum (mobile rejects anything else).
- `app/Services/Chat/ChatBroadcastPayloadBuilder.php:34,296-297` — log entries are reduced to `metadata` keys `status|errors|id` (WhatsApp status-webhook shape); `ChatStatus::normalizeLogMetadata` strips unknown statuses.
- `app/Services/Chat/ChatBroadcastPayloadBuilder.php:116-120` — `metadata` is re-emitted as a JSON **string**; clients parse it and switch on `metadata.type` with the WhatsApp taxonomy.
- `app/Services/Chat/ChatBroadcastPayloadBuilder.php:196-199` — shrink step 2 nulls `phone`/`formatted_phone_number` (harmless for new channels, but shows phone is treated as optional decoration).
- `app/Services/Chat/ChatBroadcastPayloadBuilder.php:352-358` — unread count = `chats.type='inbound' AND is_read=0` per contact (channel-agnostic since contact is per-channel).
- `app/Services/Chat/ChatNotificationContentBuilder.php:9-15,35-48` — push **title** = contact name, else `formatted_phone_number`/`phone`, else literal `Unknown`. A Telegram/Messenger contact with no name yields "Unknown" pushes.
- `app/Services/Chat/ChatNotificationContentBuilder.php:59-125` — **body** switches on `metadata.type` in {`text`,`button`,`interactive`,`image`,`video`,`audio`,`document`,`sticker`,`location`,`contacts`,`system`,`edit`,`revoke`} and reads `text.body` (L67), `button.text|payload` (L73-76), `interactive.button_reply.title` / `interactive.list_reply.title|description` (L131-147), `image.caption`/`video.caption`/`document.filename|caption` (L167-173), `audio.voice` (L183), `system.body` (L98, WhatsApp "changed number"), `edit.message.text.body` (L105), `revoke` (L110). Unknown types fall to L113-123 (text.body, else media mime, else "Message").
- `app/Services/Chat/ChatNotificationContentBuilder.php:199-216` — fallback classification by `media.type` MIME prefix (`image/`,`video/`,`audio/`,`application/`).
- `app/Jobs/SendNewChatPushNotificationJob.php:16-17,72-78` — docblock "WhatsApp-style"; FCM `data.phone` is the contact phone (empty string for non-WA); `data.chatId` is actually the **contact id** (string). No channel marker.
- `app/Jobs/SendNewChatPushNotificationJob.php:59-60` — recipients filtered by `users.current_mobile_organization_id` (channel-agnostic).
- `app/Events/NewChatEvent.php:29-30,65,92-94` — param is named `$sendToFirestore` but now means "send FCM push"; positional callers pass it as the 5th argument (`ProcessIncomingMessageJob:115`, `ProcessMediaDownloadJob:162`, `ProcessMessageEchoJob:84`).
- `app/Events/NewChatEvent.php:171,188` — payload wrapper must be `[[type:'chat', value:{...}]]` or `[type:'chat', value:{...}]` with `value.contact_id`; new channel dispatchers must build exactly this wrapper (with `is_new_contact`, `contact_uuid`, optional `tempMessageId`).
- `app/Jobs/ProcessIncomingMessageJob.php:252-256,277-282` — dedupe on `wam_id` via cache key `wam_processed_{wamId}` and the global unique `wam_id_active_key`. Platform ids that are not globally unique (Telegram `message_id` is per-chat integer; Messenger `mid` is global; Instagram `mid`; TikTok message ids) will collide across chats/orgs/platforms and be **silently dropped** as duplicates unless namespaced.
- `app/Jobs/ProcessMessageStatusJob.php:86-88` — status lookup by `wam_id` + org; relies on the same id namespace.
- `app/Jobs/ProcessIncomingMessageJob.php:286-292` and `ProcessMessageEchoJob.php:175-180` — media detection by WhatsApp `message.type` set; `ProcessMediaDownloadJob.php:66-67` reads `message[type]['id']` (Meta media id) and downloads via Graph with `organization.metadata.whatsapp.access_token` (L177).
- `app/Helpers/ChatMetadataHelper.php:16-141` — canonical stored `metadata` shape is the WhatsApp Cloud API message shape (`{type, text:{body}, image:{caption}, audio:{voice}, document:{filename}, location:{latitude,longitude,name,address,url}, contacts:[...], interactive:{...}, button:{text,payload}, template:{...}, system:{body,type,wa_id}, edit:{original_message_id,message}, revoke:{original_message_id}}`). Everything downstream (notification builder, ChatTable, mobile) keys off this.
- `resources/js/Pages/User/Chat/Index.vue:534,543,566` — on any inbound event the handler sets `is_messaging_window_open = true` on the row and the open contact; `app/Helpers/MessagingWindowHelper.php:11,25` hardcodes `WINDOW_HOURS = 24` and L81/L92 error text says "Whatsapp does not allow sending messages 24 hours…". Window length must become per-channel (Telegram: none; Messenger/Instagram: 24h; TikTok: platform-specific).
- `resources/js/Pages/User/Chat/Index.vue:557-573` — new-arrival row pushed to the list contains only `id, uuid, last_inbound_chat, last_chat, unread_messages, last_inbound_chat_created_at(_iso), is_messaging_window_open, latest_chat_created_at, full_name, ticket_status, ticket_assigned_to` — no `phone`, no `avatar`, no channel; the `full_name` comes from `contact_full_name` (null for nameless contacts → ChatTable shows `?`).
- `resources/js/Pages/User/Chat/Index.vue:354-432` — optimistic outbound bubble hardcodes WhatsApp metadata (`{type:'text', text:{body}}`, `image/video/audio/document` with `mime_type`+`caption`), `status:'sent'`, `wam_id = tempMessageId`. Fine as canonical shape, but it presumes every channel accepts the same media categories.
- `resources/js/Pages/User/Chat/Index.vue:15-19` + `resources/js/Composables/filteredChatArrivals.js:22-42` — arrivals are held only when a `search` URL param is active; a future `channel` filter param is not considered, so a Telegram arrival would be inserted into a "WhatsApp only" filtered list.
- `resources/js/Composables/mergeChatIntoThread.js:21-29` — dedupe purely on `value.wam_id` and `tempMessageId`.
- `resources/js/Components/ChatComponents/ChatTable.vue:504-508` — avatar = `item.contact.avatar` else first letter of `full_name` (the block to replace with a platform icon); L545-642 preview switches on `metadata.type` WhatsApp taxonomy and L646-647 unread badge.
- `app/Http/Controllers/ApiController.php:2907-2992` — duplicate of the builder for mobile list: `phone` L2973, `formatted_phone_number` L2974, `wam_id` L2985, `status` L2987; mobile developer relies on exactly these keys.
- `app/Services/Chat/ChatChannelRecipients.php`, `ChatReadService.php`, `routes/channels.php`, `resources/js/echo.js`, `BroadcastProvider.php`, `FcmNotification.php`, `unreadBadge.js` — **no WhatsApp/phone assumptions** (verified by reading; the grep at these files only hit `window.Pusher`/CSRF in echo.js).
- `app/Services/Firebase/Firestore.php:14-15` — hardcoded service-account path and RTDB URL; only referenced from commented code in `app/Models/User.php:151-159`. Dead.
- `app/Services/NotificationService.php:16-21` — admin `notifications` table (title/comment); not part of chat notifications.

## required_changes

### 1. Add a `channel` field to the minimal chat value (single source of truth)
- `app/Services/Chat/ChatBroadcastPayloadBuilder.php:343-346` add `'channel'` to the Contact select (contacts belong to exactly one channel per owner decision) and return it from `loadContactInfo()` (L365-375, plus `'channel' => null` in `$blank` L324-334).
- `buildMinimalValue()` L122-146: add `'channel' => $contactInfo['channel'] ?? ($arr['channel'] ?? 'whatsapp')` so legacy rows (no column yet / NULL) still read `whatsapp`. Keep every existing key, including `phone`/`formatted_phone_number` (null for non-WA) — the Flutter app reads them.
- Optionally also `'contact_handle' => ...` (Telegram username, IG/Messenger name, TikTok display) so titles/rows have something when name and phone are both null.
- `fitToPusherLimit()` L192-211: no change needed (`channel` is a short string; never add it to the shrink ladder).
- `app/Http/Controllers/ApiController.php:2967-2991` `formatChatValue()` must get the identical `'channel'` key (it is a copy of the builder; the `MessageSenderNameTest` pattern at L182-194 can be extended to assert both builders whitelist `channel`). Document for mobile: new key `channel` (string enum `whatsapp|telegram|messenger|instagram|tiktok`) in chat value objects of the list, thread and Pusher payloads.

### 2. Push notification
- `app/Jobs/SendNewChatPushNotificationJob.php:72-78`: add `'channel' => (string) ($this->chatValueSnapshot['channel'] ?? 'whatsapp')` (FCM `data` values must be strings — L73-77 already cast). Document for mobile.
- `app/Services/Chat/ChatNotificationContentBuilder.php:35-48` `buildTitle()`: after phone, fall back to `value['contact_handle']` before `Unknown`. Optionally prefix/suffix a platform label in the title (product decision).
- `buildBody()` L53-125: keep as is **provided** each new channel driver normalises inbound messages into the canonical `metadata` shape from `ChatMetadataHelper::minimalPayloadForStorage` (`{type:'text',text:{body}}`, `{type:'image',image:{caption}}`, `{type:'audio',audio:{voice:bool}}`, `{type:'document',document:{filename}}`, `{type:'video',video:{caption}}`, `{type:'sticker'}`, `{type:'location',location:{latitude,longitude,name,address}}`). Unknown platform types (e.g. Telegram `poll`, IG `story_mention`, TikTok `share`) fall into the default branch safely; add cases only if you want richer text.

### 3. NewChatEvent / dispatch contract
- `app/Events/NewChatEvent.php` needs no structural change; new channel inbound jobs must call `event(new NewChatEvent([[ 'is_new_contact'=>..., 'contact_uuid'=>..., 'type'=>'chat', 'value'=>$chatLog->relatedEntities ?? $chat ]], $orgId, $isNewContact, false, true))` (push on for inbound) and status jobs `event(new NewChatEvent([[ 'type'=>'chat','value'=>..., 'tempMessageId'=>$externalId ]], $orgId, false, true))`. Suggest extracting a tiny `App\Services\Chat\ChatEventDispatcher::broadcastChat(Chat $chat, bool $isNewContact, bool $statusChanged, bool $push)` that builds this wrapper, so the 8 hand-rolled copies (`ProcessIncomingMessageJob:333-353`, `ProcessMediaDownloadJob`, `ProcessMessageEchoJob:192-204`, `ProcessMessageStatusJob:103-108`, `RetryMediaWithTranscodeJob:177-198`, `WhatsappService:344,523-526,970-975,1017-1023`) and the new drivers share one.
- Populate `chats.wam_id` for every channel with a **namespaced, globally unique** external id (varchar 128), e.g. `tg:{chat_id}:{message_id}`, `fb:{mid}`, `ig:{mid}`, `tt:{message_id}`; keep the existing cache key scheme `wam_processed_{wamId}` (`ProcessIncomingMessageJob:255`). This keeps `mergeChatIntoThread.js`, `Index.vue:449 removeOptimisticMessage`, and `ProcessMessageStatusJob:86-88` working unchanged. If you prefer a separate `external_message_id` column, the builder must then emit it as `wam_id` in the payload anyway (clients key on that name).
- Map platform delivery states to `ChatStatus::SUPPORTED` (`accepted|sent|delivered|read|failed`) before `Chat::create`/`applyStatus` (`app/Support/ChatStatus.php:15`); Telegram has no delivery receipts → store `sent`; Messenger/IG have `delivery`/`read` webhooks → `delivered`/`read`.

### 4. Web Echo handler (`resources/js/Pages/User/Chat/Index.vue`)
- L557-573 new-row push: add `channel: currentChat.channel`, and (if available) `phone: currentChat.phone`, `avatar: null` so `ChatTable` can render the platform icon immediately.
- L534/L543/L566 `is_messaging_window_open = true`: correct for every platform (a fresh inbound reopens any window), but the server-side `MessagingWindowHelper::WINDOW_HOURS` (L11) and the error copy (L81, L92) must become per-channel (`ContactResource.php:63-66` and `ChatService` compute it) — flag this to the messaging/contacts subsystem owner.
- `resources/js/Composables/filteredChatArrivals.js:22-42`: extend `shouldHoldArrival(locationSearch, arrivalChannel?)` to also hold arrivals whose `channel` does not match an active `?channel=` filter; keep it a pure function and extend `tests/js/filtered-chat-arrivals.mjs`.
- `resources/js/Components/ChatComponents/ChatTable.vue:504-508`: replace first-letter avatar with a platform icon keyed on `item.contact.channel` (fallback `whatsapp`); rows come from `app/Services/ChatService.php:270-284` select list, which must add `channel` (and `ContactResource` for mobile).
- `resources/js/Composables/unreadBadge.js` and `Layout/App.vue:148-164`: no change (channel-agnostic). If the global badge should be per-channel later, `HandleInertiaRequests.php:107-110` is the count.

### 5. Already channel-agnostic — leave untouched
`ChatChannelRecipients.php`, `ChatReadService.php`, `routes/channels.php`, `resources/js/echo.js`, `resources/js/bootstrap.js`, `BroadcastProvider.php`, `BroadcastConfigServiceProvider.php`, `ResilientPusherBroadcaster.php`, `FcmNotification.php`, `BroadcastConfigController.php`, `ContactChatDeletedEvent.php`, `NewPaymentEvent.php`, `mergeChatIntoThread.js` (given wam_id is populated), `unreadBadge.js`.

### 6. Cleanups worth doing while here (optional, additive)
- Rename the 5th ctor param `$sendToFirestore` -> `$sendPush` in `NewChatEvent.php:65` (positional, no caller change) and delete `app/Services/Firebase/Firestore.php` + the commented block in `User.php:151-159`.
- New channel services that broadcast from a web request should call `BroadcastProvider::apply()` like `WhatsappService.php:49` (boot already applies it; the per-service call is belt-and-braces and is what `BroadcastProviderTest::test_each_former_offender_now_uses_the_shared_source` L266-282 guards for the listed files).

## risks

- **Silent message loss via id collision**: `ProcessIncomingMessageJob.php:255,277-282` treats any `wam_id` hit in cache or the global `wam_id_active_key` unique index as a duplicate and returns `null` (no chat, no broadcast, no push). Telegram `message_id`s are small per-chat integers; reusing raw platform ids in `chats.wam_id` will drop real messages across orgs/chats/platforms. Namespace ids per platform before insert.
- **Breaking the mobile app by changing keys**: `ChatBroadcastPayloadBuilder.php:122-146` and `ApiController.php:2967-2991` are consumed by Flutter which crashes on missing `created_at`/`id` (see guard comments L77-88 and `ChatBroadcastPayloadGuardTest`). Only add keys; never remove/rename `phone`, `formatted_phone_number`, `wam_id`, `status`, `metadata` (must stay a JSON string, L119).
- **Status enum leakage**: any status outside `ChatStatus::SUPPORTED` is nulled for API (`ChatStatus.php:48-57`) but stored as-is (`forStorage` L34-41). New channel status words must be mapped before storage or the web thread shows no tick state and logs get stripped (L65-82).
- **Push titles degrade**: with `phone = NULL`, `buildTitle()` (`ChatNotificationContentBuilder.php:42-47`) returns `Unknown` for nameless non-WA contacts. Provide a handle in the snapshot before enabling push for new channels.
- **Constructor does DB work in the caller's thread**: `NewChatEvent::__construct` L75-76 runs the Contact lookup and an unread `COUNT(*)` (`ChatBroadcastPayloadBuilder.php:340-358`) synchronously. Fire it from queued jobs (as WhatsApp does), never from a webhook controller, or the new platforms' webhook latency will suffer and Meta/Telegram may retry.
- **Order/duplication on the client**: a message reaches the browser several times (send broadcast, then one per status change). `mergeChatIntoThread.js` handles any order only when `wam_id` is stable and `tempMessageId` is echoed back on the first outbound broadcast (`WhatsappService.php:344,1021`). New senders must pass `tempMessageId` through or optimistic bubbles will duplicate.
- **Hold-on-search logic**: `Index.vue:551-555` holds arrivals only under `?search=`; adding a channel filter without touching `filteredChatArrivals.js` will inject foreign-channel rows into a filtered list.
- **Queues**: `NewChatEvent` and `SendNewChatPushNotificationJob` run on `high`; media on `media`; auto-replies on `autoreplies`. Reuse the same queue names so workers already provisioned in production pick them up; a new queue name with no worker = silent no-delivery.
- **Broadcast config caching in workers**: `BroadcastConfigServiceProvider.php:36-39` refreshes on `Queue::looping`; a new long-running process (e.g. a Telegram long-polling command) would need the same `BroadcastProvider::forget(); apply();` loop or it will keep the boot-time destination.
- **Pusher 10 KB cap**: the shrink ladder (`fitToPusherLimit` L192-211) already nulls phone/name/logs/metadata; platform-specific large metadata (IG story attachments, Telegram forwarded payloads) must be trimmed at storage time (`ChatMetadataHelper`) or the metadata will be dropped from the broadcast.
- **`ContactChatDeletedEvent` is a public channel** (`ContactChatDeletedEvent.php:31`, no auth) leaking contact ids — pre-existing; do not copy this pattern for new events.
- **Tests that scan source**: `BroadcastClientConfigTest::test_no_screen_builds_its_own_connection` (L140-164) fails if any new Vue/JS file calls `new Echo(`; `BroadcastProviderTest::test_no_file_writes_the_broadcast_config_by_itself` (L240-264) fails on `Config::set('broadcasting.connections` anywhere in `app/` or `modules/`; `MessageSenderNameTest` L182-194 counts a literal `'full_name' => trim((string) ($arr['user']['full_name']` in the builder and ApiController — keep that literal when editing those functions.
- **Chat model hook**: `Chat.php:21-32` writes `contacts.latest_chat_created_at`/`last_inbound_chat_created_at` on every insert via `$contact->save()` (full model save, fires `ContactObserver::saving` which normalises `phone` if dirty — not dirty here, so safe for NULL-phone contacts; verified `ContactObserver.php:129-138` returns early when phone is unchanged or null).

## tests

### Existing
- `tests/Unit/ChatBroadcastPayloadGuardTest.php` (pure PHPUnit, no DB): `buildMinimalValue` returns `[]` for null/empty/id-less input (L49-63); `buildWrappedChat` + `hasUsableChat` reject unresolved entities and malformed wrappers (L65-83); a real row keeps `id`, `created_at`, `type`, `status` (L87-96); both wrapper shapes work (L98-121); sibling keys like `tempMessageId` are preserved (L123-134); a normal message fits Pusher without shrinking (L137-153). Uses a WhatsApp fixture (`wam_id 'wamid.TEST'`, `{"type":"text","text":{"body":"مرحبا"}}` L33-45).
- `tests/Unit/ChatNotificationForSilentTypesTest.php` (Laravel TestCase for `Lang`): `buildBody` for `system` (L35-52), `edit` (L57-70), `revoke` (L74-77), `reaction` default (L87-93), legacy raw-webhook rows produce the same text as minimised rows (L95-112), untouched types text/image/video/sticker/location/contacts/document (L121-137), unknown type `order` still yields text (L140-143), Arabic keys exist in `lang/ar.json` and resolve (L147-168).
- `tests/Feature/BroadcastAuthEndpointTest.php`: `/broadcasting/auth` signs `presence-chats.ch{org}.{user}` with the active provider's key/secret (L131-171), `channel_data.user_id` (L176-183), 403 for another user's channel (L186-197) and for a non-member (L199-211).
- `tests/Feature/BroadcastClientConfigTest.php`: `clientConfig()` key order `provider,key,cluster,host,port,scheme,force_tls` (L46-56), secret never leaks (L59-67), cloud sends `host=null` (L72-81), Reverb sends host/port/tls (L84-94), switching (L97-108), source guards: `ChatService.php`, `BillingController.php`, `HandleInertiaRequests.php` must use `BroadcastProvider::clientConfig()` (L115-134), no `new Echo(` outside `echo.js` (L140-164), runs `tests/js/echo-broadcast-options.mjs` via node (L167-180).
- `tests/Feature/BroadcastConfigEndpointTest.php`: `/api/v1/broadcast-config` 401 unauthenticated (L91-94), no secret (L99-108), structure `provider,key,host,port,scheme,force_tls,cluster,auth_endpoint` (L112-128), `auth_endpoint = url('/api/v1/broadcasting/auth')` (L131-138), follows provider switches (L161-192), route registered (L197-203).
- `tests/Feature/BroadcastProviderCommandTest.php`: artisan `broadcast:provider` display/switch/refusal/probe behaviour (L61-256).
- `tests/Feature/BroadcastProviderTest.php`: default stays pusher (L53-56), unknown values fall back (L59-74), connection carries host/port/scheme (L91-112), `apply()` stable and non-stripping (L115-137), switching (L142-173), client config (L177-213), settings precedence (L219-232), source guards for `Config::set('broadcasting.connections` (L240-264) and `BroadcastProvider::apply()` presence in `WhatsappService.php`, `WebhookController.php`, `PayPalService.php`, `BroadcastConfigServiceProvider.php` (L266-282).
- `tests/Feature/BroadcastRuntimeConfigTest.php`: boot applies config with `ENABLE_DATABASE_CONFIG` off (L86-96), pusher env stays pusher (L99-108), cached broadcaster follows switch and keeps channel registrations/instance (L141-194), no client rebuild when unchanged (L200-211), `Queue::looping` re-reads settings (L218-248), survives a broken manager (L256-267).
- `tests/Feature/Cycle/InboundSideEffectsTest.php:115-136`: `NewChatEvent` dispatched for an inbound text; for media, `ProcessMediaDownloadJob` dispatched and `NewChatEvent` **not** dispatched before download.
- `tests/Feature/MessageSenderNameTest.php:92-93,103-141,182-194`: `buildMinimalValue()['user']` is exactly `['full_name' => ...]` on every path (web thread, mobile, broadcast) and a source-scan that the builder and `ApiController` whitelist `full_name`.
- `tests/Feature/ChatThreadMergeTest.php:41` runs `tests/js/merge-chat-into-thread.mjs` (all arrival permutations of send/status broadcasts with `tempMessageId`/`wam_id` dedupe).
- Present in `tests/js/` but **no PHP wrapper or `package.json` test script found** for: `unread-badge.mjs`, `filtered-chat-arrivals.mjs`, `echo-call-sites.mjs`, `sender-name.mjs` (only `merge-chat-into-thread.mjs` and `echo-broadcast-options.mjs` are wired from PHP).

### Gaps (nothing covers these today)
- `NewChatEvent::broadcastOn()`/`broadcastWith()` end-to-end (channel names per recipient, `statusChanged` flag, push dispatched only when 5th arg true, `hasUsableChat` short-circuit).
- `ChatChannelRecipients::resolveUserIds()` visibility matrix (ticketing on/off, agents-see-all, assigned agent).
- `SendNewChatPushNotificationJob` (recipient filter by `current_mobile_organization_id`, FCM `data` keys and string casting, language selection) and `FcmNotification` (stale-token deletion, retries).
- `ChatNotificationContentBuilder::buildTitle()` fallbacks (name → phone → Unknown).
- `fitToPusherLimit()` shrink ladder order.
- `ChatBroadcastPayloadBuilder::loadContactInfo()` (unread count, cross-org guard).
- `ContactChatDeletedEvent` (channel/payload) and the fact no client listens.
- `Index.vue::updateSidePanel` is not a pure function; only the extracted helpers (`holdArrival`, `shouldHoldArrival`, `mergeChatIntoThread`, `unreadBadge`) are testable.

### Tests to add for multi-channel
- Builder: emits `channel` from the contact, defaults to `whatsapp` when NULL/absent, keeps `phone` key present-but-null for non-WA rows; `ApiController::formatChatValue` emits the same key (extend the `MessageSenderNameTest` source-scan pattern).
- Push job: `data.channel` present and a string; `buildTitle` uses `contact_handle` when name and phone are null.
- `ChatStatus`-mapping of each platform's status words.
- Namespaced `wam_id` uniqueness: two platforms/chats with the same raw id both persist (guards the silent-drop risk).
- JS: `filteredChatArrivals` with a `channel` filter; a pure `platformIcon(channel)` helper for `ChatTable`; wire the orphan `tests/js/*.mjs` into PHP wrappers like `ChatThreadMergeTest`.

## data_shapes

### 1. Pusher/Reverb event (event name `NewChatEvent`, channel `presence-chats.ch{organizationId}.{userId}`) — `NewChatEvent::broadcastWith()` L137-148
```json
{
  "chat": [
    {
      "is_new_contact": false,          // present on inbound/media dispatches
      "contact_uuid": "uuid",           // present on inbound/media dispatches
      "type": "chat",
      "tempMessageId": "client-temp-id | wamid",   // outbound sends and status updates only
      "value": { ...minimal chat value (see 2) }
    }
  ],
  "statusChanged": true                 // only when the 4th ctor arg is true
}
```
Older callers may pass the flat wrapper `{"type":"chat","value":{...}}` (`buildWrappedChat` L58-61); clients read `event.chat[0].value`.

### 2. Minimal chat value — `ChatBroadcastPayloadBuilder::buildMinimalValue()` L122-146 (identical keys in `ApiController::formatChatValue()` L2967-2991; also the mobile thread via `minimalChatValue()`)
```json
{
  "id": 991, "uuid": "chat-uuid",
  "contact_uuid": "uuid|null", "contact_id": 123, "is_new_contact": false,
  "phone": "+9665…|null", "formatted_phone_number": "+966 5… |null",
  "organization_id": 211, "latest_chat_created_at": "…|null",
  "is_blocked": false, "is_favorite": false,
  "contact_full_name": "الاسم|null", "unread_messages_count": 3,
  "created_at": "2026-08-15 10:00:00", "deleted_at": null,
  "metadata": "{\"type\":\"text\",\"text\":{\"body\":\"…\"}}",   // JSON STRING (UTF-8 raw)
  "type": "inbound|outbound", "wam_id": "wamid.…|null",
  "status": "accepted|sent|delivered|read|failed|null",
  "media": { "type": "image/jpeg", "size": 1234, "path": "https://… (≤200B)", "name": "file.jpg (≤80B)" } | null,
  "logs": [ { "metadata": "{\"status\":\"delivered\",\"id\":\"wamid.…\"}" } ],   // ≤6 (≤2 under pressure), keys status|errors|id
  "user": { "full_name": "Agent Name" } | null
}
```
**Add here:** `"channel": "whatsapp|telegram|messenger|instagram|tiktok"` (and optionally `"contact_handle"`). Shrink ladder (L192-211) may null `contact_full_name`, `phone`, `formatted_phone_number`, shrink `logs`, set `metadata` to `"{}"` then `null`, `media` null.

### 3. Canonical stored `chats.metadata` (what clients switch on) — `ChatMetadataHelper::minimalPayloadForStorage()` L16-141 and `Index.vue:361-396`
- `{"type":"text","text":{"body":"…"},"header"?:…, "buttons"?:[…]}`
- `{"type":"button","button":{"text":"…","payload":"…"}}`
- `{"type":"interactive","interactive":{"type":"button_reply","button_reply":{"id","title"}} | {"type":"list_reply","list_reply":{"id","title","description"}}}`
- `{"type":"image","image":{"caption"?:"…","mime_type"?:"…"},"buttons"?:[…]}`, `{"type":"video","video":{"caption"?}}`, `{"type":"audio","audio":{"voice"?:true,"mime_type"?}}`, `{"type":"document","document":{"filename"?,"caption"?,"mime_type"?}}`, `{"type":"sticker","sticker":{}}`
- `{"type":"location","location":{"latitude","longitude","name"?,"address"?,"url"?},"context"?:{"id"}}`
- `{"type":"contacts","contacts":[{name/phones/emails, no vcard}]}`
- `{"type":"template","template":{…}}`, `{"type":"system","system":{"body","type","wa_id"}}`, `{"type":"edit","edit":{"original_message_id","message":{…}}}`, `{"type":"revoke","revoke":{"original_message_id"}}`, `{"type":"unsupported","errors":[…]}`; optional `"transcript"`.
New channels must emit these shapes (at least `text`, `image`, `video`, `audio`, `document`, `sticker`, `location`) for the push body, `ChatTable` preview and mobile to render without new branches.

### 4. FCM message — `FcmNotification::send()` L90-112 with data from `SendNewChatPushNotificationJob` L72-78,99-101
```json
{ "message": {
  "token": "<device_token>",
  "notification": { "title": "<contact name | formatted phone | phone | 'Unknown'>", "body": "<localised body>" },
  "android": { "notification": { "sound": "on_notification.wav", "color": "#0A0A0A" } },
  "apns": { "payload": { "aps": { "sound": "on_notification.wav" } } },
  "data": {
    "contactFullName": "…", "phone": "…|''",
    "chatId": "<CONTACT id as string>", "organizationId": "211",
    "createdAt": "Y-m-d H:i:s (server now)", "userId": "<recipient user id>"
  }
}}
```
**Add:** `"channel": "…"` in `data` (string). Body examples: text → body; media → `📷 Photo: caption`, `🎥 Video`, `🎤 Voice message`/`🎵 Audio`, `📄 File: filename`, `🎨 Sticker`, `📍 Location`, `👤 Contact`; `ℹ️ …` system, `✏️ …` edit, `🚫 Deleted a message`, default `💬 Message`.

### 5. Web/mobile transport config — `BroadcastProvider::clientConfig()` L145-162 (Inertia shared prop `broadcast`, `HandleInertiaRequests.php:177`; `ChatService.php:242` `pusherSettings`)
`{"provider":"pusher|reverb","key":"…","cluster":"mt1|us2|null","host":"reverb.host|null","port":443,"scheme":"https","force_tls":true}`
Mobile `GET /api/v1/broadcast-config` (`BroadcastConfigController.php:28-43`): `{"statusCode":200,"success":true,"data":{provider,key,host,port,scheme,force_tls,cluster,"auth_endpoint":"https://…/api/v1/broadcasting/auth"}}`.
Channel auth response (`/broadcasting/auth`): Pusher presence `{"auth":"key:hmac","channel_data":"{\"user_id\":…,\"user_info\":{\"id\":…}}"}`.

### 6. Other events
- `ContactChatDeletedEvent` on public `contact.chat.deleted.{organizationId}`: `{"contact_id": 123}` (no listener).
- `NewPaymentEvent` on public `payments.ch{organizationId}`: `{"payment": <mixed>}`.

### 7. Web chat-list row that the Echo handler mutates/creates (`Index.vue:515-573`; server rows from `ChatService.php:270-284` / mobile `ContactResource.php:40-70`)
`{ id, uuid, full_name, avatar?, phone?, last_chat: <minimal value>, unread_messages: n, last_inbound_chat: {created_at: iso, created_at_iso: iso}, last_inbound_chat_created_at, last_inbound_chat_created_at_iso, is_messaging_window_open: bool, latest_chat_created_at, ticket_status, ticket_assigned_to, contact_categories?, is_blocked? }` — the platform icon needs a `channel` key on these rows (server select + the client-side push at L557-573).

### 8. Dispatch wrapper new channel jobs must produce (from `ProcessIncomingMessageJob::formatChatForEvent` L333-353)
`[[ 'is_new_contact' => bool, 'contact_uuid' => string, 'type' => 'chat', 'value' => Chat::with('media','user','logs') model|array ]]`, then `new NewChatEvent($wrapper, $organizationId, $isNewContact, $statusChanged=false, $sendPush=true)`.
