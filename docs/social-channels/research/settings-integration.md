# Subsystem: settings-integration


## summary

The settings-integration subsystem is the per-organization WhatsApp Cloud API connection. Credentials and account status are stored **in plaintext** as a nested `whatsapp` object inside `organizations.metadata` (a MySQL `json` column since migration `2024_10_16_201832_change_metadata_column_in_organizations_table.php:15`, but the model has no cast, so every reader does `json_decode($org->metadata, true)` and every writer does read-modify-write of the whole blob then `json_encode` + `save()`, with no locking and no transaction). Platform-level Meta app keys (`whatsapp_client_id`, `whatsapp_client_secret`, `whatsapp_config_id`, `whatsapp_access_token`, `whatsapp_callback_token`) live in the global `settings` key/value table (`app/Models/Setting.php`) and are redacted for the browser by `SettingService::SECRET_KEYS`.

The web page is `/settings/whatsapp` → `SettingController::viewWhatsappSettings` → Inertia `User/Settings/Whatsapp.vue`, wrapped in `Settings/Layout.vue` (left nav + scrollable content column). Connection is created either manually (`POST /settings/whatsapp` → `saveWhatsappSettings`, which validates the credentials against 4 Graph API calls before persisting) or via Meta Embedded Signup (`modules/EmbeddedSignup`, FB JS SDK → `POST /whatsapp/exchange-code` → `RegisterController::handleSignup`). "WhatsApp is connected" is decided everywhere by the mere existence of the key: PHP `isset($metadata['whatsapp'])`, Vue `settings?.whatsapp` (Whatsapp.vue, Dashboard.vue, CampaignForm.vue, Templates/Add.vue); `WhatsappConnectionHelper::errorFor()` additionally requires `access_token`, `phone_number_id`, `waba_id` to be non-empty. There is **no middleware** that checks WhatsApp is connected (grep of `app/Http/Middleware/*` and `Kernel.php` for "whatsapp" returns nothing); gating is purely in-page.

`Organization::toArray()` (the serialization Inertia uses for the `settings` prop) strips `whatsapp.access_token` and adds `whatsapp.access_token_is_set`. The mobile API (`MobileSettingController`, `/api/v1/settings/general|working-hours|placeholders`) exposes **nothing** about the WhatsApp connection or channels; it only reads/merges general keys of the same metadata blob. Every new channel will need its own metadata namespace (e.g. `metadata.telegram`, `metadata.messenger`, `metadata.instagram`, `metadata.tiktok`), its own redaction in `Organization::toArray()`, its own tab in the settings page, and a new mobile endpoint to report which channels are connected.

## current_flow

### A. Viewing the WhatsApp settings page
1. `routes/web.php:273` `GET /settings/whatsapp` → `App\Http\Controllers\User\SettingController::viewWhatsappSettings` (`app/Http/Controllers/User/SettingController.php:143-158`).
   Middleware chain: `auth:user` (`routes/web.php:143`) → `check.email.verification`, `check.active.organization` (`web.php:153`) → `check.organization` (`app/Http/Middleware/CheckOrganizationId.php:12`, requires `session('current_organization')`) + `org.agent.restrict` (`app/Http/Middleware/RestrictOrganizationAgent.php:86-107`: `settings/whatsapp` is NOT in the agent allowlist → agents get 403) (`web.php:158`) → `check.subscription` (`web.php:171`). The inner `Route::group([], …)` at `web.php:268` has no extra middleware (the `check.client.role` was removed, see comments at 266-267).
2. Controller reads global settings `is_embedded_signup_active`, `whatsapp_client_id`, `whatsapp_config_id` (`SettingController.php:144-145`), `CustomHelper::isModuleEnabled('Embedded Signup')` (`:148`; `app/Helpers/CustomHelper.php:35-75` = addon `status=1 && is_active=1` AND (trial subscription OR plan `metadata.addons[module] === true`)), `config('graph.api_version')` (`:149`; `config/graph.php:4` env `GRAPH_API_VERSION` default v20.0), the `Organization` row for `session('current_organization')` (`:152`), `Addon::get()` (`:153`).
3. Inertia props (`:157`) → `resources/js/Pages/User/Settings/Whatsapp.vue:266` `defineProps(['settings','embeddedSignupActive','appId','configId','graphAPIVersion','modules'])`. The `settings` prop is the full Organization model serialized through `Organization::toArray()` (`app/Models/Organization.php:24-47`) which json-decodes `metadata`, sets `whatsapp.access_token_is_set = filled(access_token)` and `unset(whatsapp.access_token)` (`:32-35`), same for `ai.api_key` (`:37-40`), re-encodes to string. Whatsapp.vue `:268-273` does `JSON.parse(props.settings.metadata)` into `settings` ref and branches on `settings?.whatsapp`.
4. Shared props via `app/Http/Middleware/HandleInertiaRequests.php:82-101`: `organization` = `{id, uuid, name, address, notifications, notification, plan.features{ice_breakers,shortcuts,agent_performance,activity_log,rating_delete}}` with `metadata` explicitly unset (`:100`); `auth.user.organization_team_role` (`:152`) drives `isOrgAgent` in `Settings/Layout.vue:101-102`; `settingsModuleWorkingHours` (`:163-169`).

