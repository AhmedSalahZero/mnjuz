# رسائل الشات — الأعمدة وقيمها لتطبيق الموبايل

## 0) أي قناة يتكلّم عنها هذا التقرير؟

| القناة | ماذا تحمل | هل هي موضوع هذا التقرير؟ |
|---|---|---|
| `GET /api/v1/list-messages-from-uuid-to-end` (و v2) | الرسالة كاملة | ✅ |
| **Pusher** — `NewChatEvent` على `chats.ch{orgId}.{userId}` | نفس الرسالة كاملة | ✅ |
| **Firebase (FCM)** — إشعار الجوال | عنوان + نصّ + معرّفات فقط | ❌ (انظر آخر التقرير، القسم 8) |

كل الأمثلة في هذا التقرير هي **`value` الرسالة** كما تصل من REST ومن Pusher.
تحقّقنا من تطابق المسارين: `ApiController::formatChatValue` (REST) و`ChatBroadcastPayloadBuilder::buildMinimalValue` (Pusher) يُخرجان **نفس المفاتيح ونفس القيم** بايتاً ببايت. الفارق الوحيد أن حمولة Pusher قد **تُقلَّص** إن تجاوزت 10KB (تُحذف `contact_full_name` ثم `phone` ثم تُقصّ `logs` ثم `metadata`)، بينما REST لا تُقلَّص أبداً.

**إشعار Firebase ليس رسالة** — لا يحمل `metadata` ولا `media` ولا `id` الرسالة. لا تبنِ عليه فقاعة؛ استخدمه لإيقاظ التطبيق ثم اقرأ الرسالة من REST أو Pusher.

---

## 1) الغلاف

**REST:** `data[]` عنصر لكل جهة اتصال:

| العمود | القيمة | nullable |
|---|---|---|
| `contact_id` | int | لا |
| `last_inbound_chat_created_at` | `"2026-08-24 10:00:00"` | **نعم** |
| `is_blocked` | bool | نعم |
| `ticket_status` | `open` / `pending` / `closed` | **نعم** (لا تذكرة) |
| `ticket_assigned_to` | int (user id) | **نعم** |
| `unread_messages_count` | int | لا |
| `contact_categories` | `[{id, name, background_color, text_color}]` | لا (قد تكون `[]`) |
| `messages` | `[{type, value}]` | لا |
| `pagination` | `{page, per_page, total_contacts, last_page}` | **يغيب كلياً** بدون `per_page` |

`messages[i].type` = `chat` أو `ticket` أو `notes`. **الرسالة الفعلية = `chat` فقط**، وباقي هذا التقرير عنها.

**Pusher:** `{ "chat": [ { "type": "chat", "value": {...}, "tempMessageId": "..." } ] }`
قد يصل بدون مصفوفة: `{ "chat": { "type": "chat", "value": {...} } }`. `tempMessageId` يظهر عند الإرسال من التطبيق فقط.

---

## 2) أعمدة `value` — مشتركة لكل الأنواع

| العمود | القيمة | nullable |
|---|---|---|
| `id` | int — معرّف الرسالة | لا |
| `uuid` | string — معرّف الرسالة عندنا، أو `msg_uuid` المرسل من التطبيق إن أُرسل | لا (العمود NOT NULL) |
| `contact_uuid` | string | نعم |
| `contact_id` | int | نعم |
| `is_new_contact` | bool — دائماً `false` في REST | لا |
| `phone` | `"9665XXXXXXX"` | **نعم** (يُحذف عند ضغط حجم Pusher) |
| `formatted_phone_number` | `"+966 5X XXX XXXX"` | **نعم** (نفس السبب) |
| `contact_full_name` | string ≤120 بايت | **نعم** |
| `organization_id` | int | نادراً |
| `latest_chat_created_at` | datetime | نعم |
| `is_blocked` / `is_favorite` | **يصل `0` / `1` كأرقام لا `true`/`false`** | نعم |
| `unread_messages_count` | int | لا |
| `created_at` | `"2026-08-24 10:00:04"` | نادراً |
| `deleted_at` | datetime — غير null ⇒ الرسالة محذوفة | **نعم غالباً** |
| `metadata` | **string JSON** (محتوى الرسالة) | **نعم** (قد يصل `"{}"` أو `null` عند الضغط) |
| `type` | `inbound` / `outbound` — **اتجاه لا نوع** | لا |
| `wam_id` | `"wamid.…"` | نعم |
| `status` | `accepted` · `sent` · `delivered` · `read` · `failed` فقط | **نعم** (أي قيمة أخرى تُحوَّل null، و`played` ⇒ `delivered`) |
| `media` | object (جدول 3) | **نعم** |
| `logs` | array (جدول 4) | لا (قد تكون `[]`) |
| `user` | `{"full_name": "..."}` — المُرسِل | **نعم** (دائماً null في الوارد) |

> `user.full_name` هو الحقل الوحيد. `first_name` / `last_name` لم يعودا يخرجان، وقد يصل `""`.

في **v2** حقول جهة الاتصال (`contact_uuid`, `contact_id`, `phone`, `formatted_phone_number`, `contact_full_name`, `is_blocked`, `is_favorite`, `unread_messages_count`, `latest_chat_created_at`, `is_new_contact`, `organization_id`) لا تتكرّر داخل الرسالة — تأتي مرة واحدة في كائن `contact`.

