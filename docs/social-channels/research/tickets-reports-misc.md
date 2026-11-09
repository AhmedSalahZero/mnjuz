# Subsystem: tickets-reports-misc


## summary

Root `R` = /media/salah/Software/projects/mnjuz (all paths below are `R/...`).

This subsystem is the "around the conversation" layer: chat tickets (one `chat_tickets` row per contact carrying open/closed status + assignee, auto-assignment on inbound, manual assign/close from web + mobile), the ticket/notes event feed that is interleaved into the chat thread via `chat_logs` (entity_type = chat | ticket | notes), the post-close customer rating survey (`conversation_ratings`, sent as a WhatsApp CTA-URL button), agent performance metrics (chats + chat_tickets + agent_activity heartbeats), the activity log (`activity_logs`, written through `ActivityLogger`), reports for mobile (`MobileReportController`), the owner dashboard, developer API keys, user OTP verification over WhatsApp, the Waz Business billing integration (unrelated to chat contacts), and console commands/scheduler.

Wiring: `ProcessIncomingMessageJob` (R/app/Jobs/ProcessIncomingMessageJob.php:69) calls `ProcessTicketAssignmentJob::dispatchSync($contact->id, $orgId, true)` inline after saving the chat; web close/assign goes `ChatTicketController` -> `Contact::toggleTicketStatus()/assignToUserThroughTicket()` (R/app/Models/Contact.php:475-540); mobile goes `ApiController::toggleTicketStatus/assignContactToUserThroughTicket` -> same Contact methods. `toggleTicketStatus('closed')` is the single trigger of `ConversationRatingService::onConversationClosed()`, which is the ONLY outbound-sending code in this subsystem and is hard-wired to `WhatsappService` + `organizations.metadata.whatsapp`.

Verdict: ~85% of this subsystem is already channel-agnostic (everything keyed by contact_id / chat_id / user_id). The WhatsApp-bound parts are: ConversationRatingService (send path, 24h window, Meta limits, phone snapshot), DashboardController (WhatsApp setup banner/embedded signup props), UserVerificationService+SendUserVerificationWhatsappJob (user OTP, intentionally WA-only), phone-based contact lookups in 3 console commands, `$contact->phone` used as ActivityLogger fallback label, and `BackfillCampaignWebhookFailures` which parses Meta status-webhook error shapes. No table in this subsystem needs a channel column; channel derives from `contacts` via contact_id.

## current_flow

`R` = /media/salah/Software/projects/mnjuz

### A. Inbound message -> ticket (auto open / reopen / assign)
1. R/app/Jobs/ProcessIncomingMessageJob.php:66-73 — after `createChat()` + `createChatLog()` it calls `ProcessTicketAssignmentJob::dispatchSync($contact->id, $this->organizationId, true)` (SYNC, not queued; the comment at R/app/Models/ChatTicket.php:127 saying "queue tickets" is stale). Return value `$assignedTo` (?int) is captured by the caller.
2. R/app/Jobs/ProcessTicketAssignmentJob.php:46-85 `handle()`: reads org settings from Cache key `org_settings_{orgId}` (line 92-99, 30 min, value = `json_decode($org->metadata)` object). If `!settings->tickets->active` -> return null (line 51). Else `DB::transaction(…, 3)` (line 55-74): `ChatTicket::where('contact_id')->orderByDesc('id')->lockForUpdate()->first()`; no ticket && isNewChat -> `createTicket()`; ticket closed -> `reopenTicket()`; else null.
3. `createTicket()` line 105-143: `resolveAssignedAgent()` (line 197-207: `assignment_mode` auto|round_robin|manual, fallback to legacy `auto_assignment` bool); `ChatTicket::withoutEvents(create([...status open, is_latest true, assigned_seen = assignedTo===null]))` line 113-123; `ChatTicket::syncLatestFlag()` line 125; `ChatTicketLog::insertGetId(['contact_id','description'=>'Conversation was opened','created_at'])` line 128-132; `ChatLog::insert(['contact_id','entity_type'=>'ticket','entity_id'=>$ticketLogId,'created_at'])` line 135-140.
4. `reopenTicket()` line 148-192: optional reassign per `reassign_reopened_chats`; sets status open; log description `'Conversation was moved from closed to open'` line 171-175; ChatLog insert line 178-183.
5. `getLeastBusyAgent()` line 209-223: `Team::where(org)->whereNull(deleted_at)->where(status active)->withCount(tickets where status open & is_latest)->orderBy(tickets_count)`; `Team::tickets()` = hasMany(ChatTicket,'assigned_to','user_id') (R/app/Models/Team.php:29-32).
6. `getNextRoundRobinAgent()` line 229-276: `Organization::lockForUpdate()`, reads/writes `metadata.tickets.round_robin_last_user_id`, rewrites the WHOLE organizations.metadata JSON (line 268-271) then `Cache::forget("org_settings_{id}")` line 273.
7. R/app/Models/ChatTicket.php:147-170 booted(): `created` -> syncLatestFlag; `deleting` -> promote previous ticket. `syncLatestFlag()` line 176-185 = single UPDATE with CASE, 3 deadlock retries. Accessors convert created_at/updated_at to org timezone (line 187-195).
8. Legacy duplicate path: R/app/Services/ChatService.php:450-541 `handleTicketAssignment()` (same logic, older; not called by webhook) and `ensureChatTicketsExist()` line 1186-1215 (called from chat list at line 204 when tickets active: bulk-inserts `open/unassigned/is_latest=1` tickets for up to 500 contacts with `latest_chat_created_at` and no ticket).

### B. Manual close / reopen / assign
- Web: R/routes/web.php:202-204 -> R/app/Http/Controllers/User/ChatTicketController.php `update()` line 68-89 (`Contact::where(uuid)`, `authorizeConversation()` line 23-61 = agent may act only on latest ticket assigned to him unless allow_agents_to_view_all_chats / shared mode; then `$contact->toggleTicketStatus($request->status)`; `ActivityLogger::log(TICKET_CLOSED|TICKET_REOPENED, name ?: $contact->phone, 'contact', id, ['status'])` line 75-81). `assign()` line 91-119: validates Team membership, `$contact->assignToUserThroughTicket($user)`, ActivityLogger TICKET_ASSIGNED line 104-110.
- Mobile: R/routes/api.php:134-135 -> R/app/Http/Controllers/ApiController.php:3199-3222 `toggleTicketStatus($id)` (Contact::find by numeric id, no org/role authorization!) and 3343-3382 `assignContactToUserThroughTicket` (contact_id + user_id; no Team check). Both call `logMobileActivity()` line 2455-2480 (label = name ?: `$contact->phone`).
- R/app/Models/Contact.php:475-512 `toggleTicketStatus()`: UPDATE latest ticket `{status, assigned_to = auth id, assigned_seen true}`; on closed `ChatReadService::markInboundAsRead(contactId, orgId)` (R/app/Services/Chat/ChatReadService.php:24-37); ChatTicketLog description `'Conversation was moved from opened to closed'` | `'... from closed to open'` (line 491-497); ChatLog insert (499-504); on closed `ConversationRatingService::onConversationClosed($this, auth()->id())` line 509.
- R/app/Models/Contact.php:518-540 `assignToUserThroughTicket()`: UPDATE `{assigned_to, assigned_seen false}`; log `'Conversation was assigned to {first} {last}'`; ChatLog insert.

