# API الشات وإعدادات القنوات — مرجع سريع

كل الأمثلة **ردود حقيقية** من الخادم (منشأة تجريبية: «متجر الريان»)، مختصرة بحذف المكرّر فقط.

**المشترك**

```
Base:    https://app.mnjz.net/api/v1
Headers: Authorization: Bearer <token>
         Accept: application/json
```

- **نجاح:** `{ "statusCode": 200, "success": true, "message": "…أو null", "data": … }`
- **خطأ:** `{ "statusCode": 4xx, "success": false, "message": "…" }` — بلا `data`. في `400` يأتي `errors: { "field": ["…"] }`.
- `message` تصل بلغة التطبيق. اعرضها كما هي.
- **أرسل `contact_uuid` لا `phone`** في كل الإرسال. `phone` يعمل كما كان للنسخ القديمة (WhatsApp فقط).

---

## ١. الشات

### `GET /channels` — القنوات المربوطة وما تدعمه كل قناة

لكل الأدوار. اطلبه عند فتح التطبيق: منه فلتر القنوات وإخفاء الأزرار.

```json
{
  "data": {
    "connected": ["whatsapp", "telegram", "webchat"],
    "channels": [
      {
        "channel": "telegram",
        "label": "Telegram",
        "connected": true,
        "capabilities": {
          "window_hours": null, "templates": false, "interactive": true,
          "location": true, "location_request": false, "media_caption": true,
          "text_with_media": true, "multiple_attachments": false,
          "delivery_receipts": false, "read_receipts": false, "block_contact": false,
          "reactions": false, "reply_to": true, "campaigns": true,
          "media_limits": { "image": 10485760, "video": 52428800, "audio": 52428800, "document": 52428800 }
        }
      }
    ]
  }
}
```

`channels` فيها القنوات الستّ دائماً: `whatsapp`، `telegram`، `messenger`، `instagram`، `tiktok`، `webchat`.

---

### `GET /list-messages-from-uuid-to-end-v2` — مزامنة المحادثات

القائمة والرسائل معاً. v1 (`/list-messages-from-uuid-to-end`) بنفس المعاملات.

| معامل | مثال | |
|---|---|---|
| `created_at` | `2026-11-16 10:00:00` | ما بعد هذا الوقت فقط |
| `per_page`، `page` | `50`، `1` | ترقيم بالمحادثات |
| `channel` | `telegram` | قناة واحدة. القيمة المجهولة تُتجاهل |
| `contact_uuid` | `53142841-…` | محادثة واحدة كاملة |

```json
{
  "data": [
    {
      "contact": {
        "contact_id": 2,
        "contact_uuid": "53142841-c87f-4c8e-9cf7-ec1af08c7903",
        "phone": null,
        "formatted_phone_number": null,
        "contact_full_name": "خالد",
        "latest_chat_created_at": "2026-11-16 13:01:53",
        "last_inbound_chat_created_at": "2026-11-16 13:01:53",
        "is_blocked": 0,
        "is_favorite": 0,
        "unread_messages_count": 1,
        "ticket_status": "open",
        "ticket_assigned_to": null,
        "contact_categories": [],
        "channel": "telegram",
        "channel_identifier": "5512399",
        "channel_username": "khaled_88",
        "messaging_window_hours": null
      },
      "messages": [
        {
          "type": "chat",
          "value": {
            "id": 2,
            "uuid": "dd95a5f7-0555-480b-9e4b-7683b7eaab13",
            "created_at": "2026-11-16 16:01:53",
            "metadata": "{\"type\":\"text\",\"text\":{\"body\":\"عندكم توصيل لجدة؟\"}}",
            "type": "inbound",
            "wam_id": "tg:7712345678:5512399:57",
            "channel": "telegram",
            "status": "delivered",
            "media": null,
            "logs": [],
            "user": null
          }
        }
      ]
    }
  ],
  "pagination": { "page": 1, "per_page": 50, "total_contacts": 3, "last_page": 1 },
  "assigned_only": false
}
```

- **تحت الاسم:** `formatted_phone_number`، وإلّا `@` + `channel_username`، وإلّا اسم القناة.
- **`messaging_window_hours`:** `null` = لا نافذة، فلا شريط ٢٤ ساعة.
- **`assigned_only: true`:** المزامنة لما أُسند للموظّف فقط. نفّذ مزامنة كاملة عند فتح التطبيق، واحذف ما لم يعد.