---

## 3) `value.media` — للأنواع image/video/audio/document/sticker فقط

| العمود | القيمة | nullable |
|---|---|---|
| `type` | MIME: `image/jpeg` · `video/mp4` · `audio/ogg` · `application/pdf` | نعم |
| `size` | بايت — **قد يصل string لا int** | نعم |
| `path` | رابط كامل، مقصوص لـ200 بايت | نعم / قد يكون `""` |
| `name` | اسم الملف، مقصوص لـ80 بايت — غالباً `"N/A"` في الوارد | نعم |

`media == null` أو `path` فارغ ⇒ اعرض «المحتوى غير متاح».

---

## 4) `value.logs` — آخر 6 (أو 2 عند ضغط Pusher)

| العمود | القيمة | nullable |
|---|---|---|
| `logs[i].metadata` | **string JSON** يحوي `status` / `errors` / `id` فقط | لا |

---


**مثال كامل — رسالة صادرة مقروءة بثلاثة سجلات حالة:**

```json
{
  "id": 3279809,
  "uuid": "22fd78ee-c759-4a6e-9036-65bb805fddb8",
  "contact_uuid": "4895c0cc-0366-4650-8aa9-ff83e6e36160",
  "contact_id": 839286,
  "is_new_contact": false,
  "phone": "+966500000000",
  "formatted_phone_number": "+966 50 000 0000",
  "organization_id": 211,
  "latest_chat_created_at": "2026-08-24 16:15:48",
  "is_blocked": 0,
  "is_favorite": 0,
  "contact_full_name": "اختبار أنواع الرسائل",
  "unread_messages_count": 1,
  "created_at": "2026-08-24 18:15:48",
  "deleted_at": null,
  "metadata": "{\"type\":\"text\",\"text\":{\"body\":\"وعليكم السلام، تفضّل كيف أقدر أساعدك؟\"}}",
  "type": "outbound",
  "wam_id": "wamid.SEED.841936A668FF",
  "status": "read",
  "media": null,
  "logs": [
    {
      "metadata": "{\"id\":\"wamid.SEED.841936A668FF\",\"status\":\"sent\"}"
    },
    {
      "metadata": "{\"id\":\"wamid.SEED.841936A668FF\",\"status\":\"delivered\"}"
    },
    {
      "metadata": "{\"id\":\"wamid.SEED.841936A668FF\",\"status\":\"read\"}"
    }
  ],
  "user": {
    "full_name": "غاليه الدوسري"
  }
}
```

## 5) `metadata` حسب النوع

`metadata` نص JSON. بعد فكّه: `metadata.type` هو نوع المحتوى، والكتلة الداخلية `metadata[type]` **قد تكون `null`** (الخادم يحوّل الفارغ إلى null صراحةً).

> كل «مثال كامل» أدناه هو **مخرَج حقيقي** من نفس الكود الذي يبني رد الـAPI والبثّ (`ChatBroadcastPayloadBuilder`)، مأخوذ من محادثة الاختبار في القسم 7 — منسوخ كما هو بلا تحرير. لاحظ أن `metadata` فيه **نصّ** لا object.

### 5.1 `text`

```json
{"type":"text","text":{"body":"مرحباً"}}
```

| العمود | القيمة | nullable |
|---|---|---|
| `text` | object | نعم |
| `text.body` | نصّ الرسالة | نعم |
| `text.footer` | نصّ تذييل القالب | نعم غالباً |
| `header.text` | عنوان القالب | نعم غالباً |
| `buttons` | `[{type,text,value,parameters}]` أزرار قالب صادر | نعم (غائب غالباً) |
| `buttons[i].type` | `URL` · `QUICK_REPLY` · `PHONE_NUMBER` · `COPY_CODE` | نعم |
| `buttons[i].text` | نصّ الزر | نعم |
| `buttons[i].value` | الرابط/الرقم/الكود | نعم |
| `location_request` | `true` = رسالة «شاركنا موقعك» | نعم (غائب غالباً) |


**مثال كامل — نصّ وارد:**

```json
{
  "id": 3279808,
  "uuid": "97014b05-c8b2-44fb-8354-12c816e80982",
  "contact_uuid": "4895c0cc-0366-4650-8aa9-ff83e6e36160",
  "contact_id": 839286,
  "is_new_contact": false,
  "phone": "+966500000000",
  "formatted_phone_number": "+966 50 000 0000",
  "organization_id": 211,
  "latest_chat_created_at": "2026-08-24 16:15:48",
  "is_blocked": 0,
  "is_favorite": 0,
  "contact_full_name": "اختبار أنواع الرسائل",
  "unread_messages_count": 1,
  "created_at": "2026-08-24 18:13:48",
  "deleted_at": null,
  "metadata": "{\"type\":\"text\",\"text\":{\"body\":\"السلام عليكم، عندي استفسار عن الطلب.\"}}",
  "type": "inbound",
  "wam_id": "wamid.SEED.58CFDFD716D4",
  "status": "delivered",
  "media": null,
  "logs": [],
  "user": null
}
```


**مثال كامل — قالب صادر بعنوان وأزرار:**