### C. Rating survey (post-close)
1. R/app/Services/ConversationRatingService.php:53-104 `onConversationClosed(Contact, ?agentId)`: `settings(orgId)` (line 107-122 from `organizations.metadata.ratings`), `shouldAskAgain()` (line 156-175: last `conversation_ratings.created_at` withTrashed vs. latest `chat_ticket_logs.description LIKE '%closed to open%'` after it; decision in `decideAsk()` 183-194), `MessagingWindowHelper::isMessagingWindowOpen($contact)` (line 68; R/app/Helpers/MessagingWindowHelper.php:45-48 -> 24h from `contacts.last_inbound_chat_created_at`), `createPending()` (line 196-215: row with snapshot `contact_name`, `contact_phone = $contact->phone`, `agent_name`, 48-char token, expires +7d), `send()` (line 273-317: builds `new WhatsappService(access_token, graph.api_version, app_id, phone_number_id, waba_id, orgId)` and calls `sendMessage($contact->uuid, $body, null, 'interactive call to action url', ['display_text','url'])`, falls back to `'text'` with link appended); on failure `forceDelete()` the row (line 88); on success `sent_at = now()`.
2. Public rating page: R/routes/web.php:84-85 -> R/app/Http/Controllers/RatingController.php `show()` line 20-36 / `store()` line 38-65 -> `ConversationRatingService::submit()` line 335-361 (sets rating/comment/status submitted/submitted_at/ip; `ActivityLogger::log(RATING_RECEIVED, contact_name ?: contact_phone, 'contact', contact_id, ['rating'], orgId, null, 'Customer')`).
3. Listing/deleting: web R/app/Http/Controllers/User/RatingController.php:39-133; mobile R/app/Http/Controllers/Api/MobileReportController.php:63-164. Settings write: R/app/Http/Controllers/User/SettingController.php:249-306 (`tickets()` GET/POST writes `metadata.tickets.*` and `metadata.ratings.*`, then `Cache::forget("org_settings_{id}")`).
4. Console reset: R/app/Console/Commands/ResetConversationRating.php (`ratings:reset {target} --org --send`).

### D. Notes
R/routes/web.php:308 resource `notes` -> R/app/Http/Controllers/User/ChatNoteController.php:26-38 -> R/app/Services/ChatNoteService.php `store()` line 68-87 (Contact by uuid, same authorizeConversation rule as tickets, saves `chat_notes`, ChatLog insert entity_type `'notes'`), `delete()` line 89-97 (soft delete).

### E. Thread rendering of ticket/notes events
R/app/Services/ChatService.php:1132-1182 `getChatMessages()` reads `chat_logs` for contact, maps entity_type -> `Chat` | `ChatTicketLog` | `ChatNote`, returns `[['type'=>entity_type,'value'=>model]]`. Mobile sync does the same at R/app/Http/Controllers/ApiController.php:2160-2175 and 3014-3045 (+ `ticket_status`/`ticket_assigned_to` per contact at 2184-2185 via `Contact::ticket()` hasOne, R/app/Models/Contact.php:513-516). Vue renders ticket rows as centered dashed text at R/resources/js/Components/ChatComponents/ChatThread.vue:132-137 using `value.description` + `value.created_at`; header buttons at R/resources/js/Components/ChatComponents/ChatHeader.vue:107-123 PUT `/tickets/{uuid}/update` {status} and `/tickets/{uuid}/assign` {id}.

### F. Agent performance
- R/app/Services/AgentPerformanceService.php `metrics(from,to)` line 30-158: team members; `chats` outbound with user_id grouped (line 50-62); `chat_tickets` JOIN `contacts` for org scoping (assigned 66-73, closed 75-83, resolution AVG(updated_at-created_at) 85-96); `agent_activity` sums (98-112); `firstResponseAverages()` line 166-210 cursors org chats in range ordered by contact_id, created_at and pairs inbound -> first outbound with user_id; online = last ping OR last outbound within 5 min.
- Heartbeats: web R/app/Http/Controllers/User/AgentPerformanceController.php:63-74 (POST /performance/heartbeat), mobile R/app/Http/Controllers/ApiController.php:2614-2627 (POST /api/v1/performance/heartbeat) -> `recordHeartbeat()` line 222-277 (insertOrIgnore on unique (org,user,date), +min(elapsed,120)s when visible).
- Web page R/app/Http/Controllers/User/AgentPerformanceController.php:35-60 (feature `agent_performance` + privileged role). Mobile R/app/Http/Controllers/Api/MobileReportController.php:34-55.

### G. Activity log
R/app/Services/ActivityLogger.php: constants line 21-67; `featureEnabled()` line 84-92 (plan feature `activity_log`, per-request static cache); `log()` line 180-221 (org from session or `users.current_mobile_organization_id` line 226-242; inserts `activity_logs` row; never throws). Read: mobile R/app/Http/Controllers/Api/MobileReportController.php:172-242; web R/app/Http/Controllers/User/ActivityLogController.php (not fully read; export at line 122). Prune: R/app/Console/Commands/PruneActivityLogs.php scheduled `activity:prune` daily 03:30 (R/app/Console/Kernel.php:74-76), 7-day retention.

### H. Dashboard
R/routes/web.php:160 -> R/app/Http/Controllers/User/DashboardController.php:28-70: counts (chats with non-deleted contact, campaigns, contacts, templates), `graphAPIVersion`, Settings `is_embedded_signup_active|whatsapp_client_id|whatsapp_config_id`, pending/scheduled campaigns (5), `setupWhatsapp = !isset(metadata.whatsapp)`, 7-day inbound/outbound daily counts (`getChatCounts()` line 110-125, 14 COUNT queries). `dismissNotification()` line 72-93 rewrites whole metadata JSON.

### I. Developer API keys
R/app/Http/Controllers/User/DeveloperController.php:26-55 + R/app/Services/OrganizationApiService.php:9-22 (`organization_api_keys` create/soft-delete; `config('apiguide')` passed to page — not opened).