### B. Manual connection (no Embedded Signup)
1. `Whatsapp.vue:20` "Setup whatsapp" button → `openModal()` → `<Modal>` at `:209-230` with fields `app_id`, `access_token`, `phone_number_id`, `waba_id` → `form.post('/settings/whatsapp', {preserveState:false})` (`:333-341`).
2. `routes/web.php:281` → `storeWhatsappSettings(StoreWhatsappSettings $request)` (`SettingController.php:160-176`). `app/Http/Requests/StoreWhatsappSettings.php:24-29` makes all 4 fields `required` (so the "blank token keeps existing" logic below is unreachable from this endpoint). `ActivityLogger::log(SETTINGS_UPDATED, …, ['section'=>'whatsapp'])` (`:167`). `$setWebhookUrl` is computed from `is_embedded_signup_active` (`:162-163`) and passed as 5th arg, but `saveWhatsappSettings`'s `$subscribeToWebhook` parameter (`:449`) is **never used** inside the method.
3. `saveWhatsappSettings($accessToken,$appId,$phoneNumberId,$wabaId)` (`:449-555`):
   - blank token → reuse `metadata.whatsapp.access_token` (`:455-459`).
   - `new WhatsappService($accessToken, $apiVersion, $appId, $phoneNumberId, $wabaId, $organizationId)` (`:461`; ctor `app/Services/WhatsappService.php:39-50`, which also calls `BroadcastProvider::apply()`).
   - 4 synchronous Graph calls, each returning `{success, data}`; on failure `back()->with('status', ['type'=>'error','message'=>$resp->data->error->message])`:
     `getPhoneNumberId()` (`:463`; `WhatsappService.php:2030-2060`, `GET /{waba_id}/phone_numbers?fields=display_phone_number,certificate,name_status,new_certificate,new_name_status,verified_name,quality_rating,messaging_limit_tier`, returns `data[0]` = FIRST number of the WABA), `getPhoneNumberStatus()` (`:475`; `2062-2091`, `GET /{phone_number_id}?fields=status,code_verification_status,quality_score,health_status` using the ctor's phone id), `getAccountReviewStatus()` (`:487`; `2093-2122`), `getBusinessProfile()` (`:499`; `1876-1905`). NOTE: the controller passes arguments to all four but the service signatures take **no parameters** — the args are silently ignored.
   - DB write (`:510-536`): `Organization::where('id')->first()`, `json_decode(metadata,true)`, set `metadataArray['whatsapp'][…]` keys listed in data_shapes, `json_encode`, `$organizationConfig->save()`. No transaction, no `lockForUpdate`, no `Cache::forget`.
   - On save success: `$whatsappService->syncTemplates()` (`:539`; `WhatsappService.php:1663`, pages `GET /{waba_id}/message_templates` into `templates` table) then flash success (`:541-546`).
   - Nothing is queued; everything is inline HTTP in the web request.

### C. Update token only
`Whatsapp.vue:88-91` "Update token" (only when `is_embedded_signup === 0`) → Modal `:232-249` → `form3.post('/settings/whatsapp/token')` (`:349-356`) → `routes/web.php:280` → `updateToken` (`SettingController.php:178-193`): `abortIfDemo()`, loads existing `app_id/phone_number_id/waba_id` from metadata and calls `saveWhatsappSettings` (full Graph re-validation + template sync).

### D. Refresh
`Whatsapp.vue:358-373` `router.visit('/settings/whatsapp/refresh', {method:'get'})` then re-visits `/settings/whatsapp` → `routes/web.php:279` → `refreshWhatsappData` (`:195-213`): if `$config['whatsapp']['is_embedded_signup'] == 1` (**no isset guard** at `:200` → PHP warning if not connected) → `Modules\EmbeddedSignup\Services\MetaService::overrideWabaCallbackUrl($organizationId)` (`modules/EmbeddedSignup/Services/MetaService.php:11-43`: `POST https://graph.facebook.com/v20.0/{waba_id}/subscribed_apps` with `override_callback_uri = URL::to('/').'/webhook/whatsapp/'.$org->identifier`, `verify_token = identifier`; **hardcoded v20.0**, ignores `config('graph.api_version')`), then `saveWhatsappSettings` with the stored credentials.

### E. Business profile update
`Whatsapp.vue:78-163` form2 (`profile_picture_url`, `address`, `email`, `description`, `industry`) → `form2.post('/settings/whatsapp/business-profile')` (`:343-347`) → `routes/web.php:282` → `whatsappBusinessProfileUpdate(StoreWhatsappProfile)` (`:347-390`; request rules `app/Http/Requests/StoreWhatsappProfile.php:24-40`: jpg/png ≥192x192) → `WhatsappService::updateBusinessProfile($request)` (`WhatsappService.php:1907-1970`: `POST /{phone_number_id}/whatsapp_business_profile` with `messaging_product:'whatsapp'`, stores picture on local/S3 per `settings.storage_system`, resumable upload handle; on success rewrites `metadata.whatsapp.business_profile.{about,address,description,industry,email,profile_picture_url}` `:1949-1963`).

### F. Delete integration
`Whatsapp.vue:375-385` `router.delete('/settings/whatsapp/business-profile')` (route is the same path as E with DELETE verb, `routes/web.php:283`) → `deleteWhatsappIntegration` (`:392-447`): `abortIfDemo`; if global `is_embedded_signup_active == 1` → `WhatsappService::unSubscribeToWaba()` (`:414`; `WhatsappService.php:1866-1874`, `DELETE /{waba_id}/subscribed_apps`); `unset($config['whatsapp'])` (`:419`), save (`:424`); then **soft-deletes every row of `templates` for the org** (`:427-431`). Contacts/chats untouched (UI text at `Whatsapp.vue:196`). No cache invalidation.

### G. Embedded Signup (Meta OAuth)
1. `resources/js/Components/EmbeddedSignupBtn.vue:15-35` loads `https://connect.facebook.net/en_US/sdk.js`, `FB.init({appId: props.appId /*= settings.whatsapp_client_id*/, version: props.graphAPIVersion})`. `launchWhatsAppSignup()` (`:59-101`) → `FB.login(cb, {config_id, response_type:'code', override_default_response_type:true, extras:{sessionInfoVersion:2, featureType:'whatsapp_business_app_onboarding' if coexistence, setup:{}}})`. On `authResponse` → `router.post('/whatsapp/exchange-code', {token: code, coexistence: 0|1})` (`:75-78`), then `router.visit('/settings/whatsapp')` (`:80-84`).
2. `modules/EmbeddedSignup/routes.php:5` `Route::match(['get','post'], '/whatsapp/exchange-code', RegisterController@handleSignup)` registered by `modules/EmbeddedSignup/Providers/RouteServiceProvider.php:12-15` under the `web` middleware group only — **not inside the `auth:user`/`check.organization` groups**; it relies on `session()->get('current_organization')` (`RegisterController.php:24`) and `auth()->user()->id` (`:636`) being present.
3. `RegisterController::handleSignup` (`modules/EmbeddedSignup/Controllers/RegisterController.php:23-202`): `getAccessToken($code)` (`:232-267`, `GET /oauth/access_token` with `whatsapp_client_id` + `whatsapp_client_secret` from `settings`) → `debugToken` (`:269-317`, uses global `whatsapp_access_token`, extracts `app_id` and first `waba_id` from `granular_scopes[scope=whatsapp_business_management].target_ids[0]`) → `getPhoneNumberId` (`:319-351`, `data[0]`) → `getPhoneNumberStatus` → `getAccountReviewStatus` → `registerNumber` with hardcoded `pin: "123456"` (skipped when coexistence, `:93-104`) → `getBusinessProfile` → `subscribeToWaba` (`:452-474`) → `overrideWabaCallbackUrl` (`:500-525`, callback `/webhook/whatsapp/{identifier}`, verify_token = `identifier`) → write `metadata.whatsapp` (`:158-185`, sets `is_embedded_signup=1`, `is_coexistence`) → `save` → `syncTemplates` (`:607-659`, `created_by = auth()->user()->id`) → `initiateContactsSync` for coexistence (`:208-230`, `POST /{phone_number_id}/smb_app_data {messaging_product:'whatsapp', sync_type:'smb_app_state_sync'}`).
4. Admin-side setup: `modules/EmbeddedSignup/routes.php:8-9` `POST/PUT /admin/addons/setup/embedded-signup` (`auth:admin`) → `SetupController::store` (`:22-58`): writes `settings.whatsapp_client_id/whatsapp_client_secret/whatsapp_config_id/whatsapp_access_token` (blank secret = keep, via `SettingService::SECRET_KEYS`), toggles `addons.is_active`, and creates `settings.whatsapp_callback_token` (`:44-50`). `SetupController::update` (`:60-104`) rewrites the addon's `metadata.input_fields` (form schema rendered by `resources/js/Pages/Admin/Addons/Index.vue` / `Components/Tables/AddonTable.vue`). **`is_embedded_signup_active` has no writer anywhere** (grep of `database/`, `app/`, `modules/`, `resources/`): it is a legacy key that is only truthy if inserted manually, yet `SettingController.php:162,397` still branch on it.

### H. Webhook-side coupling to these settings
`app/Http/Controllers/WebhookController.php:43-57` `/webhook/waba` verify handshake compares `hub_verify_token` to `settings.whatsapp_callback_token`; `handle($request,$identifier)` (`:59-68`) resolves `Organization::where('identifier', $identifier)` (`:70-73`) and rejects if `metadata` empty (`:80-83`). `app/Jobs/ProcessAccountUpdateJob.php:31-100` rewrites `metadata.whatsapp.account_review_status / verified_name / messaging_limit_tier / max_daily_conversation_per_phone / max_phone_numbers_per_business` from `account_update` webhooks, and on `PARTNER_REMOVED|ACCOUNT_OFFBOARDED` unsets `metadata.whatsapp` and soft-deletes templates (`:102-120`); it forgets `org_settings_{id}` and `org_config_{id}` caches (`:52-53,84-85`) — caches the settings controller never invalidates.

### I. Other consumers that construct `WhatsappService` from this metadata (copy-pasted loader)
`app/Services/ChatService.php:59-72`, `app/Http/Controllers/ApiController.php:1609-1621`, `app/Helpers/WhatsappConnectionHelper.php:17-48`, plus (by grep count of `['whatsapp']`) `TemplateService.php` (9), `modules/FlowBuilder/Services/FlowExecutionService.php` (8), `ActionExecutionService.php` (7), `Jobs/SendCampaignJob.php` (7), `Jobs/RetryCampaignLogJob.php` (6), `Jobs/ProcessSingleCampaignLogJob.php` (6), `WebhookController.php` (5), `modules/IntelliReply/Services/AIResponseService.php` (4), `AutoReplyService.php` (4), `Jobs/SendTextMessageJob.php` (4), `Jobs/SendMediaJob.php` (4), `Jobs/RetryMediaWithTranscodeJob.php` (4), `Jobs/ProcessMediaUploadJob.php` (4), `Jobs/ProcessMediaDownloadJob.php` (4), `Jobs/SyncIceBreakersToMeta.php` (3), `User/IceBreakerController.php` (2), `ConversationRatingService.php` (1), `User/DashboardController.php:62` (`setupWhatsapp = !isset($config['whatsapp'])`).

### J. Mobile settings API
`routes/api.php:71` `prefix('v1')`; group at `api.php:92` middleware `auth:sanctum, has.mobile.app, check.active.organization, check.has.selected.organization`; routes `api.php:168-174`: `GET/POST /api/v1/settings/general`, `GET/POST /api/v1/settings/working-hours`, `GET /api/v1/settings/placeholders` → `app/Http/Controllers/Api/MobileSettingController.php`. Organization comes from `$request->user()->current_mobile_organization_id` (`app/Http/Controllers/Api/Concerns/MobileApi.php:22-25`), not the session. `general()` (`:48-97`) returns organization/timezone/notifications/campaigns/support/auth_template/auth_template_missing/timezones/sounds/contact_groups/auth_templates. `updateGeneral()` (`:226-292`) is privileged-only, merges sections into metadata (`:267-282`) and never wipes unknown keys. **No endpoint returns WhatsApp connection status or the list of connected channels.**

### K. Other writers of the same `organizations.metadata` blob (all read-modify-write, no lock)
`SettingController::contacts()` `:231-238`, `tickets()` `:269-296` (+`Cache::forget("org_settings_{id}")` `:300`), `automation()` `:323-328`, `workingHours()` `:615-621`; `app/Http/Controllers/ProfileController.php:148-212` `updateOrganization` (General.vue's save target, `General.vue:537` `form2.put('./profile/organization')`); `MobileSettingController::updateGeneral/updateWorkingHours`; `WhatsappService::updateBusinessProfile`; `ProcessAccountUpdateJob`; `RegisterController::handleSignup`.

## whatsapp_assumptions

- `app/Models/Organization.php:32-35` — redaction only knows `metadata.whatsapp.access_token` (and `ai.api_key`). Any new channel secret (Telegram bot token, Messenger/Instagram page access token, TikTok access/refresh token) stored under another key would be sent to the browser in the `settings` prop unredacted.
- `app/Models/Organization.php:24-47` — `toArray()` re-encodes metadata as a JSON string; Vue pages `JSON.parse` it (`Whatsapp.vue:273`, `General.vue:428`). New channel keys must survive this round-trip and be redacted the same way.
- `app/Http/Controllers/User/SettingController.php:143-158` — page is WhatsApp-only: props `appId/configId/graphAPIVersion` are Meta Embedded Signup values; `embeddedSignupActive` keyed to addon name `'Embedded Signup'`.
- `SettingController.php:144,162,397` and `DashboardController.php:50` — global `settings` keys `is_embedded_signup_active`, `whatsapp_client_id`, `whatsapp_config_id` are WhatsApp-specific; no analogous keys exist for other platforms (e.g. Messenger/Instagram need a Facebook App ID + secret + Page tokens; Telegram needs only the bot token; TikTok needs client key/secret + OAuth).
- `SettingController.php:160-176` + `app/Http/Requests/StoreWhatsappSettings.php:24-29` — form fields `access_token, app_id, phone_number_id, waba_id` all required: pure Meta WABA shape.
- `SettingController.php:449-508` — "connect" means calling Graph `GET /{waba_id}/phone_numbers`, `GET /{phone_number_id}`, `GET /{waba_id}?fields=account_review_status`, `GET /{phone_number_id}/whatsapp_business_profile`; failure messages read `->data->error->message` (Meta error envelope). Other platforms validate differently (Telegram `getMe`, Messenger `GET /me?fields=id,name` + `/me/subscribed_apps`, Instagram `GET /me?fields=id,username`, TikTok token introspection).
- `SettingController.php:463` + `WhatsappService.php:2030-2060` — the persisted `phone_number_id` is `phone_numbers.data[0].id`, i.e. the first number of the WABA, not necessarily the one the user typed; `getPhoneNumberStatus` then queries the ctor's `phoneNumberId` (user-typed). Identity of a WhatsApp connection == (waba_id, phone_number_id).
- `SettingController.php:513-533` — hardcoded metadata keys `is_embedded_signup, access_token, app_id, waba_id, phone_number_id, display_phone_number, verified_name, quality_rating, name_status, messaging_limit_tier, max_daily_conversation_per_phone, max_phone_numbers_per_business, number_status, code_verification_status, business_verification, account_review_status, business_profile{about,address,description,industry,email}` — all Meta phone-number/WABA concepts; `messaging_limit_tier`/`quality_rating` are the WhatsApp tiering model, not applicable elsewhere.
- `SettingController.php:539` and `RegisterController.php:188,607-659` — connecting triggers `syncTemplates()` (WhatsApp message templates). Non-WhatsApp channels have no templates; campaign UI (`General.vue:131-140` "Auth Template", `MobileSettingController.php:81-95,123-136`) assumes APPROVED Meta templates.
- `SettingController.php:392-447` — delete integration = `unset(metadata.whatsapp)` + `unSubscribeToWaba` + soft-delete **all** `templates` of the org (`:427-431`). A per-channel delete must not touch templates or other channels.
- `SettingController.php:195-213` — refresh assumes `is_embedded_signup` exists (`:200`, no isset → warning when not connected) and re-runs Meta callback override.
- `SettingController.php:347-390` + `WhatsappService.php:1907-1970` — business profile update posts `messaging_product:'whatsapp'`, `vertical` (industry enum list hardcoded in `Whatsapp.vue:294-314` with Meta values `AUTO, BEAUTY, APPAREL, …`), 192x192 jpg/png picture rule (`StoreWhatsappProfile.php:33-38`).
- `SettingController.php:629-642` — `abortIfDemo` only on org id 1 in `demo` env; applied to updateToken/profile/delete but NOT to `storeWhatsappSettings` or `refreshWhatsappData`.
- `app/Helpers/WhatsappConnectionHelper.php:26-46` — "connected" == `metadata.whatsapp` is a non-empty array with `access_token, phone_number_id, waba_id`; error strings hardcode "Settings → WhatsApp".
- `app/Http/Controllers/User/DashboardController.php:62,66-68` — `setupWhatsapp = !isset($config['whatsapp'])`; dashboard banner (`resources/js/Pages/User/Dashboard.vue:130-145`) only knows WhatsApp / Embedded Signup.
- `resources/js/Components/CampaignForm.vue:462-480` and `resources/js/Pages/User/Templates/Add.vue:67-95` — hard gate `v-if="!settings?.whatsapp"` → "Connect your whatsapp account" with link `/settings/whatsapp`. Campaigns for other channels are blocked by this gate.
- `resources/js/Pages/User/Settings/Whatsapp.vue:6-26` — empty state hardcodes "Setup Whatsapp Account"; `:15-19` Embedded Signup buttons (create new / coexistence); `:28-76` status grid reads `verified_name, display_phone_number, messaging_limit_tier, number_status, waba_id, code_verification_status, quality_rating, account_review_status`; `:165-190` "Meta webhook settings" shows `currentURL + '/webhook/whatsapp/' + settings.identifier` and verify token = `identifier`; `:213-216` modal fields; `:276,292` initial `access_token` values come from redacted metadata (always undefined → placeholder "Leave blank to keep current value").
- `resources/js/Pages/User/Settings/Layout.vue:30-35` and `Main.vue:23-31` — nav item literally "Whatsapp settings" (`$t('Whatsapp settings')`, `lang/en.json:1130`, `lang/ar.json:1683`) with WhatsApp icon; active-state test `$page.url.startsWith('/settings/whatsapp')`.
- `resources/js/Components/EmbeddedSignupBtn.vue:16-23,71-100` — FB JS SDK, `WA_EMBEDDED_SIGNUP` message type, `phone_number_id/waba_id` in session info, `featureType: 'whatsapp_business_app_onboarding'`. Messenger/Instagram could reuse FB Login but need different scopes (`pages_messaging`, `instagram_manage_messages`) and a different exchange endpoint.
- `modules/EmbeddedSignup/Controllers/RegisterController.php:119-120,130` and `modules/EmbeddedSignup/Services/MetaService.php:19-20` — webhook callback URL is `/webhook/whatsapp/{identifier}`, verify token = `organizations.identifier`. Meta apps allow one callback per app for Messenger/Instagram (app-level, not per-WABA), Telegram uses `setWebhook` per bot, TikTok registers per app — so per-org routing via `identifier` in the path must be redesigned per channel.
- `RegisterController.php:213-215,225,587-588` — `messaging_product: 'whatsapp'`, `/register` with PIN `123456`, `smb_app_state_sync`: WhatsApp-only.
- `RegisterController.php:296` — `granular_scopes` lookup hardcodes scope `whatsapp_business_management`.
- `RegisterController.php:629,640` — `syncTemplates` uses `session()->get('current_organization')` and `auth()->user()->id`, i.e. web-session only.
- `modules/EmbeddedSignup/Controllers/SetupController.php:44-49` and `WebhookController.php:46` — single global `whatsapp_callback_token` for the app-level `/webhook/waba` handshake.
- `app/Services/SettingService.php:21-34` — `SECRET_KEYS` lists only `whatsapp_*` and payment secrets; new platform-level secrets (`facebook_app_secret`, `tiktok_client_secret`, …) must be added or they will leak to the admin UI via `redactForClient`.
- `app/Jobs/ProcessAccountUpdateJob.php:60-76,104` — webhook `account_update` fields (`account_review_update`, `phone_number_name_update`, `phone_number_quality_update`, `business_capability_update`, `PARTNER_REMOVED`, `ACCOUNT_OFFBOARDED`) mutate `metadata.whatsapp` and can **delete** the whole WhatsApp connection + templates.
- `app/Http/Controllers/Api/MobileSettingController.php:81-95,123-136,164-197` — mobile general settings expose `auth_template` and `auth_templates` (APPROVED WhatsApp templates) — WhatsApp-only concept in a channel-neutral endpoint; nothing tells the app which channels are connected.
- `config/graph.php:4` — single Graph API version for WhatsApp; `MetaService.php:27` hardcodes `v20.0` regardless. Messenger/Instagram share Graph but Telegram/TikTok have separate base URLs/versions.
- `app/Services/WhatsappService.php:39-50` — ctor signature `(accessToken, apiVersion, appId, phoneNumberId, wabaId, organizationId)` is duplicated by every caller (`ChatService.php:59-72`, `ApiController.php:1609-1621`, `SettingController.php:363,413,461`); there is no factory/driver resolution by channel.
- `lang/en.json:630,1130` / `lang/ar.json:1683-1684` — flash messages "Whatsapp settings updated successfully", "Your whatsapp business profile has been changed successfully!", "Setup your whatsapp integration first!", "Your integration has been removed successfully!" are WhatsApp-named.

## required_changes

### Already channel-agnostic (reuse as-is)
- `organizations.metadata` is a JSON blob; adding sibling namespaces (`telegram`, `messenger`, `instagram`, `tiktok`) is purely additive and needs **no migration**.
- `Settings/Layout.vue` nav + content column, the card/row design system (see data_shapes → UI), `Modal.vue`, `FormInput/FormSelect/FormTextArea/FormToggleSwitch/FormImageLogo`, Inertia `back()->with('status', {type,message})` flash convention, `ActivityLogger::log(SETTINGS_UPDATED, …, ['section'=>…])`, `abortIfDemo()`, `MobileApi::ok/fail/isPrivileged`, the merge-not-replace pattern in `MobileSettingController::updateGeneral` (`:267-282`).
- The tab pattern already exists in `resources/js/Pages/User/Settings/Conversations.vue:18-35` (`flex border-b px-4` + buttons `px-4 py-3 text-sm border-b-2 -mb-px`, active `border-primary text-primary font-medium`, inactive `border-transparent text-slate-500 hover:text-slate-700`, `activeTab = ref('…')`). Reuse it for the platform tabs.

### 1. Credential storage & redaction (additive)
- Keep `metadata.whatsapp` byte-for-byte unchanged. Add `metadata.telegram`, `metadata.messenger`, `metadata.instagram`, `metadata.tiktok` objects (proposed shapes in data_shapes). Do not introduce a `metadata.channels` wrapper that would require moving `whatsapp` — 20+ readers depend on `metadata['whatsapp']`.
- `app/Models/Organization.php:24-47` `toArray()`: generalize redaction with a constant map `SECRET_METADATA_FIELDS = ['whatsapp'=>['access_token'], 'ai'=>['api_key'], 'telegram'=>['bot_token','webhook_secret'], 'messenger'=>['page_access_token','app_secret'], 'instagram'=>['page_access_token'], 'tiktok'=>['access_token','refresh_token']]` producing `<field>_is_set` booleans, iterating the map. Existing keys `whatsapp.access_token_is_set` and `ai.api_key_is_set` must remain.
- Add a small `App\Services\Channels\ChannelCredentials` (or `OrganizationChannelConfig`) helper: `forOrganization($orgId)->get('telegram')`, `isConnected('whatsapp')`, `connectedChannels()`, `put('telegram', array)`, `forget('telegram')`; internally does the read-modify-write on `organizations.metadata` **inside `DB::transaction` with `lockForUpdate()`** so concurrent writers (webhook jobs, mobile, profile) cannot clobber each other. `WhatsappConnectionHelper::errorFor()` (`app/Helpers/WhatsappConnectionHelper.php`) can become a thin wrapper over `ChannelConnectionHelper::errorFor($orgId, 'whatsapp')`; keep the class/name for callers.
- Optional (not required for the feature): Laravel `encrypted` casting is not possible on a nested JSON key without a cast class; if encryption-at-rest is wanted, add a `Casts\EncryptedMetadataSecrets` attribute cast that encrypts only the mapped secret fields on set and decrypts on get — but this changes a production column's content and needs a one-off backfill command; treat as a separate follow-up, not part of this rollout.

### 2. Channel driver abstraction (new code, no WhatsApp changes)
- `app/Contracts/Channels/ChannelDriver` (or `ChannelConnector`) interface with: `key(): string` (`whatsapp|telegram|messenger|instagram|tiktok`), `label()`, `icon()`, `settingsSchema(): array` (fields for the settings tab: name, type, required, secret), `validateAndDescribe(array $input, Organization $org): ConnectionResult` (calls the platform API, returns the normalized status object to persist), `registerWebhook(Organization $org): void` (Telegram `setWebhook`, Messenger/Instagram `POST /{page_id}/subscribed_apps`, TikTok app-level), `disconnect(Organization $org): void`, `messagingWindowSeconds(): ?int` (WhatsApp 24h, Messenger/Instagram 24h standard messaging, Telegram none, TikTok per docs) — the window is consumed by chats/campaigns subsystems.
- `app/Services/Channels/ChannelRegistry` resolving drivers by key from `config/channels.php` (new config file listing drivers, base URLs, API versions, platform-level env keys e.g. `FACEBOOK_APP_ID/SECRET`, `TIKTOK_CLIENT_KEY/SECRET`). Keep `config/graph.php` untouched for WhatsApp.
- Wrap the existing WhatsApp behaviour in `WhatsappChannelDriver` that delegates to the current `saveWhatsappSettings` logic (moved into `app/Services/Channels/WhatsappConnectionService` or left in the controller and called by the driver) — so no behavioural change for WhatsApp.

### 3. SettingController / routes (additive)
- Keep every existing route in `routes/web.php:273,279-283` and every existing method in `SettingController` untouched so the production WhatsApp flow is bit-identical.
- Add generic routes inside the same group: `GET /settings/channels` (or keep `/settings/whatsapp` as the tabbed page URL and add `GET /settings/channels/{channel}` that renders the same page with `activeChannel`), `POST /settings/channels/{channel}` (connect/update), `POST /settings/channels/{channel}/refresh`, `DELETE /settings/channels/{channel}`. Implement in a new `App\Http\Controllers\User\ChannelSettingController` using `ChannelRegistry`. Whitelist `{channel}` via `->whereIn('channel', [...])`.
- `viewWhatsappSettings` (`:143-158`) should additionally pass `channels` (see data_shapes → Inertia props) so the tabbed page can render every platform's status without a second request. Simplest: add the extra props to the existing method and rename the Vue page to `User/Settings/Channels.vue` while keeping `Whatsapp.vue` as the WhatsApp tab component (`Settings/Channels/WhatsappTab.vue`). Layout nav label `Whatsapp settings` → `Messaging channels` (add new lang keys; keep old keys).
- Per-channel FormRequests (`StoreTelegramSettings`: `bot_token` required-unless-set; `StoreMessengerSettings`/`StoreInstagramSettings`: `page_id`, `page_access_token` (or OAuth code); `StoreTiktokSettings`: OAuth code / `access_token`), following the `StoreEmbeddedSettings::secretRule` "required only when nothing stored" pattern (`modules/EmbeddedSignup/Requests/StoreEmbeddedSettings.php:14-19`).
- `deleteWhatsappIntegration` (`:392-447`) must stay WhatsApp-scoped (it already only unsets `whatsapp`), but the template soft-delete must not run for other channels — the generic delete route handles that per driver.
- Fix while there: guard `$config['whatsapp']['is_embedded_signup']` with `isset` at `:200`; it warns today when not connected.

### 4. Webhook registration per channel
- Telegram: driver calls `https://api.telegram.org/bot{token}/setWebhook?url={APP_URL}/webhook/telegram/{identifier}&secret_token={random}` — store `webhook_secret` in `metadata.telegram`; verify header `X-Telegram-Bot-Api-Secret-Token` in the webhook controller (other subsystem).
- Messenger/Instagram: one app-level callback `/webhook/meta` (verify token = new global setting `meta_callback_token`, mirroring `whatsapp_callback_token` in `SetupController.php:44-50`); route per-org by `entry[].id` (page id / IG account id) → new columns are NOT needed: store `page_id`/`ig_account_id` in metadata and index via a small new table `organization_channels(organization_id, channel, external_id UNIQUE(channel, external_id), created_at)` so the webhook can resolve the org with one indexed query instead of scanning JSON. Populate it from the driver on connect/disconnect. This is the only recommended new table for this subsystem.
- TikTok: app-level webhook; same `organization_channels` lookup.
- Admin addon settings: add platform-level keys to `settings` table via a new addon/setup controller (mirroring `SetupController`), and append their secret names to `SettingService::SECRET_KEYS` (`SettingService.php:21-34`).

### 5. Mobile API (new, documented for the mobile developer)
- `GET /api/v1/settings/channels` → `{ statusCode, success, message, data: { channels: [ {key:'whatsapp', label, connected:true, display_name, display_identifier:'+9665…', status:'CONNECTED'}, {key:'telegram', connected:false, …}, … ] } }` (see data_shapes). Read-only, agents allowed (like `general`).
- Do **not** change the shape of `GET /api/v1/settings/general` (`MobileSettingController.php:59-96`); tests pin it. If desired add a non-breaking `connected_channels: ['whatsapp','telegram']` array to that payload.

### 6. Vue
- `Settings/Whatsapp.vue` → becomes one tab; new `Settings/Channels.vue` container with tabs `WhatsApp | Telegram | Messenger | Instagram | TikTok`, each tab a component receiving `channel` status object + `schema`; connection card reuses the `grid grid-cols-4 … border-r` status grid (`Whatsapp.vue:33-76`) with channel-specific fields; "Remove account" card pattern (`:191-204`).
- Update gates: `CampaignForm.vue:462`, `Templates/Add.vue:67`, `Dashboard.vue:130` should check "any channel connected" (campaigns) or WhatsApp specifically (templates) — coordinate with campaigns subsystem.
- Add i18n keys to `lang/en.json` and `lang/ar.json` (flat JSON keyed by English string).

## risks

- **Lost-update on `organizations.metadata`** (highest data-loss risk in this subsystem): every writer does `first()` → `json_decode` → mutate → `json_encode` → `save()` with no lock: `SettingController.php:510-536, 399-424, 231-238, 269-296, 323-328, 615-621`, `ProfileController.php:151-192`, `MobileSettingController.php:239-287, 446-452`, `WhatsappService.php:1949-1963`, `ProcessAccountUpdateJob.php:39-81`, `RegisterController.php:158-185`. A Telegram connect running concurrently with a WhatsApp `account_update` webhook job, a mobile settings save, or a business-profile update can silently drop the other's write — including erasing `metadata.whatsapp` entirely (the job at `ProcessAccountUpdateJob.php:104-109` writes the whole blob). Adding four more writers multiplies the window. Mitigation: central helper with `DB::transaction` + `lockForUpdate()` (or MySQL `JSON_SET` partial updates) and adopt it in the new code; leave WhatsApp paths as-is for this release but plan migration.
- **Redaction leak**: if a new secret is written under a new metadata key before `Organization::toArray()` is extended, it goes to every Inertia page that passes `settings`/`Organization` (`SettingController::index/viewGeneralSettings/contacts/tickets/automation`, campaigns, templates). Extend redaction in the same commit that introduces the key.
- **Caches**: `org_settings_{id}` and `org_config_{id}` (`ProcessAccountUpdateJob.php:52-53,84-85`, `ProcessTicketAssignmentJob.php:93,273`, `SettingController.php:300`) cache parts of metadata for up to 30 min; the WhatsApp settings controller never forgets them. New channel connect/disconnect must `Cache::forget` both or ticket assignment/config readers will see stale channel state.
- **Delete semantics**: `deleteWhatsappIntegration` soft-deletes all templates (`:427-431`); `ProcessAccountUpdateJob::teardownIntegration` does the same. A generic "disconnect channel" that reuses this code would wipe WhatsApp templates when disconnecting Telegram. Keep template teardown strictly inside the WhatsApp driver.
- **`is_embedded_signup_active` has no writer**: branches at `SettingController.php:162-163, 397-415` are effectively always false in production unless the key was inserted by hand — so `unSubscribeToWaba` on delete may never run. Do not "fix" this as part of the multi-channel work without checking production `settings` rows; changing it alters live WhatsApp behaviour.
- **`/whatsapp/exchange-code` is outside the auth group** (`modules/EmbeddedSignup/routes.php:5`, `web` middleware only) and trusts `session('current_organization')` and `auth()->user()`; a new Messenger/Instagram/TikTok OAuth callback copied from it would inherit the same gap. Place new OAuth callbacks inside the `auth:user` + `check.organization` group, and for redirect-style OAuth (TikTok, FB Login redirect) use a signed `state` carrying org id.
- **Organization `identifier` doubles as webhook verify token** (`RegisterController.php:120,131`, `MetaService.php:20`, `Whatsapp.vue:187`) and is displayed in the UI. Reusing it as Telegram `secret_token` would expose it in the page; generate a separate secret.
- **Graph API version drift**: `MetaService.php:27` hardcodes `v20.0`; Messenger/Instagram drivers should use `config('graph.api_version')` (or a new `config/channels.php`) consistently.
- **Web-session assumptions in EmbeddedSignup** (`RegisterController.php:24,629,640`): any queue/job re-use of these methods will see empty session and null user → templates saved with `created_by` null / wrong org.
- **Inertia prop growth**: `settings` prop already ships the full Organization row; adding `channels` status is fine, but never add raw metadata of other channels to shared props (`HandleInertiaRequests.php:82-101` intentionally unsets metadata).
- **Agent access**: `RestrictOrganizationAgent::isAllowedForAgent` (`:86-107`) allow-lists by path prefix; `settings/channels/*` must be intentionally excluded (default) so agents cannot read tokens; if you keep `/settings/whatsapp` as the tabbed page URL nothing changes.
- **Ordering/queues**: settings writes are synchronous; `ProcessAccountUpdateJob` (`tries=2`, `timeout=30`) runs on the default queue and rewrites the blob later — a channel connected between the webhook arrival and job execution can be clobbered (see lost-update). Consider `ShouldBeUnique` keyed by org or moving to `JSON_SET`.
- **Mobile contract**: `tests/Feature/MobileApi/MobileSettingTest.php:138-154` asserts `metadata.whatsapp.access_token` survives a mobile settings save — the same guarantee must hold for `metadata.telegram|messenger|instagram|tiktok` (mobile `updateGeneral` merges, so it will, unless a new endpoint writes the blob wholesale).

## tests

### Existing tests touching this subsystem
- `tests/Feature/MobileApi/MobileSettingTest.php` (HTTP, uses `/api/v1/settings/general` and `/working-hours`): asserts exact `data.*` keys (`timezone`, `notifications.enable_sound/tone`, `campaigns.resend_intervals`, `support.ticket_form_url`, `organization.id/address`), that dropdown lists are `{value,label}` objects, tone is one of `/sounds/…`, updates persist to `organizations.metadata`, **`test_it_never_wipes_settings_it_was_not_given` (`:138-154`) asserts `metadata['whatsapp']['access_token']` and `working_hours` and `notifications` survive a partial save**, sections merge not replace (`:158-169`), invalid URL → 400, agent → 403 on write / 200 on read, working-hours validation (end before start, invalid day/time, empty slots clear).
- `tests/Feature/MobileApi/MobileAuthTemplateSettingTest.php` (`:81-492`): `contact_groups`/`auth_templates` lists, only APPROVED templates, org isolation, soft-deleted hidden, `auth_template` object with `components`, parameter/template UUID matching, agent forbidden, `test_saving_the_template_does_not_wipe_other_settings` (`:467`).
- `tests/Feature/MobileApi/DeletedTemplateTest.php` and `tests/Feature/ContactGroupPlaceholderTest.php` reference `settings/general` / placeholders (mobile).
- `tests/Feature/SettingsPageOverflowTest.php` (`:24-76`): static-source assertions on `resources/js/Pages/User/Settings/WorkingHours.vue` (`md:min-h-[90vh]`, `flex justify-center items-start`) and on `Settings/Layout.vue` containing `md:h-screen md:flex md:flex-col`, `md:flex-1 md:min-h-0`, `md:overflow-y-auto` (`:59-66`). Any Layout.vue refactor must keep these class strings.
- `tests/Feature/DeviceCoexistenceTest.php` is about device sessions, not WhatsApp coexistence.
- `tests/js/*.mjs`: none cover `Whatsapp.vue`, `Layout.vue`, or `EmbeddedSignupBtn.vue`.

### Gaps (no coverage today)
- No test for `SettingController::viewWhatsappSettings`, `storeWhatsappSettings`, `updateToken`, `refreshWhatsappData`, `whatsappBusinessProfileUpdate`, `deleteWhatsappIntegration` (would need `Http::fake()` for `graph.facebook.com`).
- No test that `Organization::toArray()` redacts `whatsapp.access_token` / adds `access_token_is_set` (add one before extending redaction to new channels).
- No test for `WhatsappConnectionHelper::errorFor()` states (missing org, missing whatsapp, missing keys).
- No test for `RegisterController::handleSignup`, `SetupController::store` (secret-keep semantics), `MetaService::overrideWabaCallbackUrl`.
- No test that `deleteWhatsappIntegration` leaves contacts/chats intact and only soft-deletes templates.
- No test asserting agents get 403 on `/settings/whatsapp` (RestrictOrganizationAgent path list).
- No concurrency test for metadata lost-update.

### Tests the multi-channel work should add
- Redaction map test for every channel secret; connect/disconnect per driver with `Http::fake()`; "connecting Telegram does not alter `metadata.whatsapp`" and vice-versa; disconnecting a non-WhatsApp channel does not soft-delete templates; `GET /api/v1/settings/channels` shape; `GET /api/v1/settings/general` shape unchanged (snapshot of keys); `organization_channels` uniqueness per `(channel, external_id)`; cache keys forgotten on connect/disconnect; agents forbidden on channel settings routes; Layout.vue class strings preserved.

## data_shapes

### 1. `organizations.metadata` (MySQL `json`, read/written as string) — current top-level keys seen in this subsystem
```
{
  "whatsapp": {
    "is_embedded_signup": 0|1,             // SettingController:513 / RegisterController:159
    "is_coexistence": 0|1,                 // RegisterController:160 only (absent for manual connections)
    "access_token": "EAAG…",               // PLAINTEXT; redacted to access_token_is_set in toArray()
    "app_id": "123…",
    "waba_id": "123…",
    "phone_number_id": "123…",             // = phone_numbers.data[0].id
    "display_phone_number": "+966 5…",
    "verified_name": "Business",
    "quality_rating": "GREEN"|"YELLOW"|"RED"|"UNKNOWN"|null,
    "name_status": "APPROVED"|…,
    "messaging_limit_tier": "TIER_1K"|…|null,
    "max_daily_conversation_per_phone": null|int,   // set by ProcessAccountUpdateJob
    "max_phone_numbers_per_business": null|int,
    "number_status": "CONNECTED"|…,
    "code_verification_status": "VERIFIED"|…|null,
    "business_verification": "",
    "account_review_status": "APPROVED"|"PENDING"|…,
    "business_profile": { "about": null|str, "address": null|str, "description": null|str,
                          "industry": null|"AUTO"|"BEAUTY"|…, "email": null|str,
                          "profile_picture_url": str (only after updateBusinessProfile) }
  },
  "ai": { "api_key": "…", … },               // redacted to api_key_is_set
  "timezone": "Asia/Riyadh",
  "notifications": { "enable_sound": bool, "tone": "/sounds/…", "volume": 0..1 },
  "notification": …,
  "campaigns": { "enable_resend": bool, "resend_intervals": [int], "move_failed_contacts_to_group": bool, "failed_campaign_group": uuid|null },
  "support": { "ticket_form_url": str|null },
  "auth_template": uuid|null, "auth_template_parameters": { "template": uuid, … }|null,
  "contacts": { "location": … },
  "tickets": { "active": bool, "assignment_mode": "manual|auto|shared|round_robin", "auto_assignment": bool,
               "reassign_reopened_chats": bool, "allow_agents_to_view_all_chats": bool, "encrypt_contacts_for_agents": bool },
  "ratings": { "active": bool, "message": str, "button_label": str, "cooldown_days": int },
  "automation": { "response_sequence": … },
  "working_hours": [ { "day": 0-6, "open": "HH:MM", "close": "HH:MM" } ],
  "working_hours_outside_message": str
}
```
Proposed additive namespaces (new channels must mirror the `*_is_set` redaction):
```
"telegram":  { "bot_token", "bot_id", "bot_username", "display_name", "webhook_secret", "webhook_url", "status": "CONNECTED|ERROR", "connected_at": ISO8601, "last_error": null|str }
"messenger": { "page_id", "page_name", "page_access_token", "app_id", "status", "connected_at", "last_error" }
"instagram": { "ig_account_id", "username", "page_id", "page_access_token", "status", "connected_at", "last_error" }
"tiktok":    { "business_id", "display_name", "access_token", "refresh_token", "expires_at", "status", "connected_at", "last_error" }
```

### 2. `Organization::toArray()` output as the `settings` Inertia prop (`SettingController.php:152`)
All columns of `organizations` (`id, uuid, identifier, name, address(json string), metadata(json string), timezone, created_by, is_active, waz_company_id, deleted_at, created_at, updated_at`) with `metadata` re-encoded after removing `whatsapp.access_token` and adding `whatsapp.access_token_is_set: bool` (same for `ai.api_key`).

### 3. Inertia props of `User/Settings/Whatsapp` (`SettingController.php:147-155`)
```
{ embeddedSignupActive: bool, graphAPIVersion: "v20.0", appId: str|null /* settings.whatsapp_client_id */,
  configId: str|null /* settings.whatsapp_config_id */, settings: <Organization toArray>,
  modules: Addon[] /* {id, uuid, category, name, logo, description, metadata(json str), status, is_active, is_plan_restricted, license, created_at, updated_at} */,
  title: "Settings" }
```
Shared (`HandleInertiaRequests.php:171-207`): `csrf_token, config[{key,value}], broadcast, max_upload_bytes, max_post_bytes, auth.user{id,first_name,last_name,full_name,email,phone,language,verification_enabled,is_verified,role,organization_team_role,teams[]}, organization{id,uuid,name,address,notifications,notification,plan.features{…}}, organizations[Team with organization{id,uuid,name}], flash.status{type,message}, languages, unreadMessages, currentLanguage, tfa, isRtl, settingsModuleWorkingHours`.
Proposed extra prop for the tabbed page: `channels: [ { key:'whatsapp', label, connected:bool, status:{…public non-secret fields…}, secrets:{ access_token_is_set:bool }, schema:[{name,type,label,required,secret}], capabilities:{ templates:true, campaigns:true, window_hours:24 } }, … ]`.

### 4. Form payloads (web)
- `POST /settings/whatsapp` (`StoreWhatsappSettings`): `{ access_token, app_id, phone_number_id, waba_id }` all required.
- `POST /settings/whatsapp/token`: `{ access_token }` (blank ⇒ keep).
- `POST /settings/whatsapp/business-profile` (`StoreWhatsappProfile`, multipart): `{ profile_picture_url?: File(jpg|png ≥192x192), address, email, description, industry, websites: [] }`.
- `DELETE /settings/whatsapp/business-profile`: no body.
- `POST /whatsapp/exchange-code`: `{ token: <FB auth code>, coexistence: 0|1 }`.
- `POST /admin/addons/setup/embedded-signup` (`StoreEmbeddedSettings`): `{ uuid, is_active?, settings: { whatsapp_client_id, whatsapp_client_secret?, whatsapp_config_id, whatsapp_access_token? } }`.
- Flash on every action: `session('status') = { type: 'success'|'error'|'warning', message: str }` → shared prop `flash.status`.

### 5. Global `settings` table rows consumed (key → value text)
`whatsapp_client_id` (Meta App ID used as FB SDK appId), `whatsapp_client_secret`, `whatsapp_config_id` (Embedded Signup config), `whatsapp_access_token` (system-user token for `debug_token`), `whatsapp_callback_token` (verify token for `/webhook/waba`), `is_embedded_signup_active` (legacy, never written), `storage_system` (`local|aws`, used by profile picture upload), `google_maps_api_key`, `pusher_*`. `SettingService::redactForClient()` output shape: `[{key, value}]` with secret keys blanked and `<key>_is_set: bool` companions.

### 6. `WhatsappService` / `RegisterController` response envelope (every Graph call)
```
success: bool
data:    object   // on success: decoded Graph JSON (e.g. phone_numbers.data[0] object; or {status, code_verification_status, …}; or {account_review_status}; or business_profile.data[0])
         // on failure: { error: { code?: int, message: string, error_user_msg?: string } }
message: string   // only set by sendHttpRequest() on GuzzleException (WhatsappService.php:2357-2361)
```
Controllers surface failures as `$response->data->error->message`. Channel drivers should return the same envelope (or a typed `ConnectionResult` mapped to it) so existing error handling in controllers keeps working.

### 7. Mobile API envelope (`MobileApi.php:40-63`)
```
{ "statusCode": 200, "success": true, "message": null|str, "data": … }
{ "statusCode": 4xx, "success": false, "message": str, "errors"?: {field: [msg]} }
```
`GET /api/v1/settings/general` data keys (unchanged, pinned by tests): `organization{id,uuid,name,address{street,city,state,zip,country,latitude?,longitude?}}, timezone, notifications{enable_sound,tone,volume}, campaigns{enable_resend,resend_intervals[],move_failed_contacts_to_group,failed_campaign_group}, support{ticket_form_url}, auth_template{uuid,name,language,status,components[],parameters}|null, auth_template_missing, timezones[{value,label}], sounds[{value,label}], contact_groups[{uuid,name}], auth_templates[{uuid,name,language}]`.
Proposed `GET /api/v1/settings/channels` data: `{ channels: [ { key:'whatsapp'|'telegram'|'messenger'|'instagram'|'tiktok', label: str, icon: str, connected: bool, display_name: str|null, display_identifier: str|null /* phone, @bot, page name, @ig, business name */, status: 'CONNECTED'|'DISCONNECTED'|'ERROR', supports_templates: bool, supports_campaigns: bool, messaging_window_hours: int|null } ] }`.

### 8. Webhook callback registration values
`callback_url = URL::to('/') . '/webhook/whatsapp/' . organizations.identifier`, `verify_token = organizations.identifier` (per-WABA override via `POST /{waba_id}/subscribed_apps {override_callback_uri, verify_token}`); app-level `GET /webhook/waba` expects `hub_mode=subscribe`, `hub_verify_token = settings.whatsapp_callback_token`, echoes `hub_challenge`.

### 9. UI design system used by the settings pages (for the tabbed page)
Container: `<SettingLayout :modules>` → `<div class="md:h-[90vh]"><div class="flex justify-center items-center mb-8"><div class="md:w-[60em]">…` (Whatsapp.vue:3-5; Conversations.vue uses `items-start` + `w-full max-w-[60em]`, and WorkingHours uses `md:min-h-[90vh]` — prefer the latter two per SettingsPageOverflowTest). Card: `bg-white border border-slate-200 rounded-lg py-2 text-sm mb-4`. Card header: `px-4 pt-2 pb-4` with `<h2 class="text-[17px]">` + `<span class="flex items-center mt-1">` (info svg 15x15). Field row: `flex space-x-10 border-b w-full px-4 py-6` → label col `w-[40%]` (`text-slate-600` + hint `text-xs text-slate-700 flex items-center`) / control col `w-[60%]`. Status grid: `grid grid-cols-4 items-center px-4 gap-x-4 py-2 border-b relative` with `border-r` cells and value chips `bg-slate-50 py-1 px-2 rounded-md w-[fit-content] text-xs`; refresh button `absolute right-0 top-0 text-xs … bg-slate-50 rounded-xl`. Primary button: `bg-primary text-white p-2 rounded-lg text-sm mt-5 flex px-3 w-fit` or `rounded-md bg-primary px-3 py-2 text-sm text-white shadow-sm hover:shadow-md`. Danger: `rounded-md bg-red-700 px-3 py-2 text-sm text-white hover:bg-red-500`. Modal: `<Modal :label :isOpen>` with form `grid gap-x-6 gap-y-4 sm:grid-cols-6`, inputs `:class="'sm:col-span-6'"`, cancel `bg-slate-50 text-slate-500 hover:bg-slate-200`. Tabs: Conversations.vue:18-35 pattern. Nav item (Layout.vue): `<li :class="$page.url.startsWith('/settings/x') ? 'bg-slate-50 text-black' : ''"><Link class="flex items-center p-2 space-x-3 rounded-md"><svg 24x24/><span>{{ $t('…') }}</span></Link></li>`; hidden for agents via `v-if="!isOrgAgent"`. Mobile list (Main.vue): `<Link class="flex justify-between border-b py-3 mb-2">` with icon + chevron. Responsive: sidebar `md:w-1/5 … md:block hidden`, content `md:w-4/5 … md:overflow-y-auto`; pages are desktop-first with `md:` prefixes and unprefixed mobile fallback (`bg-white md:bg-inherit`). i18n: `$t('English string')` keys in flat `lang/en.json`, `lang/ar.json` (RTL handled by shared `isRtl`).