---

### `GET /contacts` — جهات الاتصال

معاملات: `channel`، `search`، `page`، `per_page`. الردّ بشكل ترقيم Laravel (`data`، `links`، `meta`).

```json
{
  "data": [
    {
      "id": 3,
      "uuid": "a33e438a-dab6-47b1-be37-39d51d6be6fd",
      "full_name": "سارة",
      "phone": null,
      "formatted_phone_number": "+966 50 123 4567",
      "channel": "webchat",
      "channel_identifier": "600f2bec-68c4-471c-920e-7846ad555f50",
      "channel_username": null,
      "channel_capabilities": { "templates": false, "location": false, "location_request": false, "…": "…" },
      "webchat_visitor": {
        "phone": "+966501234567",
        "email": null,
        "first_page_url": "https://alrayyan.sa/products/9",
        "last_page_url": "https://alrayyan.sa/cart",
        "referrer": "https://google.com/",
        "country": "SA",
        "browser": "Safari · iOS",
        "locale": "ar",
        "journey": null
      },
      "is_messaging_window_open": true,
      "messaging_window_hours": null,
      "unread_messages": 0
    }
  ],
  "meta": { "current_page": 1, "last_page": 1, "per_page": 10, "total": 1 }
}
```

`webchat_visitor` لزائر المحادثة المباشرة فقط، وغير موجود لغيره.

---

### `GET /contacts/{uuid}` — تفاصيل جهة اتصال

```json
{
  "data": {
    "id": 2,
    "uuid": "53142841-c87f-4c8e-9cf7-ec1af08c7903",
    "full_name": "خالد",
    "phone": null,
    "formatted_phone_number": null,
    "channel": "telegram",
    "channel_identifier": "5512399",
    "channel_username": "khaled_88",
    "channel_capabilities": { "templates": false, "location": true, "location_request": false, "…": "…" },
    "messaging_window_hours": null,
    "is_messaging_window_open": true,
    "webchat_visitor": null,
    "chat_ticket": { "id": 2, "status": "open", "assigned_to": null, "is_latest": 1, "assigned_seen": 1 },
    "groups": [],
    "categories": []
  }
}
```

`chat_ticket` = **أحدث** تذكرة.

---

### `POST /send-msg` — إرسال نصّ

```json
{ "contact_uuid": "53142841-c87f-4c8e-9cf7-ec1af08c7903", "type": "text", "message": "نعم، التوصيل لجدة خلال يومين", "msg_uuid": "b128a49d-6420-4b8d-972a-04bcb44b56aa" }
```

**جهة على قناة غير WhatsApp** — يُرسل فوراً:

```json
{ "statusCode": 200, "success": true, "message": null,
  "data": { "contact_id": 2, "contact_uuid": "53142841-…", "channel": "telegram", "message_id": "tg:7712345678:5512399:318" } }
```

**جهة WhatsApp** — في الطابور:

```json
{ "statusCode": 200, "success": true, "message": "Message queued for sending.",
  "data": { "queued": true, "contact_id": 1, "contact_uuid": "01d49d88-…", "phone": "+966551234567" } }
```

- `msg_uuid` اختياري: يصير `uuid` الرسالة، فتطابق به الفقاعة المؤقّتة.
- **النافذة مغلقة** ⇒ `422`، والنصّ بحسب القناة.

---

### `POST /send-msg` — إرسال ملف (multipart)

```
contact_uuid=01d49d88-a6d9-41ea-a03c-de6c2925d5ba
file=@invoice-1042.pdf          (أو files[] لعدّة ملفات)
caption=فاتورة الطلب
msg_uuid[]=…                    (اختياري، واحد لكل ملف)
```

```json
{ "statusCode": 200, "success": true, "message": "Message sent successfully",
  "data": { "queued": true, "files": 1, "contact_id": 1, "contact_uuid": "01d49d88-…", "phone": "+966551234567" } }
```

---

### `POST /chats/upload/chunk` — ملف كبير على قطع (multipart)

```
contact_uuid=01d49d88-…   upload_id=up-7f3a   index=0   total=2   chunk=@part0   file_name=catalog.pdf
```

قطعة لم تُكمل الملف:

```json
{ "data": { "completed": false, "received": 1, "total": 2 } }
```

آخر قطعة:

