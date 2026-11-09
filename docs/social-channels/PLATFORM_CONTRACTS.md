# Platform API contracts (verified Sep 2026)

Sources: official docs for Telegram Bot API, Meta Messenger Platform, Instagram Platform; TikTok verified against Chatwoot's production integration (lib in `app/services/tiktok/*`, develop branch) + TikTok Business API docs.

## Telegram (Bot API)

- Base: `https://api.telegram.org/bot<TOKEN>/<method>`
- Webhook: `setWebhook {url, secret_token, allowed_updates:["message","edited_message"]}`; Telegram sends header `X-Telegram-Bot-Api-Secret-Token: <secret_token>` on every POST. Verify it.
- Inbound update:
  ```json
  {"update_id":1,"message":{"message_id":55,"from":{"id":123,"is_bot":false,"first_name":"A","last_name":"B","username":"ab","language_code":"ar"},"chat":{"id":123,"type":"private","first_name":"A"},"date":1758600000,
    "text":"hi",                        // OR one of:
    "photo":[{"file_id":"..","file_unique_id":"..","width":90,"height":90,"file_size":1}, ... largest last],
    "document":{"file_id":"..","file_name":"x.pdf","mime_type":"application/pdf","file_size":1},
    "video":{"file_id":"..","mime_type":"video/mp4","duration":1}, "voice":{"file_id":"..","mime_type":"audio/ogg"}, "audio":{"file_id":"..","mime_type":"audio/mpeg","title":".."},
    "sticker":{"file_id":"..","emoji":"😀","is_animated":false}, "location":{"latitude":1.0,"longitude":2.0}, "contact":{"phone_number":"+966..","first_name":".."},
    "caption":"optional caption for media", "reply_to_message":{"message_id":54}}}
  ```
  Only `chat.type == "private"` is a customer conversation. `message_id` is unique **per chat**, not globally → store `wam_id` as `tg:<chat_id>:<message_id>`.
- Files: `getFile {file_id}` → `{result:{file_path}}` then download `https://api.telegram.org/file/bot<TOKEN>/<file_path>` (bots can download ≤ 20 MB).
- Send: `sendMessage {chat_id,text,reply_to_message_id?}`, `sendPhoto {chat_id,photo:<url|file_id|multipart>,caption?}`, `sendVideo`, `sendAudio`, `sendVoice`, `sendDocument {document,caption?}`, `sendLocation {chat_id,latitude,longitude}`. Response `{ok:true,result:{message_id, ...}}`. Send by public URL works (≤5 MB photo / 20 MB other by URL; multipart up to 50 MB).
- `getMe` → `{result:{id,username,first_name}}` — validate token at connect time.
- No delivery/read receipts. Status after successful send = `sent` (terminal).
- Errors: 403 `Forbidden: bot was blocked by the user`; 429 `{parameters:{retry_after}}`; 400 chat not found.
- Rate limits: 30 msg/s global, ~1 msg/s per chat (campaigns: throttle ≥ 25/s, sleep on 429).
- Messaging window: none. Bot can message any user who has started the bot (pressed Start / sent a message).
- Sender profile: `first_name`, `last_name`, `username` in payload; avatar via `getUserProfilePhotos {user_id,limit:1}` → `photos[0][last].file_id` → getFile (optional).

## Facebook Messenger (Graph API v25.0)

- Webhook GET verify: `hub.mode=subscribe&hub.verify_token=..&hub.challenge=..` → echo challenge.
- Signature: header `X-Hub-Signature-256: sha256=<hex hmac_sha256(app_secret, raw_body)>`.
- Inbound POST:
  ```json
  {"object":"page","entry":[{"id":"<PAGE_ID>","time":1758600000123,"messaging":[
    {"sender":{"id":"<PSID>"},"recipient":{"id":"<PAGE_ID>"},"timestamp":1758600000123,
     "message":{"mid":"m_...","text":"hi","attachments":[{"type":"image|audio|video|file|fallback|location","payload":{"url":"https://...","coordinates":{"lat":1,"long":2}}}],"reply_to":{"mid":"m_.."},"is_echo":true?,"app_id":..},
     "delivery":{"mids":["m_.."],"watermark":1758600000123},
     "read":{"watermark":1758600000123},
     "postback":{"title":"..","payload":".."},
     "message":{"quick_reply":{"payload":".."}}}]}]}
  ```
  Timestamps are **milliseconds**. `mid` is globally unique. Attachment URLs are temporary → download immediately.
  `is_echo:true` = message sent by the page (from Inbox or our own API) — skip if we already stored it (dedupe by mid).