```json
{
  "id": 3279810,
  "uuid": "0f8bc72f-3297-4f11-bba8-33f15fcfdafe",
  "contact_uuid": "4895c0cc-0366-4650-8aa9-ff83e6e36160",
  "contact_id": 839286,
  "is_new_contact": false,
  "phone": "+966500000000",
  "formatted_phone_number": "+966 50 000 0000",
  "organization_id": 211,
  "latest_chat_created_at": "2026-08-24 16:15:48",
  "is_blocked": 0,
  "is_favorite": 0,
  "contact_full_name": "اختبار أنواع الرسائل",
  "unread_messages_count": 1,
  "created_at": "2026-08-24 18:17:48",
  "deleted_at": null,
  "metadata": "{\"type\":\"text\",\"header\":{\"text\":\"تأكيد الطلب\"},\"text\":{\"body\":\"طلبك رقم 12345 قيد التجهيز.\",\"footer\":\"منجز\"},\"buttons\":[{\"type\":\"URL\",\"text\":\"تتبّع الطلب\",\"value\":\"https://example.com/track/12345\"},{\"type\":\"QUICK_REPLY\",\"text\":\"إلغاء الطلب\",\"value\":null},{\"type\":\"PHONE_NUMBER\",\"text\":\"اتصل بنا\",\"value\":\"+966500000001\"},{\"type\":\"COPY_CODE\",\"text\":\"نسخ الكود\",\"value\":\"MNJZ2026\"}]}",
  "type": "outbound",
  "wam_id": "wamid.SEED.646555274060",
  "status": "delivered",
  "media": null,
  "logs": [],
  "user": {
    "full_name": "غاليه الدوسري"
  }
}
```

### 5.2 `image`

```json
{"type":"image","image":{"caption":"الفاتورة"}}
```

| العمود | القيمة | nullable |
|---|---|---|
| `image` | object | نعم |
| `image.caption` | التعليق — قد يصل `""` أو `"null"` (عاملها فارغة) | **نعم غالباً** |
| `image.mime_type` | صادر فقط؛ استخدم `media.type` بدله | نعم |
| `buttons` | نفس جدول text | نعم (غائب غالباً) |

الملف: `value.media.path`.


**مثال كامل — صورة واردة بتعليق:**

```json
{
  "id": 3279812,
  "uuid": "2325bf4b-53b7-4899-a64b-4a54b29b187e",
  "contact_uuid": "4895c0cc-0366-4650-8aa9-ff83e6e36160",
  "contact_id": 839286,
  "is_new_contact": false,
  "phone": "+966500000000",
  "formatted_phone_number": "+966 50 000 0000",
  "organization_id": 211,
  "latest_chat_created_at": "2026-08-24 16:15:48",
  "is_blocked": 0,
  "is_favorite": 0,
  "contact_full_name": "اختبار أنواع الرسائل",
  "unread_messages_count": 1,
  "created_at": "2026-08-24 18:21:48",
  "deleted_at": null,
  "metadata": "{\"type\":\"image\",\"image\":{\"caption\":\"الفاتورة المرفقة\"}}",
  "type": "inbound",
  "wam_id": "wamid.SEED.7964BF61FE2B",
  "status": "delivered",
  "media": {
    "type": "image/jpeg",
    "size": "6080",
    "path": "http://127.0.0.1:8000/media/public/seed-message-types/photo.jpg",
    "name": "N/A"
  },
  "logs": [],
  "user": null
}
```

### 5.3 `video`

```json
{"type":"video","video":{"caption":null}}
```

| العمود | القيمة | nullable |
|---|---|---|
| `video` | object | نعم |
| `video.caption` | التعليق | **نعم غالباً** |
| `buttons` | نفس جدول text | نعم (غائب غالباً) |
| `transcode_retry_status` | `"retrying"` / `"failed"` — أثناء إعادة الإرسال | نعم (غائب غالباً) |
| `transcode_retry_count` | `1` | نعم (غائب غالباً) |

`retrying` ⇒ اعرض «يُعاد الإرسال» لا «فشل».


**مثال كامل — فيديو وارد:**

```json
{
  "id": 3279815,
  "uuid": "8a30716a-d0c8-47b2-8bf2-7e5ae854289b",
  "contact_uuid": "4895c0cc-0366-4650-8aa9-ff83e6e36160",
  "contact_id": 839286,
  "is_new_contact": false,
  "phone": "+966500000000",
  "formatted_phone_number": "+966 50 000 0000",
  "organization_id": 211,
  "latest_chat_created_at": "2026-08-24 16:15:48",
  "is_blocked": 0,
  "is_favorite": 0,
  "contact_full_name": "اختبار أنواع الرسائل",
  "unread_messages_count": 1,
  "created_at": "2026-08-24 18:27:48",
  "deleted_at": null,
  "metadata": "{\"type\":\"video\",\"video\":{\"caption\":\"شاهد المشكلة في الفيديو\"}}",
  "type": "inbound",
  "wam_id": "wamid.SEED.27440AD5C9D1",
  "status": "delivered",
  "media": {
    "type": "video/mp4",
    "size": "43136",
    "path": "http://127.0.0.1:8000/media/public/seed-message-types/clip.mp4",
    "name": "N/A"
  },
  "logs": [],
  "user": null
}
```


**مثال كامل — فيديو صادر فشل وتُعاد محاولته:**