```json
{ "message": "Message sent successfully",
  "data": { "completed": true, "queued": true, "received": 2, "total": 2, "contact_id": 1, "contact_uuid": "01d49d88-…", "phone": "+966551234567" } }
```

قناة الجهة مفصولة ⇒ `403` عند **أول** قطعة.

**`DELETE /chats/upload/chunk`** `{ "upload_id": "up-9b21" }` — إلغاء رفع لم يكتمل:

```json
{ "data": { "discarded": true } }
```

---

### `GET /list-templates` — قوالب WhatsApp

```json
{ "data": [ { "uuid": "8c570080-90c1-44f2-a2b2-0dab8164f8e0", "name": "order_update", "metadata": "{\"name\":\"order_update\",\"components\":[…]}", "updated_at": "16/11/2026 13:31" } ] }
```

اعرض زرّ القوالب فقط حين `capabilities.templates = true`.

---

### `POST /send-template` — إرسال قالب

```json
{ "contact_uuid": "01d49d88-a6d9-41ea-a03c-de6c2925d5ba", "template_uuid": "8c570080-90c1-44f2-a2b2-0dab8164f8e0" }
```

```json
{ "statusCode": 200, "success": true, "message": "Template sent successfully", "data": null }
```

جهة على قناة بلا قوالب:

```json
{ "statusCode": 422, "success": false, "message": "Templates is not available on Telegram." }
```

### `POST /send-auth-template` — قالب المصادقة المختار في الإعدادات

```json
{ "contact_uuid": "01d49d88-a6d9-41ea-a03c-de6c2925d5ba" }
```

الردّ كـ `send-template`.

---

### `POST /request-location` — طلب موقع العميل

```json
{ "uuid": "01d49d88-a6d9-41ea-a03c-de6c2925d5ba", "body": "من فضلك أرسل موقعك لتحديد التوصيل" }
```

```json
{ "success": true,
  "data": { "chat": { "id": 5, "uuid": "e2bbe70f-…", "type": "outbound", "channel": "whatsapp", "wam_id": "wamid.HBgM…" } } }
```

قناة لا تدعمه:

```json
{ "statusCode": 422, "success": false, "message": "Location requests is not available on Web Chat." }
```

### `GET /organization-location` — موقع النشاط المحفوظ

```json
{ "statusCode": 200, "success": true, "is_set": true,
  "data": { "latitude": 24.7136, "longitude": 46.6753, "name": "متجر الريان", "address": "طريق الملك فهد، الرياض، SA" } }
```

### `POST /send-location` — إرسال موقع

```json
{ "uuid": "01d49d88-a6d9-41ea-a03c-de6c2925d5ba", "use_organization_location": true }
```

أو موقع محدّد: `latitude`، `longitude`، `name`، `address` بدل `use_organization_location`. الردّ كـ `request-location`.

---

### `POST /mark-as-read` — قراءة المحادثة

```json
{ "contact_id": 2 }
```

```json
{ "statusCode": 200, "success": true, "message": "Chat count reset successfully" }
```

### `GET /list-teams` — الموظّفون (لقائمة الإسناد)

```json
{ "data": [ { "id": 2, "full_name": "منى العتيبي", "email": "mona@alrayyan.sa", "role": "agent", "avatar": null } ] }
```

### `POST /assign-ticket` — إسناد المحادثة لموظّف

```json
{ "contact_id": 2, "user_id": 2 }
```

```json
{ "statusCode": 200, "success": true, "message": "Contact assigned to user successfully" }
```

### `POST /toggle-ticket-status/{contact_id}` — فتح/إغلاق المحادثة

```json
{ "status": "closed" }
```

```json
{ "statusCode": 200, "success": true, "message": "Status updated successfully!" }
```

### `GET /tickets` — قائمة التذاكر

معاملات: `status` (`open`/`closed`)، `assigned_to` (رقم أو `unassigned`)، `channel`، `search`، `page`، `per_page`. الموظّف يرى ما أُسند إليه فقط.