- Send: `POST https://graph.facebook.com/v25.0/me/messages?access_token=<PAGE_TOKEN>` (or `/{PAGE_ID}/messages`)
  ```json
  {"recipient":{"id":"<PSID>"},"messaging_type":"RESPONSE|UPDATE|MESSAGE_TAG","tag":"...", "message":{"text":"hi"}}
  {"recipient":{"id":"<PSID>"},"messaging_type":"RESPONSE","message":{"attachment":{"type":"image|audio|video|file","payload":{"url":"https://public-url","is_reusable":true}}}}
  ```
  Response `{"recipient_id":"<PSID>","message_id":"m_..."}`. Attachment limit 25 MB.
- Window: **24 h** from user's last message (standard). Outside it only `MESSAGE_TAG` with approved tags (CONFIRMED_EVENT_UPDATE, POST_PURCHASE_UPDATE, ACCOUNT_UPDATE, HUMAN_AGENT within 7 days) — non-promotional. Campaigns → only contacts with last inbound < 24h, else mark log `skipped_window`.
- Profile: `GET https://graph.facebook.com/v25.0/<PSID>?fields=first_name,last_name,profile_pic&access_token=<PAGE_TOKEN>`.
- Subscribe page to app fields: `messages, messaging_postbacks, message_deliveries, message_reads, message_echoes` (`POST /{PAGE_ID}/subscribed_apps?subscribed_fields=...`).
- Connection settings needed per org: `page_id`, `page_access_token` (long-lived), `app_id`, `app_secret` (for signature), `verify_token` (we generate).

## Instagram Direct (Graph API v25.0, via Facebook Page-linked professional account)

- Webhook body identical in shape to Messenger but `"object":"instagram"`, `entry[].id = <IG_ACCOUNT_ID>`, `sender.id = <IGSID>`.
  Extra attachment types: `share, story_mention, reel, ig_reel`; message flags `is_echo, is_deleted, is_unsupported`; `read:{watermark}`; `reaction:{reaction,mid,action}`.
- Send: `POST https://graph.facebook.com/v25.0/<IG_ACCOUNT_ID>/messages?access_token=<PAGE_TOKEN>` (or `/me/messages`) — same body as Messenger (text / attachment image|video|audio by URL). (With Instagram-Login tokens the host is `graph.instagram.com` and token is an IG user token — driver takes `api_host` from account settings; default graph.facebook.com.)
  Limits: image png/jpeg ≤ 8 MB; video ≤ 25 MB; audio ≤ 25 MB. No documents/files.
- Window: **24 h**; `HUMAN_AGENT` tag extends to 7 days.
- Profile: `GET https://graph.facebook.com/v25.0/<IGSID>?fields=name,username,profile_pic&access_token=<PAGE_TOKEN>`.
- Subscribe fields: `messages, messaging_postbacks, message_reactions, messaging_seen`.
- Settings per org: `instagram_account_id`, `page_id`, `access_token`, `app_secret`, `verify_token`.

## TikTok Business Messaging (open_api v1.3)

- Base: `https://business-api.tiktok.com/open_api/v1.3`; auth header `Access-Token: <access_token>`.
- OAuth: authorize `https://www.tiktok.com/v2/auth/authorize?client_key=<APP_ID>&response_type=code&scope=user.info.basic,user.info.username,user.info.stats,user.info.profile,user.account.type,user.insights,message.list.read,message.list.send,message.list.manage&redirect_uri=..&state=..`
  Token: `POST /tt_user/oauth2/token/ {client_id,client_secret,grant_type:"authorization_code",auth_code,redirect_uri}` → `data:{open_id (== business_id), scope, access_token, refresh_token, expires_in (~86400), refresh_token_expires_in (~30d)}`.
  Refresh: `POST /tt_user/oauth2/refresh_token/ {client_id,client_secret,grant_type:"refresh_token",refresh_token}` → same keys. Refresh when `expires_at - 5min < now`; if refresh token expired → status `reauthorization_required`.