```json
{
  "id": 3279816,
  "uuid": "92bfbd9e-3d1f-4633-a219-c99e4eb1a636",
  "contact_uuid": "4895c0cc-0366-4650-8aa9-ff83e6e36160",
  "contact_id": 839286,
  "is_new_contact": false,
  "phone": "+966500000000",
  "formatted_phone_number": "+966 50 000 0000",
  "organization_id": 211,
  "latest_chat_created_at": "2026-08-24 16:15:48",
  "is_blocked": 0,
  "is_favorite": 0,
  "contact_full_name": "اختبار أنواع الرسائل",
  "unread_messages_count": 1,
  "created_at": "2026-08-24 18:29:48",
  "deleted_at": null,
  "metadata": "{\"type\":\"video\",\"video\":{\"caption\":null},\"transcode_retry_status\":\"retrying\",\"transcode_retry_count\":1}",
  "type": "outbound",
  "wam_id": "wamid.SEED.658FC08C38FF",
  "status": "failed",
  "media": {
    "type": "video/mp4",
    "size": "43136",
    "path": "http://127.0.0.1:8000/media/public/seed-message-types/clip.mp4",
    "name": "clip.mp4"
  },
  "logs": [
    {
      "metadata": "{\"id\":\"wamid.SEED.658FC08C38FF\",\"status\":\"failed\",\"errors\":[{\"code\":131053,\"title\":\"Media upload error\"}]}"
    }
  ],
  "user": {
    "full_name": "غاليه الدوسري"
  }
}
```

### 5.4 `audio`

```json
{"type":"audio","audio":{"voice":true}}
```

| العمود | القيمة | nullable |
|---|---|---|
| `audio` | object | نعم |
| `audio.voice` | `true` = ملاحظة صوتية، غيرها = ملف صوتي | **نعم** |
| `audio.mime_type` | صادر فقط | نعم |
| `transcript` | تفريغ نصّي — على مستوى `metadata` لا داخل `audio` | نعم (غائب غالباً) |

لا يوجد caption للصوت إطلاقاً.


**مثال كامل — ملاحظة صوتية واردة:**

```json
{
  "id": 3279817,
  "uuid": "11abbb23-f432-4356-a919-37661e4c096d",
  "contact_uuid": "4895c0cc-0366-4650-8aa9-ff83e6e36160",
  "contact_id": 839286,
  "is_new_contact": false,
  "phone": "+966500000000",
  "formatted_phone_number": "+966 50 000 0000",
  "organization_id": 211,
  "latest_chat_created_at": "2026-08-24 16:15:48",
  "is_blocked": 0,
  "is_favorite": 0,
  "contact_full_name": "اختبار أنواع الرسائل",
  "unread_messages_count": 1,
  "created_at": "2026-08-24 18:31:48",
  "deleted_at": null,
  "metadata": "{\"type\":\"audio\",\"audio\":{\"voice\":true}}",
  "type": "inbound",
  "wam_id": "wamid.SEED.ACD1AB5D8487",
  "status": "delivered",
  "media": {
    "type": "audio/ogg",
    "size": "37671",
    "path": "http://127.0.0.1:8000/media/public/seed-message-types/voice.ogg",
    "name": "N/A"
  },
  "logs": [],
  "user": null
}
```

### 5.5 `document`

```json
{"type":"document","document":{"filename":"invoice.pdf"}}
```

| العمود | القيمة | nullable |
|---|---|---|
| `document` | object | نعم |
| `document.filename` | اسم الملف (وارد) | نعم |
| `document.caption` | صادر فقط | **نعم دائماً في الوارد** |
| `document.mime_type` | صادر فقط | نعم |

الاسم للعرض: `media.name` ثم `document.filename`.


**مثال كامل — مستند وارد:**

```json
{
  "id": 3279819,
  "uuid": "d47ff47c-2006-4268-b9c1-1262e3305c6c",
  "contact_uuid": "4895c0cc-0366-4650-8aa9-ff83e6e36160",
  "contact_id": 839286,
  "is_new_contact": false,
  "phone": "+966500000000",
  "formatted_phone_number": "+966 50 000 0000",
  "organization_id": 211,
  "latest_chat_created_at": "2026-08-24 16:15:48",
  "is_blocked": 0,
  "is_favorite": 0,
  "contact_full_name": "اختبار أنواع الرسائل",
  "unread_messages_count": 1,
  "created_at": "2026-08-24 18:35:48",
  "deleted_at": null,
  "metadata": "{\"type\":\"document\",\"document\":{\"filename\":\"invoice.pdf\"}}",
  "type": "inbound",
  "wam_id": "wamid.SEED.B921BE2CA460",
  "status": "delivered",
  "media": {
    "type": "application/pdf",
    "size": "544",
    "path": "http://127.0.0.1:8000/media/public/seed-message-types/offer.pdf",
    "name": "invoice.pdf"
  },
  "logs": [],
  "user": null
}
```

### 5.6 `sticker`

```json
{"type":"sticker","sticker":null}
```

| العمود | القيمة | nullable |
|---|---|---|
| `sticker` | **دائماً فارغ عملياً** — لا تقرأ داخله | **نعم (الحالة الطبيعية)** |

المحتوى كله = `media.path` (webp).


**مثال كامل — ملصق وارد:**