```json
{
  "data": {
    "items": [
      {
        "id": 3,
        "status": "closed",
        "contact_id": 3,
        "contact_uuid": "a33e438a-dab6-47b1-be37-39d51d6be6fd",
        "contact_name": "سارة",
        "phone": null,
        "channel": "webchat",
        "channel_identifier": "600f2bec-68c4-471c-920e-7846ad555f50",
        "formatted_phone_number": "+966 50 123 4567",
        "assigned_to": 1,
        "assigned_to_name": "أحمد صلاح",
        "assigned_seen": true,
        "last_message_at": "2026-11-16 13:28:53",
        "updated_at": "2026-11-16 13:31:53"
      }
    ],
    "pagination": { "page": 1, "per_page": 25, "total": 1, "last_page": 1 }
  }
}
```

### `GET /tickets/summary` — العدّادات

معامل اختياري: `channel`.

```json
{ "data": { "open": 2, "closed": 1, "unassigned": 0 } }
```

### `DELETE /delete-chat-for-contact/{contact_uuid}` — مسح رسائل المحادثة

```json
{ "statusCode": 200, "success": true, "message": "Chat deleted successfully" }
```

---

## ٢. إعدادات القنوات

للمالك والمدير فقط، والموظّف يأخذ `403`.

### `GET /settings/channels` — شاشة القنوات

```json
{
  "data": {
    "whatsapp": {
      "connected": true,
      "name": "متجر الريان",
      "connect_mode": "web",
      "connect_url": "https://app.mnjz.net/settings/whatsapp",
      "capabilities": { "…": "…" }
    },
    "channels": [
      {
        "channel": "telegram",
        "label": "Telegram",
        "account": {
          "uuid": "6d11ed87-b3ec-4b28-8dd2-7c81610faaca",
          "name": "الريان للدعم",
          "username": "alrayyan_support_bot",
          "external_id": "7712345678",
          "avatar": null,
          "status": "active",
          "is_active": true,
          "needs_reauthorization": false,
          "last_error": null
        },
        "connect_mode": "in_app",
        "connect_url": "https://app.mnjz.net/settings/channels/telegram",
        "fields": [ { "key": "bot_token", "label": "توكن البوت", "type": "password", "hint": "احصل عليه من @BotFather على تيليجرام." } ],
        "platform_ready": true,
        "capabilities": { "…": "…" },
        "notes": [ "لا نافذة ردّ — تستطيع مراسلة العميل متى شئت.", "…" ]
      }
    ],
    "email": { "connected": false, "needs_attention": false, "from_address": null, "connect_mode": "in_app" },
    "webchat": { "exists": true, "enabled": true, "available": true }
  }
}
```

| الحقل | التصرّف |
|---|---|
| `account: null` | القناة غير مربوطة |
| `connect_mode: "in_app"` | نموذج من `fields` (Telegram) |
| `connect_mode: "web"` | زرّ يفتح `connect_url` في المتصفّح (Messenger، Instagram، TikTok، WhatsApp) |
| `needs_reauthorization: true` | شارة «يحتاج إعادة ربط» + `last_error` |
| `webchat.available: false` | الإضافة خارج الباقة: القناة مقفلة |

### `POST /settings/channels` — ربط Telegram (أو إعادة ربطه)

```json
{ "channel": "telegram", "credentials": { "bot_token": "7712345678:AAH-k9xQ2v_example" } }
```

```json
{ "statusCode": 200, "success": true, "message": "Telegram was connected.",
  "data": {
    "account": { "uuid": "6d11ed87-…", "name": "الريان للدعم", "username": "alrayyan_support_bot", "status": "active", "is_active": true },
    "webhook_registered": true
  } }
```

- **`webhook_registered: false`:** حُفظ الحساب لكن الرسائل لن تصل. اعرضه تحذيراً.
- **التوكن مرفوض أو البوت مربوط بمنشأة أخرى** ⇒ `422`.
- **Messenger/Instagram/TikTok** ⇒ `422` `"Connect Messenger from the web dashboard."` — افتح `connect_url` بدل ذلك.

### `DELETE /settings/channels/{account.uuid}` — فصل قناة (Telegram، Messenger، Instagram، TikTok، Email)

```json
{ "statusCode": 200, "success": true, "message": "Telegram was disconnected.", "data": null }
```

### `DELETE /settings/channels/whatsapp` — فصل WhatsApp

```json
{ "statusCode": 200, "success": true, "message": "Your integration has been removed successfully!", "data": null }
```

⚠️ يحذف كل قوالب WhatsApp. اطلب تأكيداً قبل الإرسال.

---

### `GET /settings/channels/email` — إعداد البريد (SMTP) للحملات