### J. User OTP over WhatsApp
R/app/Services/UserVerificationService.php `createAndSend()` line 32-57 -> `SendUserVerificationWhatsappJob` (R/app/Jobs/SendUserVerificationWhatsappJob.php:22-46, email fallback on failure) -> `sendWhatsappCode()` line 99-144: `Template::where(org)->where('id', 4)`, normalizes `users.phone` to E.164 (line 221-252), builds Meta template components (line 254-296), internally invokes `ApiController::sendTemplateMessage` via `Request::create('/api/send/template')` (line 122-131).

### K. Waz Business (billing) — reads only
R/app/Services/WazSyncService.php: `contactId(userId)` line 48-53 = `users.waz_contact_id`; `primaryContactId(companyId)` 63-88; `syncTicket(Ticket)` 378-424 (support `tickets` table, not chat tickets); `removeContact(User)` 595-607. R/app/Services/WazBusinessService.php `createContact()` 212-278 posts `phonenumber = $data['phone']` (the registering USER's phone). Zero references to `contacts`/`chats` tables.

### L. Console commands & scheduler
R/app/Console/Kernel.php:16-92: chunked-upload prune 03:20; `CreateCampaignLogsJob` every minute on queue `campaign-logs`; `subscriptions:renew-from-credits` hourly; `ProcessCampaignMessagesJob` every `campaigns.send_interval_seconds` (5s default) on queue `campaign-messages`; `queue:prune-failed`, `queue:prune-batches`, `activity:prune`, `sanctum:prune-expired`. Commands touching chats/contacts: DiagnoseUnreadChats (`chat:diagnose-unread`), UnescapeChatMetadata (`chat:unescape-metadata`), BackfillContactFormattedPhone, UpdateLastInboundChatCreatedAt, UpdateLatestChatCreatedAt, ResetConversationRating, BackfillCampaignWebhookFailures, TestCommand (`run:test`, dev scratch with hardcoded contact 159377). Others (BackfillWazInvoices, BroadcastProviderCommand, CheckModuleUpdates, EnableActivityLogOnPlans, PruneActivityLogs, ReindexAllTables, RenewSubscriptionsFromCredits, RepairOrphanPayment, RunModuleMigrations/Seeders, SendErrorReportCommand, SyncTemplateData (empty stub), SyncTranslations, WazReconcileInvoices) do not touch chats/contacts.

## whatsapp_assumptions

`R` = /media/salah/Software/projects/mnjuz

**ConversationRatingService (the only sender in this subsystem)**
- R/app/Services/ConversationRatingService.php:37-42 — `BUTTON_LABEL_MAX=20`, `BODY_MAX=1024` are Meta cta_url limits — other channels have different/no limits; these must live in the WA driver, not the generic service.
- :68 — `MessagingWindowHelper::isMessagingWindowOpen($contact)` = fixed 24h from `last_inbound_chat_created_at` (R/app/Helpers/MessagingWindowHelper.php:11,13-26). Telegram has no window; Messenger/Instagram have their own 24h policy; TikTok has its own — the window must be resolved per channel.
- :124-134 `normalizeButtonLabel()` truncates to 20 chars (WA).
- :207 — snapshot `contact_phone => $contact->phone`; NULL for non-WA contacts, so ratings list/search by phone (R/app/Http/Controllers/User/RatingController.php:56, R/app/Http/Controllers/Api/MobileReportController.php:82) and the ActivityLogger label (:351, MobileReportController:154, User/RatingController:121) degrade to null/'—'.
- :248-264 `buildBody()` — "Meta rejects empty body" fallback; `ContactPlaceholderService::replace()` (R/app/Services/ContactPlaceholderService.php:93) exposes `{phone}` placeholder = NULL for new channels.
- :275-292 `send()` — reads `metadata['whatsapp']['access_token'|'phone_number_id'|'app_id'|'waba_id']`, `config('graph.api_version')`, constructs `WhatsappService`; logs `'Conversation rating skipped: WhatsApp is not configured'` if missing (a test asserts this exact string: R/tests/Feature/RatingSurveyButtonTest.php:294).
- :294-300, :320 — message type string `'interactive call to action url'` (WhatsappService vocabulary) with `['display_text','url']`; :311-316 fallback to type `'text'`.
- :322-329 `succeeded()` expects WhatsappService's response shape `{success: bool}` (object with `getData()` or array).
- Downstream: `WhatsappService::executeSendMessage()` (R/app/Services/WhatsappService.php:222-247) short-circuits with `Log::error('WhatsApp send skipped: contact has no phone number')` when `isSendableNumber()` (:85-88, `trim(phone) !== ''`) fails. So closing a ticket of a phone-less contact today: creates a pending row, logs an ERROR, force-deletes the row. Safe but noisy.
- :166-167 `shouldAskAgain()` couples to the literal English ticket-log text `'%closed to open%'` (written at R/app/Models/Contact.php:491-495 and R/app/Jobs/ProcessTicketAssignmentJob.php:173). Not WhatsApp-specific, but any channel that writes different log text breaks the per-conversation rating rule.

**MessagingWindowHelper (used by rating + ResetConversationRating)**
- R/app/Helpers/MessagingWindowHelper.php:11 `WINDOW_HOURS = 24`; :76-95 error messages literally say "Whatsapp does not allow sending messages 24 hours…"; :58-74 `payloadForContact()` emits `is_messaging_window_open` consumed by chat UI/mobile.

**DashboardController**
- R/app/Http/Controllers/User/DashboardController.php:15 unused `use WhatsappService`.
- :42 `templateCount` (WA templates); :43 `graphAPIVersion`; :48 Setting keys `is_embedded_signup_active`, `whatsapp_client_id`, `whatsapp_config_id`; :60 `setupWhatsapp = !isset($config['whatsapp'])`; :64-66 `embeddedSignupActive`, `appId`, `configId`. Consumed by R/resources/js/Pages/User/Dashboard.vue:130-136 (banner "Create a new WhatsApp number" / coexistence). An org that connects only Telegram would forever see "set up WhatsApp".
- :34-39, :110-125 counts are channel-agnostic (`chats.type` inbound/outbound only).

**UserVerificationService / SendUserVerificationWhatsappJob (user OTP — intentionally WhatsApp)**
- R/app/Services/UserVerificationService.php:106 `Template::where(org)->where('id', 4)` hardcoded template id; :111,221-252 E.164 normalization of `users.phone`; :116-131 Meta template payload `{name, language:{code}, components:[{type:'body',parameters:[{type:'text',text}]}, {type:'button',sub_type:'url',index,parameters}]}` posted to internal `/api/send/template`; :133 reads `data.success`; :169 methods `['email','whatsapp']`. This is about verifying the platform USER, not a contact — stays WA; no channel abstraction needed.

**Ticket controllers / ActivityLogger labels**
- R/app/Http/Controllers/User/ChatTicketController.php:77, :106 — `name ?: $contact->phone` as activity subject label.
- R/app/Http/Controllers/ApiController.php:2464 (`logMobileActivity`) — same fallback for mobile ticket actions.
- R/app/Http/Controllers/Api/MobileTicketController.php:61 search `contacts.phone LIKE`; :77,:89 returns `phone` per ticket item.
- R/app/Models/ChatTicket.php:13,71 — doc comments say "محادثة واتساب" (docs only).

**Console commands**
- R/app/Console/Commands/DiagnoseUnreadChats.php:28 arg doc "رقم الهاتف أو uuid"; :78 prints `$contact->phone`; :150 `preview()` reads Meta metadata shape `$decoded['text']['body'] ?? '[' . $decoded['type'] . ']'`; :207-211 lookup by digits `phone LIKE %digits%` (uuid path at :203-204 still works for any channel).
- R/app/Console/Commands/ResetConversationRating.php:146 arg "رقم الهاتف أو معرّف"; :164 prints phone; :173-176 prints "نافذة 24 ساعة"; :208-219 resolves by numeric id or phone variants only.
- R/app/Console/Commands/BackfillContactFormattedPhone.php:22-25 — `whereNotNull('phone')` so NULL-phone contacts are naturally skipped (OK).
- R/app/Console/Commands/BackfillCampaignWebhookFailures.php:262-270 joins `campaign_logs`→`chats.status = 'failed'`; :349-365 parses `chat_status_logs.metadata` as Meta status webhook `{"status":"failed","errors":[…]}` and :302 `CampaignRetryService::isRetryableFailure($errors)` (Meta error codes). Will misclassify non-WA failure logs unless they write the same shape or are filtered out.
- R/app/Console/Commands/UnescapeChatMetadata.php:250 tables `chats`, `chat_status_logs`; assumes `metadata` is JSON text (channel-agnostic as long as new channels also store JSON in `chats.metadata`).
- R/app/Console/Commands/UpdateLastInboundChatCreatedAt.php:75-84 and UpdateLatestChatCreatedAt.php:110-119 — by `chats.type='inbound'` / latest chat; channel-agnostic (a contact from any channel gets `last_inbound_chat_created_at`, which then feeds the 24h window helper).
- R/app/Console/Commands/TestCommand.php:82 commented dispatch with WA payload shape `['id','from','timestamp','type','text'=>['body']]` + `['profile'=>['name']]`; :50 reads `formatted_phone_number`. Dev-only.
- R/app/Console/Commands/SyncTemplateData.php — description "Sync template data from WhatsApp API", empty stub.

**Reports / metrics**
- R/app/Services/AgentPerformanceService.php:52,171,185 — relies on `chats.type` ∈ {inbound, outbound} and `chats.user_id` set on agent-sent outbound. Channel-agnostic, but new channel senders MUST populate `chats.user_id` and `type='outbound'` or agents get no credit / no "online" signal (:126-131).
- R/app/Http/Controllers/Api/MobileReportController.php:97 returns `contact_phone` (may be null).

**Waz Business** — R/app/Services/WazSyncService.php and WazBusinessService.php: "contact"/"phone" refer to the Waz billing platform contact of the org user (`users.waz_contact_id`, `users.phone`). No chat-contact or WhatsApp coupling. Nothing to change.

**Cache / metadata coupling (not WA-specific but affects adding channel settings)**
- R/app/Jobs/ProcessTicketAssignmentJob.php:92-99 caches the entire decoded `organizations.metadata` under `org_settings_{id}` for 30 min; :252-271 round-robin rewrites the whole JSON under row lock. R/app/Http/Controllers/User/DashboardController.php:76-85 and R/app/Http/Controllers/User/SettingController.php:268-296 do read-modify-write of the whole JSON without a lock. Any new per-channel settings tab writing `organizations.metadata` (e.g. `telegram`, `messenger`, `instagram`, `tiktok` keys) participates in this race and must `Cache::forget("org_settings_{id}")`.

## required_changes

`R` = /media/salah/Software/projects/mnjuz

### Already channel-agnostic — NO change needed
- R/app/Jobs/ProcessTicketAssignmentJob.php (whole file), R/app/Models/ChatTicket.php, R/app/Models/ChatTicketLog.php, R/app/Models/ChatLog.php, R/app/Models/Contact.php:475-540 (`toggleTicketStatus`, `assignToUserThroughTicket`, `ticket()`), R/app/Services/ChatNoteService.php, R/app/Http/Controllers/User/ChatNoteController.php, R/app/Services/TicketService.php (support tickets), R/app/Services/AgentPerformanceService.php, R/app/Http/Controllers/User/AgentPerformanceController.php, R/app/Services/ActivityLogger.php (core), R/app/Http/Controllers/User/DeveloperController.php, R/app/Services/OrganizationApiService.php, R/app/Services/WazSyncService.php, R/app/Services/WazBusinessService.php, R/app/Console/Kernel.php, R/app/Console/Commands/{PruneActivityLogs,UpdateLastInboundChatCreatedAt,UpdateLatestChatCreatedAt,UnescapeChatMetadata,BackfillContactFormattedPhone,ReindexAllTables,SendErrorReportCommand,…}.php, R/app/Services/UserVerificationService.php + R/app/Jobs/SendUserVerificationWhatsappJob.php (user OTP stays WhatsApp by design).
- DB: `chat_tickets`, `chat_ticket_logs`, `chat_logs`, `chat_notes` have no organization_id and no channel; they hang off `contact_id`. Do NOT add channel columns to them — derive channel via `contacts` join. `conversation_ratings`, `agent_activity`, `activity_logs` carry organization_id only.

### 1. Inbound pipeline contract for each new channel (Telegram/Messenger/Instagram/TikTok)
Each channel's incoming-message job must, after inserting the `chats` row and the `chat_logs` row (`entity_type='chat'`), call exactly `ProcessTicketAssignmentJob::dispatchSync($contact->id, $organizationId, true)` (mirror R/app/Jobs/ProcessIncomingMessageJob.php:66-73) and use its ?int return the same way. Recommended: extract that block (createChatLog + ticket + media + working-hours + auto-reply + NewChatEvent) into a channel-agnostic `InboundPostProcessor`/trait so all five channel jobs share it instead of re-implementing it. Requirements the job silently depends on: `contacts.organization_id` set, `chats.type='inbound'`, `contacts.last_inbound_chat_created_at`/`latest_chat_created_at` updated (so `ensureChatTicketsExist` and the messaging-window helper work).

### 2. Rating survey — introduce channel driver + per-channel window
- R/app/Services/ConversationRatingService.php:68 — replace `MessagingWindowHelper::isMessagingWindowOpen($contact)` with a channel-aware policy, e.g. `MessagingWindowHelper::isOpenFor(Contact $contact)` that reads `$contact->channel` and a `ChannelWindowPolicy` map (`whatsapp => 24h`, telegram => none, messenger/instagram/tiktok => their own values kept in one config `config/channels.php`). Keep `WINDOW_HOURS=24` as the WA value and keep `isMessagingWindowOpen()` signature returning the WA behavior for `channel='whatsapp'`/NULL so existing callers do not change.
- :273-317 `send()` — move the WhatsApp-specific body (metadata.whatsapp lookup, `WhatsappService`, `'interactive call to action url'`, text fallback, `succeeded()` shape) into `App\Services\Channels\WhatsApp\...` behind an interface such as `ChannelMessenger::sendLinkMessage(Contact $c, string $body, string $buttonLabel, string $url): SendResult`. Telegram driver = inline keyboard URL button; Messenger/Instagram = button template / generic link; TikTok = text + link (or skip). `onConversationClosed()` should early-return `null` with an info log when the resolved driver reports `supportsOutboundLink()===false` or credentials missing — BEFORE `createPending()` so no create+forceDelete churn and no ERROR-level log from WhatsappService.
- :37-42, :124-134, :263 — keep the 20/1024 limits inside the WA driver (`WhatsappService::CTA_URL` constraints); generic service passes raw text.
- :207 — additive columns on `conversation_ratings`: `channel VARCHAR(32) NULL` and `contact_identifier VARCHAR(191) NULL` (display id: phone for WA, username/external id otherwise); keep `contact_phone` untouched for backward compatibility. Update search at R/app/Http/Controllers/User/RatingController.php:52-58 and R/app/Http/Controllers/Api/MobileReportController.php:78-85 to `orWhere('contact_identifier', like)`; add `channel` + `contact_identifier` to both list transforms (:68-78 / :92-102). Document for mobile.
- :166-167 keep the `'%closed to open%'` literal strings unchanged in every writer (Contact.php:491-495, ProcessTicketAssignmentJob.php:130,173, ChatService.php:494,525). If localized text is wanted, localize at render time (ChatThread.vue:134), never in the DB.
- R/app/Helpers/MessagingWindowHelper.php:76-95 — messages mention "Whatsapp"; make them channel-aware (`__('… :channel …')`) once the helper takes a channel.

### 3. Contact display identifier for labels/search
Add `Contact::displayIdentifier(): ?string` (phone for WA; `external_id`/username for others) and use it at: R/app/Http/Controllers/User/ChatTicketController.php:77,106; R/app/Http/Controllers/ApiController.php:2464; R/app/Services/ConversationRatingService.php:206-207 (snapshot); R/app/Http/Controllers/Api/MobileTicketController.php:56-63 (search) and :89 (add `channel` + `identifier` fields, keep `phone`). Optional: add `?channel=` filter to `MobileTicketController::index/summary` (`contacts.channel = ?` on the existing contacts join) to match the requested channel filter in the chat list.

### 4. DashboardController
R/app/Http/Controllers/User/DashboardController.php:60 — keep `setupWhatsapp` for the existing Vue, add `channels: {whatsapp: bool, telegram: bool, messenger: bool, instagram: bool, tiktok: bool}` derived from a `ChannelRegistry::configured($org)` helper so Dashboard.vue:130-136 can show "connect a channel" instead of WhatsApp-only; remove unused import :15. Chat/contact counts (:34-41,110-125) stay total; per-channel counts are optional and would need `contacts.channel` (join) or a denormalized `chats.channel`.

### 5. Console commands
- R/app/Console/Commands/DiagnoseUnreadChats.php:193-212 — accept `--channel=` and match `contacts.external_id`/username when the needle is not a uuid or digits; :78 use `displayIdentifier()`; :142-153 `preview()` should call the shared text-extractor used by the chat list (whatever the chats subsystem introduces, e.g. `ChatMetadataReader::previewText(array $meta, string $channel)`), keeping the `text.body` path for WA.
- R/app/Console/Commands/ResetConversationRating.php:198-220 — same identifier resolution; :173-176 label the window per channel.
- R/app/Console/Commands/BackfillCampaignWebhookFailures.php:262-270 — add `->join('contacts', ...)->where('contacts.channel', 'whatsapp')` (or `campaigns.channel`) unless the new channel status logs are written in the same `{status:'failed', errors:[…]}` envelope AND `CampaignRetryService::isRetryableFailure()` gets channel-aware error tables.
- R/app/Console/Commands/UnescapeChatMetadata.php — no change; just require new channels to store JSON in `chats.metadata`/`chat_status_logs.metadata`.

### 6. Organization metadata writes (shared concern)
All whole-JSON rewrites in this subsystem (R/app/Http/Controllers/User/DashboardController.php:76-85, R/app/Http/Controllers/User/SettingController.php:268-296, R/app/Jobs/ProcessTicketAssignmentJob.php:252-271) should move to one `OrganizationSettingsWriter::merge(int $orgId, array $patch)` that does `SELECT … FOR UPDATE` + `JSON_MERGE_PATCH`/PHP array_replace_recursive on the fresh row + `Cache::forget("org_settings_{id}")`. New channel settings tabs (`/settings/whatsapp` becoming tabbed) MUST use it, otherwise saving the Telegram tab can overwrite `tickets`, `ratings`, `whatsapp` or `round_robin_last_user_id`.

### 7. Kernel
No scheduler change is required for tickets/reports. If a channel needs polling (e.g. Telegram long-poll fallback) or token refresh (Messenger/Instagram long-lived tokens, TikTok refresh), add named `->withoutOverlapping()` entries in R/app/Console/Kernel.php next to :74-82. Campaign scheduling (:27-54) is channel-agnostic at this level.

### 8. Mobile API documentation deltas produced by this subsystem
- `GET /api/v1/tickets` items: add `channel` (string) and `identifier` (string|null); `phone` may be null for non-WA.
- `GET /api/v1/reports/ratings` items: add `channel`, `contact_identifier`; `contact_phone` may be null.
- `GET /api/v1/reports/agent-performance`: unchanged (optionally `channel` query filter).
- `GET /api/v1/reports/activity-log`: unchanged; `subject_label` now uses displayIdentifier fallback.
- Ticket-log entities in `/api/v1/sync*` threads: unchanged shape `{type:'ticket', value:{id, contact_id, description, created_at, updated_at}}`.

## risks

`R` = /media/salah/Software/projects/mnjuz

1. **Duplicate/missing tickets on new channels.** `ProcessTicketAssignmentJob` relies on `lockForUpdate` on the latest ticket + 3 deadlock retries (R/app/Jobs/ProcessTicketAssignmentJob.php:55-74) and on being called SYNC inside the inbound job. If a new channel's inbound job is dispatched twice for the same update (Telegram retries, Meta duplicate deliveries) and the channel's `wam_id`-equivalent dedupe is missing, you get two inbound chats but still one ticket (the lock serializes), so the ticket layer is safe — the risk is upstream dedupe, not here. Do NOT switch to `dispatch()` (queued): `ShouldBeUnique` + `uniqueFor=120` (:21-28,41-44) would then DROP a second inbound within 120 s for the same contact, which is fine for WA today only because the sync path bypasses the unique lock.
2. **`ensureChatTicketsExist()`** (R/app/Services/ChatService.php:1186-1215) bulk-creates open tickets for any contact with `latest_chat_created_at` — new-channel contacts will be swept in automatically on first chat-list load once tickets are active. Harmless, but it means channel contacts appear as "open / unassigned" without an `'opened'` ticket log. Same behavior as WA today.
3. **Rating churn and error noise.** Until §2 of required_changes is done, every close of a phone-less contact ticket inserts and force-deletes a `conversation_ratings` row and writes `Log::error('WhatsApp send skipped…')` (R/app/Services/WhatsappService.php:222-230). With 4 new channels that becomes a steady stream of ERRORs and wasted auto-increment ids. Gate before `createPending()`.
4. **Rating "new conversation" detection** depends on the exact English text `'closed to open'` (R/app/Services/ConversationRatingService.php:167). Changing ticket-log wording anywhere (ProcessTicketAssignmentJob.php:173, Contact.php:491-495, ChatService.php:525) silently disables repeat surveys.
5. **organizations.metadata clobbering.** Whole-JSON read-modify-write sites (SettingController:268-296, DashboardController:76-85, ProcessTicketAssignmentJob:252-271 under lock; the first two without lock). A new channel settings tab that reads metadata, waits, then saves will overwrite concurrent writes: round-robin pointer (minor), ticket/rating settings, or — worst — the WhatsApp `access_token` saved from another tab/embedded signup. This is the single most likely way to "break WhatsApp" from this subsystem.
6. **30-minute settings cache.** `org_settings_{id}` (ProcessTicketAssignmentJob:92-99) is only invalidated by SettingController::tickets and round-robin. If any new code reuses this key to read channel credentials, stale tokens will be served for up to 30 min after reconnecting a channel. Either always `Cache::forget` after metadata writes or keep channel credentials out of this key.
7. **Agent performance accuracy.** New-channel outbound rows without `chats.user_id` count as "someone replied" (pairing unset at R/app/Services/AgentPerformanceService.php:201) but credit nobody, and agents sending only via Telegram would look offline (:126-131). Bot/auto replies must leave `user_id` NULL (as WA auto-replies do) to avoid crediting an agent. `firstResponseAverages()` cursors all org chats in range (line 169-177): adding channels increases volume linearly; it already avoids memory blowup via `cursor()`.
8. **`chats.status='failed'` semantics.** `BackfillCampaignWebhookFailures` and `CampaignRetryService::isRetryableFailure` interpret Meta error codes; running the command after non-WA campaigns exist could mark non-WA logs `failed`/resendable incorrectly or skip them silently ("no failure detail"). Filter by channel.
9. **Mobile ticket endpoints lack authorization** (R/app/Http/Controllers/ApiController.php:3199-3222 uses `Contact::find($id)` with no org check; :3343-3382 no Team check). Pre-existing; multi-channel doesn't worsen it, but if you touch these endpoints, add the same `authorizeConversation` rule as R/app/Http/Controllers/User/ChatTicketController.php:23-61.
10. **Timezone accessors on models** (ChatTicket.php:187-195, ChatTicketLog.php:16-24, ChatLog.php:19-28) convert on read; `AgentPerformanceService` correctly uses `DB::table` (raw UTC). New code computing durations must also bypass Eloquent accessors or it will mix UTC and org-local strings.
11. **ActivityLogger** never throws (:180-221) and `featureEnabled` is cached per request — safe. But `subject_label` for a nameless non-WA contact becomes NULL → rendered '—' (:165-174); not data loss, just useless log lines until displayIdentifier is used.
12. **Data loss vectors specifically:** none of the proposed changes touch existing rows; all suggested DDL is additive nullable columns on `conversation_ratings`. `UnescapeChatMetadata` is the only command that rewrites `chats.metadata` in place; it proves lossless round-trip before writing (R/app/Console/Commands/UnescapeChatMetadata.php:299-315) and must still be run only with `--dry-run` first on production. `ResetConversationRating` does `forceDelete` of ratings by design (line 166-168).

## tests

`R` = /media/salah/Software/projects/mnjuz

**Existing tests covering this subsystem**
- R/tests/Unit/ConversationRatingTest.php — pure unit (no DB): link validity (`isOpenForSubmission`, `isExpired`, submitted vs expired), `LINK_VALID_DAYS=7`, `decideAsk()` matrix (never asked, cooldown blocks, reopen after cooldown asks, time alone doesn't, old reopen ignored, zero cooldown, 30-day boundary), `normalizeCooldown` defaults/clamping, `OrganizationRole::isOwnerOnly/isPrivileged`.
- R/tests/Feature/RatingSurveyButtonTest.php (RefreshDatabase) — Meta cta_url payload shape via `WhatsappService::buildInteractivePayload` (type interactive/cta_url, display_text+url, body, header/footer omission), reply-button regression, `buildBody()` (no `{rating_link}`/http left, dangling punctuation trimmed, link-only falls back to default text, capped at BODY_MAX), `normalizeButtonLabel` (default/custom/20-char cut/blank), `WhatsappService::ctaUrlMetadata()` keeps `type:'text'` and `cta_url` in chat metadata, `onConversationClosed()` gating: off => null + no row + never reaches sending; on + open window => reaches the `'WhatsApp is not configured'` log line; default off.
- R/tests/Feature/MobileApi/MobileReportTest.php — `/api/v1/reports/agent-performance` (structure, default 30-day range, custom range, agent 403, plan feature 403), `/api/v1/reports/ratings` (list+summary avg, summary follows filter, search by contact_name, pending counted not listed, agent 403), delete (owner ok, manager 403, unknown 404, cross-org 404), `/api/v1/reports/activity-log` (list, retention_days, members/groups keys, user_id filter, agent 403, plan feature 403).
- R/tests/Feature/MobileApi/MobileTicketTest.php — `/api/v1/tickets` list/status filter/assignee filter/`unassigned`/search by first_name/cross-org hidden/agent scoping/manager sees all/`assigned_to_name`; `/api/v1/tickets/summary` counts + agent scoping; 401 unauthenticated. Inserts `chat_tickets` directly with phone contacts.
- R/tests/Feature/Cycle/InboundSideEffectsTest.php (CycleTestCase posts a real WA webhook payload) — no ticket when feature off; one ticket on first inbound when `metadata.tickets.active=true`; second message doesn't create a second ticket; auto-reply dispatch on queue `autoreplies`; no auto-reply at message limit; NewChatEvent; media delays broadcast; away-notice once/hour; echo tests. Note `enableTickets()` writes `'assignment' => 'manual'` (not `assignment_mode`), so only the manual default path is exercised.
- R/tests/Feature/DiagnoseUnreadChatsTest.php — `chat:diagnose-unread` by phone/uuid, verdict strings, auto-reply attribution window, read-only guarantee (counts INSERT/UPDATE/DELETE = 0), unknown contact exit 1.
- R/tests/Feature/ContactNameCaptureTest.php:182-225 — rating row falls back to current contact name / snapshot wins.
- R/tests/Feature/MobileApi/MobileSupportTicketTest.php — support `tickets` table + `WazSyncService` (not chat tickets; unaffected by channels).
- Test bases: R/tests/Feature/Cycle/CycleTestCase.php (org with `metadata.whatsapp` creds, identifier, subscription; runs FlowBuilder migrations), R/tests/Feature/MobileApi/MobileApiTestCase.php (owner + Team + addons + plan).

**Gaps (to add before/while going multi-channel)**
- No direct tests of `ProcessTicketAssignmentJob`: auto (least-busy), round_robin (pointer persistence + wrap-around + Cache::forget), reopen with/without `reassign_reopened_chats`, `assigned_seen` semantics, `is_latest` sync, `isNewChat=false` no-op, tickets inactive no-op.
- No tests for `Contact::toggleTicketStatus` / `assignToUserThroughTicket` (ticket log wording, ChatLog rows, markInboundAsRead on close, rating trigger) nor for web `ChatTicketController::update/assign` authorization (agent on someone else's ticket => 403).
- No test that closing a ticket for a contact with `phone = NULL` (new channel) does not create a rating row nor log at error level — this is the key regression test for the channel-driver refactor.
- No test for `MessagingWindowHelper` per-channel behavior (needed once channel policy is added) beyond `InboundSideEffectsTest::test_an_echo_does_not_open_the_reply_window`.
- No unit test for `AgentPerformanceService::metrics()` / `firstResponseAverages()` pairing (inbound→first agent outbound, auto-reply without user_id ends the wait without credit), nor for `recordHeartbeat` race/visible=false.
- No tests for `ActivityLogger::log` (feature gating, mobile org resolution from `current_mobile_organization_id`, truncation), nor for `subject_label` fallback when phone is NULL.
- No tests for `ChatNoteService` authorization/store/delete.
- No tests for `MobileTicketController` search by phone or listing contacts with NULL phone / channel filter (to add with the `channel` field).
- No tests for console commands `contacts:update-last-inbound-chat-created-at`, `contacts:update-latest-chat-created-at`, `campaigns:backfill-webhook-failures`, `ratings:reset`, `activity:prune`, `chat:unescape-metadata`.
- No test for `DashboardController::index` props (`setupWhatsapp` and future `channels`).
- No test of `UserVerificationService::sendWhatsappCode` payload or email fallback in `SendUserVerificationWhatsappJob`.

## data_shapes

`R` = /media/salah/Software/projects/mnjuz

### DB tables (this subsystem)
- `chat_tickets` (R/database/migrations/2024_05_11_052925 + 2026_03_01 + 2026_07_06 + 2025_01_24): `id`, `contact_id INT`, `assigned_to INT NULL`, `status VARCHAR(128)` ∈ {'open','closed'}, `is_latest TINYINT default 0`, `assigned_seen TINYINT default 1`, `created_at`, `updated_at`. Indexes: (contact_id,is_latest) `idx_chat_tickets_contact_is_latest`, (contact_id,assigned_to,status) `idx_chat_tickets_contact_assigned_to_status`. No organization_id, no channel.
- `chat_ticket_logs`: `id`, `contact_id INT`, `description TEXT NULL`, `created_at`, `updated_at`. Description literals written by code (must stay byte-identical): `'Conversation was opened'`, `'Conversation was moved from closed to open'`, `'Conversation was moved from opened to closed'`, `'Conversation was assigned to {first_name} {last_name}'`.
- `chat_logs` (R/database/migrations/2024_05_11_054010; the original chat_logs was renamed to `chat_status_logs` in 2024_05_11_053846): `id`, `contact_id INT`, `entity_type VARCHAR(128)` ∈ {'chat','ticket','notes'}, `entity_id INT`, `deleted_by INT NULL`, `deleted_at`, `created_at`, `updated_at`.
- `chat_notes`: `id`, `uuid CHAR(50)`, `contact_id`, `content TEXT`, `created_by`, `deleted_at`, `deleted_by`, timestamps.
- `conversation_ratings` (2026_08_19): `id`, `uuid` (unique), `organization_id`, `contact_id NULL`, `agent_id NULL`, `contact_name NULL`, `contact_phone VARCHAR(32) NULL`, `agent_name NULL`, `token VARCHAR(64)` unique (48 random chars), `rating TINYINT NULL`, `comment TEXT NULL`, `status VARCHAR(16)` ∈ {'pending','submitted'}, `sent_at`, `submitted_at`, `expires_at` (= created + 7 days), `ip VARCHAR(45)`, timestamps, `deleted_at`. Indexes (organization_id,status,id), (organization_id,contact_id).
- `agent_activity`: `id`, `organization_id`, `user_id`, `activity_date DATE`, `active_seconds`, `last_ping_at`, timestamps; unique (organization_id,user_id,activity_date).
- `activity_logs`: `id`, `organization_id`, `user_id NULL`, `user_name(191)`, `event(64)`, `subject_type(64)` (currently 'contact' or null), `subject_id`, `subject_label(191)`, `properties JSON`, `ip`, `user_agent(255)`, `created_at` (no updated_at).
- `organization_api_keys`: `id`, `uuid`, `organization_id`, `token` (40 random, unique), `deleted_at`, `deleted_by`, timestamps.

### organizations.metadata keys read/written here (JSON, whole-blob rewrite)
```
{
  "tickets": {"active": bool, "assignment_mode": "manual|auto|round_robin|shared", "auto_assignment": bool,
              "reassign_reopened_chats": bool, "allow_agents_to_view_all_chats": bool,
              "encrypt_contacts_for_agents": bool, "round_robin_last_user_id": int},
  "ratings": {"active": bool, "message": string (may contain {rating_link} + contact placeholders),
              "button_label": string(<=20), "cooldown_days": int 0..365},
  "whatsapp": {"access_token", "phone_number_id", "app_id", "waba_id"},   // read by rating send()
  "notification": {"team": bool},                                          // DashboardController::dismissNotification
  "timezone": string
}
```
Cache: `org_settings_{organizationId}` = `json_decode(metadata)` (stdClass), TTL 1800 s.

### Ticket-log entity as it appears in threads (web Inertia `chatThread` and mobile sync)
`{"type": "ticket", "value": {"id": int, "contact_id": int, "description": string, "created_at": "Y-m-d H:i:s" (org tz), "updated_at": string|null}}` — R/app/Services/ChatService.php:1165-1174, R/app/Http/Controllers/ApiController.php:2169-2174. Notes: `{"type":"notes","value":{id, uuid, contact_id, content, created_by, created_at, updated_at, deleted_at, deleted_by}}`.
Mobile sync per contact additionally: `ticket_status: 'open'|'closed'|null`, `ticket_assigned_to: int|null` (ApiController.php:2184-2185, from `Contact::ticket()` hasOne — note: hasOne without is_latest filter).
Web chat header prop `ticket`: `ChatTicket::with('user')` -> `{id, contact_id, assigned_to, status, is_latest, assigned_seen, created_at, updated_at, user: {id, full_name, …}|null}` (ChatService.php:323-358; ChatHeader.vue:62-71).

### Web endpoints
- `PUT /tickets/{contactUuid}/update` body `{status: 'open'|'closed'}` -> redirect back with flash `{type:'success', message}`.
- `PUT /tickets/{contactUuid}/assign` body `{id: userId}` -> redirect back.
- `POST /notes` body `{contact: uuid, notes: string}` -> flash includes `contact` with `lastChat`, `lastInboundChat`, `notes`.
- `GET|POST /rate/{token}` -> Inertia `Rating/Form` props `{state: 'invalid'|'open'|'submitted'|'expired', token, organizationName, stars}`; POST body `{rating: 1..5, comment?: <=2000}`.
- `POST /performance/heartbeat` `{visible?: bool}` -> `{success: true}`.
- `GET /performance?from&to` -> Inertia `User/Performance` `{title, metrics, filters:{from,to}}`.
- `GET /ratings?rating&search` -> Inertia `User/Ratings` `{rows (paginator of items below), filters, summary:{total, average|null, pending}, canDelete, deletionAllowedByPlan}`; `DELETE /ratings/{uuid}`.
- `GET /dashboard` -> props `{subscription, subscriptionDetails, subscriptionIsActive, chatCount, campaignCount, contactCount, templateCount, graphAPIVersion, campaigns[≤5 pending|scheduled], setupWhatsapp: bool, period: [7 ISO dates], inbound: int[7], outbound: int[7], embeddedSignupActive, appId, configId, title}`.

### Mobile API (Sanctum, /api/v1) — exact response shapes
- `GET /reports/agent-performance?from&to` -> `{success, data: {metrics: {from: 'Y-m-d', to: 'Y-m-d', agents: [{user_id, name, email, role, messages_sent, tickets_assigned, tickets_closed, avg_first_response_seconds: int|null, avg_resolution_seconds: int|null, active_seconds, last_activity_at: string|null, online: bool}]}, filters: {from, to}}}`.
- `GET /reports/ratings?rating&search&per_page&page` -> `{data: {items: [{uuid, contact_name: string|null, contact_phone: string|null, agent_name, rating, comment, submitted_at: 'Y-m-d H:i:s'}], pagination: {page, per_page, total, last_page}, summary: {total, average: float|null, pending}, can_delete: bool, deletion_allowed_by_plan: bool}}`.
- `DELETE /reports/ratings/{uuid}` -> `{success: true, message}` | 403 | 404.
- `GET /reports/activity-log?user_id&group&event&search&from&to&per_page` -> `{data: {items: [{id, user_name, user_id, event, description (Arabic sentence), subject_label, ip, created_at}], pagination, members: [{id, name}], groups: ['account','contacts','chats','tickets','team','campaigns','settings','ratings'], retention_days: 7}}`.
- `GET /tickets?status&assigned_to(int|'unassigned')&search&per_page` -> `{data: {items: [{id, status, contact_id, contact_uuid, contact_name: string|null, phone, assigned_to: int|null, assigned_to_name: string|null, assigned_seen: bool, last_message_at, updated_at}], pagination}}` (R/app/Http/Controllers/Api/MobileTicketController.php:83-97).
- `GET /tickets/summary` -> `{data: {open, closed, unassigned}}`.
- `POST /toggle-ticket-status/{contactId}` `{status}` -> `{statusCode: 200, success: true, message}` | 404.
- `POST /assign-ticket` `{contact_id, user_id}` -> `{statusCode, success, message}` | 400 `{error: {...}}` | 404.
- `POST /performance/heartbeat` `{visible?}` -> `{success: true}`.

### ActivityLogger call signature and event vocabulary
`ActivityLogger::log(string $event, ?string $subjectLabel, ?string $subjectType, $subjectId, array $properties = [], ?int $organizationId = null, ?int $userId = null, ?string $userName = null)`. Events used by this subsystem: `ticket_assigned` (props `{assigned_to: "First Last"|email}`), `ticket_closed`/`ticket_reopened` (props `{status}`), `rating_received`/`rating_deleted` (props `{rating}`, userName 'Customer' for received). Full catalog R/app/Services/ActivityLogger.php:21-67; groups :147-162.

### Rating message sent (WhatsApp shape the drivers must map)
Body = `settings.message` with `{rating_link}` removed and placeholders replaced, ≤1024 chars; button `{display_text: ≤20 chars, url: "{APP_URL}/rate/{token}"}`; WA type `'interactive call to action url'` (-> Meta `interactive.type='cta_url'`, `action.name='cta_url'`, `action.parameters{display_text,url}`); fallback text `body + "\n" + url`. Chat row saved by WhatsappService as metadata `{type:'text', text:{body: body+link}, cta_url:{display_text,url}}` (asserted at R/tests/Feature/RatingSurveyButtonTest.php:231-242).

### Messaging window payload (consumed by chat list/mobile; from R/app/Helpers/MessagingWindowHelper.php:58-74)
`{last_inbound_chat_created_at: 'Y-m-d H:i:s'|null, last_inbound_chat_created_at_iso: ISO8601|null, last_inbound_chat: {created_at, created_at_iso}|null, is_messaging_window_open: bool}`; closed-window error `{success:false, message:'Whatsapp does not allow…'}` 422 (web) / `{statusCode:422, success:false, message}` (api).

### Inbound contract inputs the ticket job needs
`ProcessTicketAssignmentJob::__construct(int $contactId, int $organizationId, bool $isNewChat=false)`; `handle(): ?int` (assigned user id or null). Requires `contacts.organization_id`, `teams` rows with `status='active'` and `deleted_at IS NULL`, and `organizations.metadata.tickets`.