```json
{
  "id": 3279821,
  "uuid": "8a1217d5-43ca-4cdc-8fc3-87cca911beb5",
  "contact_uuid": "4895c0cc-0366-4650-8aa9-ff83e6e36160",
  "contact_id": 839286,
  "is_new_contact": false,
  "phone": "+966500000000",
  "formatted_phone_number": "+966 50 000 0000",
  "organization_id": 211,
  "latest_chat_created_at": "2026-08-24 16:15:48",
  "is_blocked": 0,
  "is_favorite": 0,
  "contact_full_name": "اختبار أنواع الرسائل",
  "unread_messages_count": 1,
  "created_at": "2026-08-24 18:39:48",
  "deleted_at": null,
  "metadata": "{\"type\":\"sticker\",\"sticker\":null}",
  "type": "inbound",
  "wam_id": "wamid.SEED.29F49D28E9D1",
  "status": "delivered",
  "media": {
    "type": "image/webp",
    "size": "2052",
    "path": "http://127.0.0.1:8000/media/public/seed-message-types/sticker.webp",
    "name": "N/A"
  },
  "logs": [],
  "user": null
}
```

### 5.7 `location`

```json
{"type":"location","location":{"latitude":21.485811,"longitude":39.192505}}
```

| العمود | القيمة | nullable |
|---|---|---|
| `location` | object | نادراً نعم |
| `location.latitude` | رقم — **قد يصل int أو double** | لا (بدونه لا خريطة) |
| `location.longitude` | رقم | لا |
| `location.name` | اسم المكان | **نعم غالباً** |
| `location.address` | العنوان | **نعم غالباً** |
| `location.url` | رابط خرائط (وارد أحياناً) | **نعم غالباً** |
| `context.id` | wamid رسالة «طلب الموقع» التي يردّ عليها | نعم (غائب غالباً) |

لا يوجد `media` لهذا النوع.


**مثال كامل — موقع وارد ردّاً على طلب موقع:**

```json
{
  "id": 3279822,
  "uuid": "14a0e319-de47-47dd-b6c1-8fee27111085",
  "contact_uuid": "4895c0cc-0366-4650-8aa9-ff83e6e36160",
  "contact_id": 839286,
  "is_new_contact": false,
  "phone": "+966500000000",
  "formatted_phone_number": "+966 50 000 0000",
  "organization_id": 211,
  "latest_chat_created_at": "2026-08-24 16:15:48",
  "is_blocked": 0,
  "is_favorite": 0,
  "contact_full_name": "اختبار أنواع الرسائل",
  "unread_messages_count": 1,
  "created_at": "2026-08-24 18:41:48",
  "deleted_at": null,
  "metadata": "{\"type\":\"location\",\"location\":{\"latitude\":21.485811,\"longitude\":39.192505,\"name\":\"فرع الروضة\",\"address\":\"الروضة، جدة\",\"url\":\"https://maps.google.com/?q=21.485811,39.192505\"},\"context\":{\"id\":\"wamid.SEED.LOCREQ\"}}",
  "type": "inbound",
  "wam_id": "wamid.SEED.1E65D76BC9A0",
  "status": "delivered",
  "media": null,
  "logs": [],
  "user": null
}
```


**مثال كامل — موقع صادر:**

```json
{
  "id": 3279823,
  "uuid": "02906abb-a229-4371-b1b5-809925e728a4",
  "contact_uuid": "4895c0cc-0366-4650-8aa9-ff83e6e36160",
  "contact_id": 839286,
  "is_new_contact": false,
  "phone": "+966500000000",
  "formatted_phone_number": "+966 50 000 0000",
  "organization_id": 211,
  "latest_chat_created_at": "2026-08-24 16:15:48",
  "is_blocked": 0,
  "is_favorite": 0,
  "contact_full_name": "اختبار أنواع الرسائل",
  "unread_messages_count": 1,
  "created_at": "2026-08-24 18:43:48",
  "deleted_at": null,
  "metadata": "{\"type\":\"location\",\"location\":{\"latitude\":24.774265,\"longitude\":46.738586}}",
  "type": "outbound",
  "wam_id": "wamid.SEED.6609A6F83775",
  "status": "delivered",
  "media": null,
  "logs": [],
  "user": {
    "full_name": "غاليه الدوسري"
  }
}
```

### 5.8 `contacts`

```json
{"type":"contacts","contacts":[{"name":{"formatted_name":"محمد أحمد"},"phones":[{"phone":"+9665X","wa_id":"9665X","type":"CELL"}]}]}
```

| العمود | القيمة | nullable |
|---|---|---|
| `contacts` | array (قد تحوي أكثر من جهة) | **نعم** (`null` أو `[]`) |
| `contacts[i].name.formatted_name` | الاسم الجاهز | نعم |
| `contacts[i].name.first_name` / `last_name` | الاحتياطي | نعم |
| `contacts[i].phones[j].phone` | الرقم | نعم |
| `contacts[i].phones[j].wa_id` | رقم واتساب بلا `+` | نعم |
| `contacts[i].phones[j].type` | `CELL` / `WORK` | نعم |
| `contacts[i].emails[j].email` | البريد | نعم |
| `contacts[i].org.company` | الشركة | نعم |
| `contacts[i].addresses` / `urls` / `birthday` | كما وصلت | نعم |
| `vcard` | **محذوف — لا يصل** | — |


**مثال كامل — مشاركة جهتَي اتصال:**