```json
{ "data": { "account": null, "smtp": null, "ports": [465, 587, 2525] } }
```

(`null` قبل أول ربط.)

### `POST /settings/channels/email` — حفظ البريد

```json
{ "host": "smtp.office365.com", "port": 587, "encryption": "tls", "username": "sales@alrayyan.sa", "password": "S3cret!pass", "from_address": "sales@alrayyan.sa", "from_name": "متجر الريان", "reply_to": "support@alrayyan.sa" }
```

```json
{ "statusCode": 200, "success": true, "message": "Email was connected. Your email campaigns will be sent from sales@alrayyan.sa.",
  "data": {
    "account": { "uuid": "43902298-…", "status": "active", "is_active": true, "needs_reauthorization": false, "last_error": null },
    "smtp": { "host": "smtp.office365.com", "port": 587, "encryption": "tls", "username": "sales@alrayyan.sa", "has_password": true, "from_address": "sales@alrayyan.sa", "from_name": "متجر الريان", "reply_to": "support@alrayyan.sa" },
    "ports": [465, 587, 2525]
  } }
```

كلمة مرور خاطئة:

```json
{ "statusCode": 400, "success": false, "message": "The provided data is invalid.",
  "errors": { "password": ["The SMTP server rejected the username or password."] } }
```

- **كلمة المرور لا تُرسل إليك أبداً** (`has_password` فقط). اترك الحقل فارغاً لإبقائها، ما دام الخادم والمنفذ واسم المستخدم كما هي.
- المنفذ `465` ⇒ `ssl`، وغيره ⇒ `tls`.

### `POST /settings/channels/email/test` — رسالة تجربة لبريد المستخدم

```json
{ "statusCode": 200, "success": true, "message": "A test email was sent to ahmed@alrayyan.sa.", "data": null }
```

---

### `GET /settings/webchat` — إعدادات المحادثة المباشرة

```json
{
  "data": {
    "key": "wc_K2lg3lf8fnCqNRL0mbEvt96x",
    "snippet": "<script async src=\"https://app.mnjz.net/widget/v1/loader.js\" data-mnjz-key=\"wc_K2lg3lf8fnCqNRL0mbEvt96x\"></script>",
    "settings": {
      "enabled": true,
      "allowed_domains": [],
      "appearance": {
        "color": "#16a34a", "position": "right", "offset_x": 20, "offset_y": 20,
        "title": "", "subtitle": "", "welcome_message": "",
        "title_en": "", "subtitle_en": "", "welcome_message_en": "",
        "language": "ar", "logo_url": null, "logo_path": null
      },
      "prechat": {
        "default_country": "SA", "phone": "required", "email": "required",
        "consent_text": null, "consent_text_en": null,
        "questions": [
          { "id": "name", "target": "name", "field": null, "validation": "text", "required": true, "label_ar": null, "label_en": null },
          { "id": "email", "target": "email", "field": null, "validation": "email", "required": true, "label_ar": null, "label_en": null },
          { "id": "phone", "target": "phone", "field": null, "validation": "phone", "required": true, "label_ar": null, "label_en": null }
        ]
      },
      "behavior": { "show_agent_names": true, "sound": true, "uploads": true }
    },
    "logo": null,
    "contact_fields": [],
    "faqs": [],
    "options": {
      "positions": ["right", "left"],
      "languages": ["auto", "ar", "en"],
      "phone_modes": ["off", "optional", "required"],
      "email_modes": ["off", "optional", "required"],
      "question_targets": ["name", "email", "phone", "field"],
      "question_validations": ["text", "number", "email", "phone", "date", "select", "saudi_id"],
      "questions_max": 12,
      "faqs_max": 30,
      "logo_max_kb": 1024,
      "faq_image_max_kb": 2048
    }
  }
}
```

- `snippet`: الكود الذي يلصقه التاجر في موقعه (زرّ نسخ/مشاركة).
- `logo`: رابط الشعار للعرض.
- ارسم الاختيارات من `options`.

### `POST /settings/webchat` — حفظ الإعدادات

```json
{
  "enabled": true,
  "allowed_domains": ["alrayyan.sa"],
  "appearance": {
    "color": "#0f766e", "position": "right", "offset_x": 20, "offset_y": 20,
    "title": "تحدّث معنا", "subtitle": "نرد خلال دقائق", "welcome_message": "أهلاً بك في متجر الريان",
    "title_en": "Chat with us", "language": "ar"
  },
  "prechat": {
    "default_country": "SA",
    "email": "off",
    "questions": [ { "target": "name", "required": true }, { "target": "phone", "required": false } ]
  },
  "behavior": { "show_agent_names": true, "sound": true, "uploads": true }
}
```