- Account: `GET /business/get/?business_id=..&fields=["username","display_name","profile_image"]`.
- Webhook config (app-level, one URL for all orgs): `POST /business/webhook/update/ {app_id,secret,event_type:"DIRECT_MESSAGE",callback_url}`; `GET /business/webhook/list/?app_id&secret&event_type=DIRECT_MESSAGE`.
- Webhook signature: header `Tiktok-Signature: t=<unix>,s=<hex>`; `s = hmac_sha256(app_secret, "<t>.<raw_body>")`; reject if `now - t > 5s` (server clock must be NTP-synced).
- Webhook event body: `{"event":"im_receive_msg|im_send_msg|im_mark_read_msg","user_openid":"<BUSINESS_ID>","content":"<JSON string>", ...}`. `content` (decoded):
  ```json
  {"conversation_id":"..","message_id":"..","type":"text|image|share_post|sticker|...","timestamp":1758600000123,
   "from":"<username>","from_user":{"id":"<user open id>"},"to":"<biz username>","to_user":{"id":"<BUSINESS_ID>"},
   "text":{"body":".."}, "image":{"media_id":".."}, "share_post":{"embed_url":".."}, "referenced_message_info":{"referenced_message_id":".."},
   "read":{"last_read_timestamp":1758600000123}}
  ```
  Route to org by `user_openid` == channel account `external_id`. Inbound if `to_user.id == business_id`; `im_send_msg` is an echo of our own send (dedupe by message_id; Chatwoot delays echo 2 s).
- Media download: `POST /business/message/media/download/ {business_id,conversation_id,message_id,media_id,media_type:"IMAGE"}` → `data.download_url`; GET it with header `x-user: <access_token>`.
- Send: `POST /business/message/send/ {business_id, recipient_type:"CONVERSATION", recipient:<conversation_id>, message_type:"TEXT", text:{body}, referenced_message_info?:{referenced_message_id}}` or `message_type:"IMAGE", image:{media_id}` after `POST /business/message/media/upload/` multipart `{business_id, media_type:"IMAGE", file}` → `data.media_id`. Response `data.message.message_id`. All responses `{code:0,message:"OK",data:{}}`; `code != 0` is an error.
  Capability check: `GET /business/message/capabilities/get/?business_id&conversation_id&conversation_type=SINGLE&capability_types=["IMAGE_SEND"]` → `data.capability_infos[].capability_result`.
- Constraints: user must message first; **48 h** window; text OR single JPG/PNG image ≤ 3 MB; no video/docs; no text+image in one message. Not available to EEA/CH/UK accounts. Business account must enable "accept DMs from everyone".
- Identity: contact `channel_identifier = conversation_id` (that is what we send to); store `tiktok_user_id` (from_user.id) and `username` in contact metadata.

## Common driver contract we will implement

```
interface ChannelDriver {
  channel(): string                                 // 'whatsapp'|'telegram'|'messenger'|'instagram'|'tiktok'
  capabilities(): ChannelCapabilities               // text, image, video, audio, document, location, templates, reactions, readReceipts, maxMediaBytes, windowHours (null = none)
  verifyWebhook(Request): bool                      // signature / secret token
  parseWebhook(Request, ChannelAccount): InboundEvent[]   // normalized: message | status | echo | read
  sendText(ChannelAccount, Contact, string $text, ?string $replyTo): SendResult
  sendMedia(ChannelAccount, Contact, MediaRef, ?string $caption): SendResult
  sendLocation(...)? (optional capability)
  fetchProfile(ChannelAccount, string $externalId): ?Profile   // name, avatar url, username
  downloadMedia(ChannelAccount, InboundMedia): DownloadedFile
  isWithinWindow(Contact): bool                      // from windowHours + contact.last_inbound_chat_created_at
}
```
Normalized inbound message keeps a **WhatsApp-compatible `metadata` JSON** (`{type:'text',text:{body}}`, `{type:'image',image:{...}}`, ...) so ChatBubble / mobile renderers work unchanged, plus `channel` and `channel_raw` (original payload, trimmed).