```json
{
  "id": 3279824,
  "uuid": "c2b33afc-f634-471b-9557-a4cabce136e5",
  "contact_uuid": "4895c0cc-0366-4650-8aa9-ff83e6e36160",
  "contact_id": 839286,
  "is_new_contact": false,
  "phone": "+966500000000",
  "formatted_phone_number": "+966 50 000 0000",
  "organization_id": 211,
  "latest_chat_created_at": "2026-08-24 16:15:48",
  "is_blocked": 0,
  "is_favorite": 0,
  "contact_full_name": "اختبار أنواع الرسائل",
  "unread_messages_count": 1,
  "created_at": "2026-08-24 18:45:48",
  "deleted_at": null,
  "metadata": "{\"type\":\"contacts\",\"contacts\":[{\"name\":{\"formatted_name\":\"محمد أحمد\",\"first_name\":\"محمد\",\"last_name\":\"أحمد\",\"middle_name\":null,\"prefix\":null,\"suffix\":null},\"phones\":[{\"phone\":\"+966551112233\",\"wa_id\":\"966551112233\",\"type\":\"CELL\"}],\"emails\":[{\"email\":\"m@example.com\",\"type\":\"WORK\"}],\"org\":{\"company\":\"شركة النور\",\"department\":null,\"title\":null},\"addresses\":[],\"urls\":[],\"birthday\":null},{\"name\":{\"first_name\":\"سالم\",\"last_name\":null},\"phones\":[{\"wa_id\":\"966554445566\"}]}]}",
  "type": "inbound",
  "wam_id": "wamid.SEED.F50D9B8CDC8D",
  "status": "delivered",
  "media": null,
  "logs": [],
  "user": null
}
```

### 5.9 `interactive` — وارد فقط (ردّ العميل على زر/قائمة)

```json
{"type":"interactive","interactive":{"type":"button_reply","button_reply":{"id":"btn_yes","title":"أوافق"}}}
```

| العمود | القيمة | nullable |
|---|---|---|
| `interactive` | object | **نعم** |
| `interactive.type` | `button_reply` / `list_reply` / غيرها | نعم |
| `interactive.button_reply.id` | معرّف الزر | نعم |
| `interactive.button_reply.title` | النصّ المعروض | نعم |
| `interactive.list_reply.id` | معرّف العنصر | نعم |
| `interactive.list_reply.title` | العنوان | نعم |
| `interactive.list_reply.description` | الوصف | **نعم غالباً** |

نوع غير معروف ⇒ اعرض «رسالة تفاعلية».


**مثال كامل — ردّ على زر:**

```json
{
  "id": 3279826,
  "uuid": "02566310-1665-4ae2-b9c3-d26b554721ac",
  "contact_uuid": "4895c0cc-0366-4650-8aa9-ff83e6e36160",
  "contact_id": 839286,
  "is_new_contact": false,
  "phone": "+966500000000",
  "formatted_phone_number": "+966 50 000 0000",
  "organization_id": 211,
  "latest_chat_created_at": "2026-08-24 16:15:48",
  "is_blocked": 0,
  "is_favorite": 0,
  "contact_full_name": "اختبار أنواع الرسائل",
  "unread_messages_count": 1,
  "created_at": "2026-08-24 18:49:48",
  "deleted_at": null,
  "metadata": "{\"type\":\"interactive\",\"interactive\":{\"type\":\"button_reply\",\"button_reply\":{\"id\":\"btn_yes\",\"title\":\"أوافق\"}}}",
  "type": "inbound",
  "wam_id": "wamid.SEED.F2F0F72F075D",
  "status": "delivered",
  "media": null,
  "logs": [],
  "user": null
}
```

### 5.10 `button` — وارد فقط (ردّ على زر قالب)

```json
{"type":"button","button":{"text":"نعم","payload":"YES"}}
```

| العمود | القيمة | nullable |
|---|---|---|
| `button` | object | نعم |
| `button.text` | النصّ المعروض | نعم |
| `button.payload` | القيمة الداخلية | نعم |


**مثال كامل — ردّ على زر قالب:**

```json
{
  "id": 3279830,
  "uuid": "880a671e-0266-4476-9c90-32ec5637e674",
  "contact_uuid": "4895c0cc-0366-4650-8aa9-ff83e6e36160",
  "contact_id": 839286,
  "is_new_contact": false,
  "phone": "+966500000000",
  "formatted_phone_number": "+966 50 000 0000",
  "organization_id": 211,
  "latest_chat_created_at": "2026-08-24 16:15:48",
  "is_blocked": 0,
  "is_favorite": 0,
  "contact_full_name": "اختبار أنواع الرسائل",
  "unread_messages_count": 1,
  "created_at": "2026-08-24 18:57:48",
  "deleted_at": null,
  "metadata": "{\"type\":\"button\",\"button\":{\"text\":\"نعم\",\"payload\":\"YES\"}}",
  "type": "inbound",
  "wam_id": "wamid.SEED.6A3DDAD9EADE",
  "status": "delivered",
  "media": null,
  "logs": [],
  "user": null
}
```

### 5.11 `system` — وارد (تغيّر رقم العميل)

```json
{"type":"system","system":{"body":"changed to +9665Y","type":"user_changed_number","wa_id":"9665YYYYYYY"}}
```