الردّ: الإعدادات كاملة كـ `GET`، و`message: "Settings updated successfully"`. هنا صار `prechat.phone: "optional"` بسبب الأسئلة المرسلة.

- **إلزامي:** `enabled`، و`appearance.color` و`position` و`offset_x` و`offset_y` و`language`، و`prechat.default_country`، و`prechat.email`، و`behavior` كاملة.
- **ما لا ترسله يبقى كما هو:** الشعار، والنصوص الإنجليزية، و`questions`.

### `POST /settings/webchat/logo` (multipart: `logo`) · `DELETE /settings/webchat/logo`

`png`/`jpg`/`webp` حتى ١ ميغابايت. الردّ: الإعدادات كاملة، و`logo` فيه الرابط:

```json
{ "data": { "logo": "https://app.mnjz.net/media/public/webchat-logos/1/01m7j8x7yewfjnqsc403wfpzyy.png", "…": "…" } }
```

### `POST /settings/webchat/rotate-key` — مفتاح جديد

```json
{ "message": "A new key was generated. Replace the code on your website.",
  "data": { "key": "wc_ZyAk9fPdfpWqjKbTlVZMASh1", "snippet": "<script … data-mnjz-key=\"wc_ZyAk9fPdfpWqjKbTlVZMASh1\"></script>", "…": "…" } }
```

⚠️ الكود القديم في موقع التاجر يتوقّف فوراً. اطلب تأكيداً.

---

### الأسئلة الشائعة (FAQs)

**`POST /settings/webchat/faqs`** — إضافة (multipart عند إرفاق `image`)

```json
{ "question_ar": "كم مدة التوصيل؟", "answer_ar": "من يوم إلى ثلاثة أيام داخل المملكة", "question_en": "How long is delivery?", "answer_en": "1–3 days within Saudi Arabia" }
```

```json
{ "data": {
    "faq":  { "id": 1, "question_ar": "كم مدة التوصيل؟", "question_en": "How long is delivery?", "answer_ar": "من يوم إلى ثلاثة أيام داخل المملكة", "answer_en": "1–3 days within Saudi Arabia", "image_url": null, "is_active": true },
    "faqs": [ "…كل الأسئلة بالترتيب…" ]
} }
```

**`POST /settings/webchat/faqs/{id}`** — تعديل

```json
{ "question_ar": "كم مدة التوصيل؟", "question_en": "How long is delivery?", "answer_ar": "يومان داخل الرياض، وثلاثة لبقية المدن", "answer_en": "2 days in Riyadh, 3 elsewhere", "is_active": true }
```

⚠️ **أرسل كل الحقول في التعديل.** الحقل النصّي الغائب يُمسح (`question_en`، `answer_ar`، `answer_en`). يبقى كما هو فقط `is_active` والصورة. لحذف الصورة: `remove_image: true`.

**`POST /settings/webchat/faqs/reorder`**

```json
{ "ids": [2, 1] }
```

**`DELETE /settings/webchat/faqs/{id}`**

الترتيب والحذف يُرجعان `data.faqs` بالترتيب الجديد.

**قواعد:**
- `question_ar` إلزامي.
- إجابة نصّية **أو** صورة.
- حتى ٣٠ سؤالاً.
- الصورة `png`/`jpg`/`webp` حتى ٢ ميغابايت.

---

## ٣. الأخطاء

| الحالة | المعنى |
|---|---|
| `400` | بيانات ناقصة أو خاطئة — `errors` بأسماء الحقول |
| `403` | الدور لا يسمح، أو الميزة خارج الباقة، أو القناة مفصولة |
| `404` | غير موجود أو تابع لمنشأة أخرى |
| `422` | مرفوض: نافذة مغلقة، ميزة لا تدعمها القناة، توكن مرفوض، قناة تُربط من الويب |
| `429` | طلبات كثيرة (الربط والبريد) — حاول بعد قليل |

التفاصيل والخلفية في [MOBILE_CHANNELS_IMPLEMENTATION.md](MOBILE_CHANNELS_IMPLEMENTATION.md).