| العمود | القيمة | nullable |
|---|---|---|
| `system` | object | نعم |
| `system.body` | نصّ جاهز للعرض | نعم |
| `system.type` | `user_changed_number` | نعم |
| `system.wa_id` | الرقم الجديد | نعم |


**مثال كامل — تغيّر رقم العميل:**

```json
{
  "id": 3279831,
  "uuid": "011470af-cff9-4275-89e7-eb1850f4e74f",
  "contact_uuid": "4895c0cc-0366-4650-8aa9-ff83e6e36160",
  "contact_id": 839286,
  "is_new_contact": false,
  "phone": "+966500000000",
  "formatted_phone_number": "+966 50 000 0000",
  "organization_id": 211,
  "latest_chat_created_at": "2026-08-24 16:15:48",
  "is_blocked": 0,
  "is_favorite": 0,
  "contact_full_name": "اختبار أنواع الرسائل",
  "unread_messages_count": 1,
  "created_at": "2026-08-24 18:59:48",
  "deleted_at": null,
  "metadata": "{\"type\":\"system\",\"system\":{\"body\":\"تم تغيير الرقم إلى +966500000009\",\"type\":\"user_changed_number\",\"wa_id\":\"966500000009\"}}",
  "type": "inbound",
  "wam_id": "wamid.SEED.BDAC9DBB7B2E",
  "status": "delivered",
  "media": null,
  "logs": [],
  "user": null
}
```

### 5.12 `edit` — وارد (العميل عدّل رسالته)

```json
{"type":"edit","edit":{"original_message_id":"wamid.…","message":{"type":"text","text":{"body":"النصّ الجديد"}}}}
```

| العمود | القيمة | nullable |
|---|---|---|
| `edit` | object | نعم |
| `edit.original_message_id` | wamid الأصلية | نعم |
| `edit.message.text.body` | النصّ بعد التعديل | نعم |


**مثال كامل — رسالة معدَّلة:**

```json
{
  "id": 3279832,
  "uuid": "9d5be5ef-7a3d-4086-abd2-81f1667ecacd",
  "contact_uuid": "4895c0cc-0366-4650-8aa9-ff83e6e36160",
  "contact_id": 839286,
  "is_new_contact": false,
  "phone": "+966500000000",
  "formatted_phone_number": "+966 50 000 0000",
  "organization_id": 211,
  "latest_chat_created_at": "2026-08-24 16:15:48",
  "is_blocked": 0,
  "is_favorite": 0,
  "contact_full_name": "اختبار أنواع الرسائل",
  "unread_messages_count": 1,
  "created_at": "2026-08-24 19:01:48",
  "deleted_at": null,
  "metadata": "{\"type\":\"edit\",\"edit\":{\"original_message_id\":\"wamid.SEED.ORIGINAL\",\"message\":{\"type\":\"text\",\"text\":{\"body\":\"النصّ بعد التعديل\"}}}}",
  "type": "inbound",
  "wam_id": "wamid.SEED.3760B01D68F7",
  "status": "delivered",
  "media": null,
  "logs": [],
  "user": null
}
```

### 5.13 `revoke` — وارد (حذف للجميع)

```json
{"type":"revoke","revoke":{"original_message_id":"wamid.…"}}
```

| العمود | القيمة | nullable |
|---|---|---|
| `revoke` | object | نعم |
| `revoke.original_message_id` | wamid الأصلية | نعم |

لا محتوى — اعرض «تم حذف هذه الرسالة». (غير `value.deleted_at` وهو حذف من داخل نظامنا.)


**مثال كامل — رسالة محذوفة للجميع:**

```json
{
  "id": 3279833,
  "uuid": "f25f743f-c5fd-4262-807b-ffcc5c18873c",
  "contact_uuid": "4895c0cc-0366-4650-8aa9-ff83e6e36160",
  "contact_id": 839286,
  "is_new_contact": false,
  "phone": "+966500000000",
  "formatted_phone_number": "+966 50 000 0000",
  "organization_id": 211,
  "latest_chat_created_at": "2026-08-24 16:15:48",
  "is_blocked": 0,
  "is_favorite": 0,
  "contact_full_name": "اختبار أنواع الرسائل",
  "unread_messages_count": 1,
  "created_at": "2026-08-24 19:03:48",
  "deleted_at": null,
  "metadata": "{\"type\":\"revoke\",\"revoke\":{\"original_message_id\":\"wamid.SEED.ORIGINAL\"}}",
  "type": "inbound",
  "wam_id": "wamid.SEED.2B5A32CD3A94",
  "status": "delivered",
  "media": null,
  "logs": [],
  "user": null
}
```

### 5.14 `unsupported` — وارد

```json
{"type":"unsupported","errors":[{"code":131051,"error_data":{"details":"Message type is not currently supported."}}]}
```

| العمود | القيمة | nullable |
|---|---|---|
| `errors` | array | **نعم** (احرسها قبل `[0]`) |
| `errors[0].code` | رقم خطأ Meta | نعم |
| `errors[0].error_data.details` | وصف الخطأ | نعم |

---


**مثال كامل — رسالة غير مدعومة:**

```json
{
  "id": 3279834,
  "uuid": "ef50c30c-c6ce-43cf-8b0f-cd51dfa6f709",
  "contact_uuid": "4895c0cc-0366-4650-8aa9-ff83e6e36160",
  "contact_id": 839286,
  "is_new_contact": false,
  "phone": "+966500000000",
  "formatted_phone_number": "+966 50 000 0000",
  "organization_id": 211,
  "latest_chat_created_at": "2026-08-24 16:15:48",
  "is_blocked": 0,
  "is_favorite": 0,
  "contact_full_name": "اختبار أنواع الرسائل",
  "unread_messages_count": 1,
  "created_at": "2026-08-24 19:05:48",
  "deleted_at": null,
  "metadata": "{\"type\":\"unsupported\",\"errors\":[{\"code\":131051,\"title\":\"Message type not supported\",\"error_data\":{\"details\":\"Message type is not currently supported.\"}}]}",
  "type": "inbound",
  "wam_id": "wamid.SEED.212D7D7B358A",
  "status": "delivered",
  "media": null,
  "logs": [],
  "user": null
}
```

## 6) أنواع لن تصل / غير معروضة

| النوع | الحالة |
|---|---|
| `reaction` | **مستبعَد من كل الاستعلامات والبثّ** — لن يصل التطبيق أبداً |
| `template` | القوالب الصادرة تُخزَّن `text`/`image`/`video`/`document`؛ وصول `template` نادر وغير مدعوم |
| أي نوع جديد من واتساب | يصل خاماً — التعامل معه في `default`: «لا يمكن عرض هذا النوع» |

---

## 7) محادثة اختبار فيها كل الأنواع

لا توجد في الإنتاج جهة اتصال واحدة تجمع الأنواع كلها (أقصى تغطية 9 من 14)، فيوجد seeder يبنيها:

```bash
SEED_ORG_ID=211 SEED_MEDIA_BASE_URL=http://127.0.0.1:8000 \
  php artisan db:seed --class=ChatMessageTypesSeeder
```

- ينشئ جهة اتصال `+966500000000` ويضع عليها **32 رسالة** تغطّي الـ14 نوعاً + الحالات الحافّة.
- يولّد ملفات ميديا حقيقية (jpg · mp4 · ogg · pdf · webp) تحت `storage/app/public/seed-message-types`.
- إعادة التشغيل تمسح وتبني من جديد (لا تُكرّر).
- متغيّرات: `SEED_ORG_ID` · `SEED_TEST_PHONE` · `SEED_MEDIA_BASE_URL`.

حالات مقصودة داخل المحادثة يجب أن يتعامل معها التطبيق:

| الحالة | المتوقَّع |
|---|---|
| `image` بلا `media` | «المحتوى غير متاح» لا انهيار |
| `video` بـ`transcode_retry_status: retrying` | «يُعاد الإرسال» لا «فشل» |
| `contacts: []` | يصل `null` — يُعامل كقائمة فارغة |
| `interactive.type = nfm_reply` | فقاعة عامّة «رسالة تفاعلية» |
| `unsupported` بلا `errors` | نصّ عام بلا قراءة `errors[0]` |
| `reaction` | **لا يظهر في رد الـAPI إطلاقاً** |
| `order` (نوع مجهول) | يقع في `default` |
| رسالة بـ`deleted_at` | تُعرض كمحذوفة |

---

## 8) إشعار Firebase (FCM) — للتفريق فقط

يُرسَل بجانب البثّ لا بدلاً منه (`SendNewChatPushNotificationJob`)، وشكله مختلف تماماً:

```json
{
  "message": {
    "token": "<device_token>",
    "notification": {
      "title": "اختبار أنواع الرسائل",
      "body": "📷 صورة: الفاتورة المرفقة"
    },
    "android": { "notification": { "sound": "on_notification.wav", "color": "#0A0A0A" } },
    "apns":    { "payload": { "aps": { "sound": "on_notification.wav" } } },
    "data": {
      "contactFullName": "اختبار أنواع الرسائل",
      "phone": "+966500000000",
      "chatId": "839286",
      "organizationId": "211",
      "createdAt": "2026-08-24 21:15:00",
      "userId": "1234"
    }
  }
}
```

| العمود | القيمة | nullable |
|---|---|---|
| `notification.title` | اسم جهة الاتصال، أو رقمها إن لم يوجد اسم | لا (قد يكون `"Unknown"`) |
| `notification.body` | نصّ الرسالة، أو `<إيموجي> <نوع>: <caption>` للميديا | لا (قد يكون `""`) |
| `data.contactFullName` | نفس الاسم | قد يكون `""` |
| `data.phone` | رقم جهة الاتصال | قد يكون `""` |
| `data.chatId` | **`contact_id` وليس `chat.id`** — نصّ لا رقم | قد يكون `""` |
| `data.organizationId` | نصّ لا رقم | لا |
| `data.createdAt` | وقت إرسال الإشعار لا وقت الرسالة | لا |
| `data.userId` | الموظّف المستلِم للإشعار | لا |

**كل قيم `data` نصوص (strings)** — هذا شرط FCM.

ما **لا** يحمله الإشعار: `metadata` · `media` · `wam_id` · `status` · `logs` · معرّف الرسالة نفسه.
الاستخدام الصحيح: الإشعار يفتح المحادثة على `chatId` (= contact_id)، والمحتوى يأتي من `list-messages-from-uuid-to-end` أو من Pusher.
