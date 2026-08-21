# أنواع الرسائل لتطبيق الموبايل

تقرير لمطور التطبيق: شكل كل نوع كما يصل من الـ API / Pusher، والحقول التي قد تكون `null` أو غائبة.

المصدر: التخزين في `chats.metadata` + تنسيق `ApiController` / `ChatBroadcastPayloadBuilder`.

---

## 1) قواعد يجب الالتزام بها

### نوع المحتوى ≠ اتجاه الرسالة

| الحقل | المعنى | القيم |
|---|---|---|
| `value.type` | اتجاه الرسالة | `"inbound"` أو `"outbound"` |
| `JSON.parse(value.metadata).type` | نوع المحتوى | `"interactive"` / `"image"` / `"video"` / … |

الفرع في الواجهة يكون دائمًا على **`metadata.type`** بعد فك JSON، وليس على `value.type`.

### `metadata` نص JSON وليس object

يصل الحقل **string**. يجب `jsonDecode` قبل القراءة.

```dart
final Map<String, dynamic> meta =
    jsonDecode(message.metadata ?? '{}') as Map<String, dynamic>;
final String contentType = meta['type'] as String? ?? '';
```

لا تفترض أن المفتاح الخاص بالنوع موجود أو أنه Map. قد يكون:

- object فيه حقول
- `null` (الـ API يحوّل المصفوفة الفارغة `[]` إلى `null`)
- غائب تمامًا

### الميديا ليست داخل `metadata`

للأنواع `image` / `video` / `audio` / `document` / `sticker`:

- الملف في `value.media` (`path`, `name`, `type` mime, `size`)
- `metadata` يحمل فقط caption / filename / voice
- لا تعتمد على `id` / `url` / `sha256` / `mime_type` داخل كتلة الميديا في `metadata` (الوارد يُزال قبل الحفظ)

`media` نفسه قد يكون `null` (التحميل لم يكتمل بعد، أو فشل، أو النوع بلا ملف مثل location).

### سلسلة `"null"` ليست قيمة صالحة

بعض الصفوف القديمة تحمل caption = `"null"` كنص. اعتبرها فارغة مثل الداشبورد:

```dart
String captionOf(dynamic value) {
  if (value == null) return '';
  final text = value.toString().trim();
  if (text.isEmpty || text.toLowerCase() == 'null') return '';
  return text;
}
```

---

## 2) غلاف الرسالة (كما يصل)

### REST — مزامنة المحادثات (v1)

كل عنصر في `messages`:

```json
{
  "type": "chat",
  "value": { "...حقول الرسالة..." }
}
```

### Pusher — `NewChatEvent`

```json
{
  "chat": [
    {
      "type": "chat",
      "value": { "...حقول الرسالة..." },
      "tempMessageId": "اختياري — عند الإرسال من التطبيق"
    }
  ]
}
```

قد يصل الغلاف بدون مصفوفة: `{ "type": "chat", "value": { ... } }`.

### حقول `value` المشتركة

| الحقل | النوع | قد يكون null؟ | ملاحظات |
|---|---|---|---|
| `id` | int | لا (إن وصل بدون id لا يُبث) | معرّف الصف |
| `uuid` | string | نعم | معرّف العميل عند الإرسال من الموبايل |
| `contact_uuid` | string | نعم | v1 فقط |
| `contact_id` | int | نعم | v1 فقط |
| `phone` | string | نعم | v1 — قد يُحذف تحت ضغط حجم Pusher |
| `formatted_phone_number` | string | نعم | v1 |
| `contact_full_name` | string | نعم | v1 — قد يُحذف تحت ضغط Pusher |
| `organization_id` | int | نادرًا | |
| `is_new_contact` | bool | — | v1؛ في المزامنة دائمًا `false` |
| `is_blocked` / `is_favorite` | bool | نعم | |
| `unread_messages_count` | int | — | 0 إذا غاب |
| `latest_chat_created_at` | string | نعم | |
| `created_at` | string | نادرًا | |
| `deleted_at` | string | **نعم غالبًا** | رسالة محذوفة إن وُجد |
| `metadata` | **string JSON** أو `null` | نعم | تحت ضغط Pusher قد يصير `"{}"` ثم `null` |
| `type` | string | — | `"inbound"` / `"outbound"` فقط |
| `wam_id` | string | نعم | معرّف واتساب |
| `status` | string | نعم | فقط: `accepted` · `sent` · `delivered` · `read` · `failed`. `played` تُترجم إلى `delivered`. أي قيمة أخرى تُحذف (`null`) |
| `media` | object | **نعم** | انظر الجدول التالي |
| `logs` | array | — | قد تكون `[]` |
| `user` | object | **نعم دائمًا للوارد** | الصادر: `{ "first_name", "last_name" }` وكلاهما قد ينقص |

في **v2** (`list-messages-from-uuid-to-end-v2`) حقول جهة الاتصال **لا تُكرَّر** داخل الرسالة؛ تأتي مرة واحدة في كائن `contact` فوق المصفوفة.

### `media` عند وجوده

```json
{
  "type": "image/jpeg",
  "size": 245760,
  "path": "https://…",
  "name": "photo.jpg"
}
```

| الحقل | قد يكون null؟ | ملاحظات |
|---|---|---|
| `type` | نعم | MIME مثل `image/jpeg` / `video/mp4` / `audio/ogg` |
| `size` | نعم | بايت |
| `path` | قد يكون `""` | رابط كامل. في البث يُقص إلى **200 بايت** — الروابط الطويلة قد تُقطع |
| `name` | قد يكون `"N/A"` | يُقص إلى **80 بايت**. للملصق/الصوت الوارد غالبًا `N/A` |

عرض الملف: `media.path`. إن كان `media == null` أو `path` فارغًا → «المحتوى غير متاح».

### `logs`

آخر 6 سجلات كحد أقصى:

```json
[
  { "metadata": "{\"id\":\"wamid.xxx\",\"status\":\"delivered\"}" }
]
```

`logs[i].metadata` أيضًا **string**. الحقول المحتفظ بها: `status`, `errors`, `id`.

---

## 3) ملخص الأنواع المطلوبة

| `metadata.type` | اتجاه شائع | أين المحتوى | أين الملف |
|---|---|---|---|
| `interactive` | وارد (رد العميل) | `interactive.button_reply` أو `list_reply` | لا |
| `button` | وارد (زر قالب) | `button.text` / `button.payload` | لا |
| `image` | وارد وصادر | `image.caption` | `media.path` |
| `video` | وارد وصادر | `video.caption` | `media.path` |
| `audio` | وارد وصادر | `audio.voice` + `transcript` | `media.path` |
| `document` | وارد وصادر | `document.filename` | `media.path` + `media.name` |
| `sticker` | وارد وصادر | لا نص | `media.path` |
| `location` | وارد وصادر | `location.latitude/longitude` | لا |
| `contacts` | وارد | `contacts[]` | لا |

---

## 4) التفاصيل + أمثلة

في الأمثلة أدناه `metadata` معروض **بعد** `jsonDecode`. على السلك هو string.

---

### 4.1 `interactive` — رد على أزرار / قائمة

يصل عندما يضغط العميل زر رد تفاعلي أو عنصر قائمة. **ليس** الرسالة التفاعلية الصادرة من النشاط (تلك تُحفظ حاليًا كـ `type: "text"`).

#### أ) رد زر — `button_reply`

```json
{
  "type": "interactive",
  "interactive": {
    "type": "button_reply",
    "button_reply": {
      "id": "btn_yes",
      "title": "أوافق"
    }
  }
}
```

| المسار | مطلوب؟ | قد يكون null؟ |
|---|---|---|
| `interactive` | يفترض وجوده | **نعم** — إن كان فارغًا يصبح `null` |
| `interactive.type` | للتفريع | نعم |
| `interactive.button_reply` | إذا النوع `button_reply` | نعم |
| `interactive.button_reply.id` | لا للعرض | نعم |
| `interactive.button_reply.title` | النص المعروض | نعم — اعرض عنوانًا احتياطيًا |

**للعرض:** `interactive?.button_reply?.title`

#### ب) رد قائمة — `list_reply`

```json
{
  "type": "interactive",
  "interactive": {
    "type": "list_reply",
    "list_reply": {
      "id": "opt_2",
      "title": "فرع جدة",
      "description": "التوصيل خلال ساعتين"
    }
  }
}
```

| المسار | قد يكون null؟ |
|---|---|
| `interactive.list_reply` | نعم |
| `interactive.list_reply.id` | نعم |
| `interactive.list_reply.title` | نعم |
| `interactive.list_reply.description` | **نعم غالبًا** — العنصر قد بلا وصف |

**للعرض:** العنوان، ثم الوصف إن وُجد.

#### ج) أنواع تفاعلية أخرى

الكتلة تُحفظ كما وصلت من واتساب. قد ترى:

- `nfm_reply` (واتساب فلو)
- أشكال غير معروفة لاحقًا

إن لم يكن `button_reply` ولا `list_reply`: اعرض فقاعة «رسالة تفاعلية» ولا تلمس حقولًا غير موجودة.

#### الصادر من النشاط

إرسال أزرار/قائمة يُحفظ كـ:

```json
{ "type": "text", "text": { "body": "نص الجسم" } }
```

طلب الموقع الصادر:

```json
{
  "type": "text",
  "text": { "body": "شاركنا موقعك" },
  "location_request": true
}
```

`location_request` اختياري. العملاء القدامى يتجاهلونه ويعرضون نصًا عاديًا.

---

### 4.2 `button` — رد على زر قالب (Quick Reply)

مختلف عن `interactive`. العميل ضغط زر **قالب** (template).

```json
{
  "type": "button",
  "button": {
    "text": "نعم",
    "payload": "YES"
  }
}
```

| المسار | قد يكون null؟ | ملاحظات |
|---|---|---|
| `button` | نعم (`null` إن فُرغ) | |
| `button.text` | نعم | النص المعروض |
| `button.payload` | نعم | القيمة الداخلية؛ قد تساوي النص أو تختلف |

**للعرض:** `button?.text ?? button?.payload ?? ''`

لا تخلط بين هذا و`metadata.buttons` (مصفوفة أزرار القالب **الصادر** على رسالة `text`/`image`).

---

### 4.3 `image`

```json
{
  "type": "image",
  "image": {
    "caption": "الفاتورة المرفقة"
  }
}
```

بدون تعليق (الوارد بعد التقليص):

```json
{
  "type": "image",
  "image": {
    "caption": null
  }
}
```

الصادر قد يحمل حقولًا إضافية لا تستخدم للعرض:

```json
{
  "id": "wamid.HBgN...",
  "type": "image",
  "image": {
    "mime_type": "image/jpeg",
    "caption": "اختيارية"
  }
}
```

ومع أزرار قالب:

```json
{
  "type": "image",
  "image": { "caption": "نص القالب" },
  "buttons": [
    { "type": "URL", "text": "افتح الموقع", "value": "https://…" },
    { "type": "QUICK_REPLY", "text": "تواصل معنا", "value": null }
  ]
}
```

| المسار | قد يكون null؟ |
|---|---|
| `image` | نعم |
| `image.caption` | **نعم غالبًا** + قد يكون `""` أو `"null"` |
| `image.mime_type` | نعم — لا تعتمد عليه؛ MIME في `media.type` |
| `buttons` | **نعم غالبًا غائب** | فقط قوالب صادرة |
| `media` | **نعم** حتى يكتمل التحميل |
| `media.path` | نعم / فارغ |

**للعرض:** صورة من `media.path` + `captionOf(image?.caption)`.

---

### 4.4 `video`

نفس صورة الصورة.

```json
{
  "type": "video",
  "video": {
    "caption": "شاهد الفيديو"
  }
}
```

بدون تعليق:

```json
{ "type": "video", "video": { "caption": null } }
```

| المسار | قد يكون null؟ |
|---|---|
| `video` | نعم |
| `video.caption` | **نعم غالبًا** |
| `media` / `media.path` | نعم |

الصادر قيد إعادة الترميز قد يحمل:

```json
{
  "type": "video",
  "video": { "caption": null },
  "transcode_retry_status": "retrying",
  "transcode_retry_count": 1
}
```

`transcode_retry_status`: `"retrying"` | `"failed"` | غائب. عند `retrying` أظهر حالة إعادة المحاولة لا فشلًا نهائيًا.

---

### 4.5 `document`

**وارد** (بعد التقليص) — يُحفظ `filename` فقط، **بدون caption**:

```json
{
  "type": "document",
  "document": {
    "filename": "invoice.pdf"
  }
}
```

بدون اسم:

```json
{ "type": "document", "document": { "filename": null } }
```

**صادر** قد يشمل caption:

```json
{
  "id": "wamid.…",
  "type": "document",
  "document": {
    "mime_type": "application/pdf",
    "caption": "العقد الموقّع"
  }
}
```

اسم الملف للعرض من **`media.name`** أولًا، ثم `document.filename`.

| المسار | قد يكون null؟ |
|---|---|
| `document` | نعم |
| `document.filename` | نعم (الوارد بلا اسم) |
| `document.caption` | **نعم دائمًا تقريبًا في الوارد** — غير محفوظ عند التقليص |
| `media.name` | نعم / `"N/A"` |
| `media.type` | نعم — MIME لأيقونة PDF/DOC/… |
| `media.size` | نعم |
| `media.path` | نعم |

---

### 4.6 `audio`

رسالة صوتية أو ملف صوتي.

ملاحظة صوتية (وارد):

```json
{
  "type": "audio",
  "audio": {
    "voice": true
  }
}
```

ملف صوتي عادي (بعد التقليص إن غاب `voice`):

```json
{
  "type": "audio",
  "audio": {
    "voice": null
  }
}
```

مع تفريغ نصي (مستوى `metadata` وليس داخل `audio`):

```json
{
  "type": "audio",
  "audio": { "voice": true },
  "transcript": "السلام عليكم، أريد إلغاء الطلب"
}
```

| المسار | قد يكون null؟ | ملاحظات |
|---|---|---|
| `audio` | نعم | |
| `audio.voice` | **نعم** | `true` = ملاحظة صوتية. `null` / غائب = ملف صوتي |
| `transcript` | **نعم غالبًا** | نص التفريغ إن وُجد |
| `audio.caption` | لا يُرسل | واتساب لا يدعم تعليقًا على الصوت |
| `media` | نعم | |

**للعرض:** مشغّل من `media.path`. إن `voice == true` اعرض أيقونة ملاحظة صوتية. `status: played` من واتساب يصل للتطبيق كـ `delivered`.

---

### 4.7 `sticker`

لا نص للعرض. الملف في `media`.

بعد مرور الرسالة من الـ API غالبًا:

```json
{
  "type": "sticker",
  "sticker": null
}
```

أحيانًا (صفوف قديمة أو قبل تحويل `[]` → `null`):

```json
{ "type": "sticker", "sticker": [] }
```

أو:

```json
{ "type": "sticker", "sticker": {} }
```

| المسار | قد يكون null؟ |
|---|---|
| `sticker` | **نعم — الحالة الشائعة** |
| `media` / `media.path` | نعم |

**لا تقرأ حقولًا داخل `sticker`.** الفرع على `metadata.type == "sticker"` ثم اعرض `media.path` كصورة صغيرة.

---

### 4.8 `location`

لا يوجد `media`. الإحداثيات داخل `metadata`.

نقطة خام (الحد الأدنى الصالح):

```json
{
  "type": "location",
  "location": {
    "latitude": 21.485811,
    "longitude": 39.192505
  }
}
```

مكان مسمّى (وارد أو صادر):

```json
{
  "type": "location",
  "location": {
    "latitude": 21.485811,
    "longitude": 39.192505,
    "name": "ليديز",
    "address": "الروضة، جدة",
    "url": "https://maps.google.com/?q=21.485811,39.192505"
  }
}
```

رد على «طلب الموقع» — يحمل `context.id` (معرّف رسالة الطلب):

```json
{
  "type": "location",
  "location": {
    "latitude": 21.485811,
    "longitude": 39.192505
  },
  "context": {
    "id": "wamid.HBgMOTY2NTk2NzcxNzE4FQIAEhgJNTMzNjQ5OTc4AA=="
  }
}
```

| المسار | قد يكون null / غائب؟ | ملاحظات |
|---|---|---|
| `location` | نادرًا نعم | إن فُرغ يصبح `null` — لا ترسم خريطة |
| `location.latitude` | يجب وجوده للعرض | رقم (int أو double). الصادر float مقوّم لـ 8 خانات |
| `location.longitude` | يجب وجوده للعرض | نفس الشيء |
| `location.name` | **نعم غالبًا** | يُحذف إن كان فارغًا عند الإرسال |
| `location.address` | **نعم غالبًا** | |
| `location.url` | **نعم غالبًا** | وارد من واتساب أحيانًا؛ الصادر لا يضمّه |
| `context` | **نعم غالبًا غائب** | موجود فقط إن كان ردًا على طلب موقع |
| `context.id` | إن وُجد `context` | wamid رسالة الطلب |

**للعرض:** خريطة على `(latitude, longitude)`. لا تستخدم `name`/`address` كبديل عن الإحداثيات.

افتح الاتجاهات:

`https://www.google.com/maps/search/?api=1&query={lat},{lng}`

---

### 4.9 `contacts` — مشاركة جهات اتصال

مصفوفة. ملف `vcard` **يُحذف** قبل الحفظ (يُبنى من الحقول إن لزم).

```json
{
  "type": "contacts",
  "contacts": [
    {
      "name": {
        "formatted_name": "محمد أحمد",
        "first_name": "محمد",
        "last_name": "أحمد",
        "middle_name": null,
        "prefix": null,
        "suffix": null
      },
      "phones": [
        { "phone": "+9665XXXXXXX", "wa_id": "9665XXXXXXX", "type": "CELL" }
      ],
      "emails": [
        { "email": "m@example.com", "type": "WORK" }
      ],
      "org": {
        "company": "شركة النور",
        "department": null,
        "title": null
      },
      "addresses": [],
      "urls": [],
      "birthday": null
    }
  ]
}
```

قد تصل **عدة** جهات في المصفوفة.

إن كانت المصفوفة فارغة يحوّلها الـ API إلى:

```json
{ "type": "contacts", "contacts": null }
```

| المسار | قد يكون null؟ | ملاحظات |
|---|---|---|
| `contacts` | نعم (`null` أو `[]`) | تعامل `null` كـ `[]` |
| `contacts[i].name` | نعم | |
| `name.formatted_name` | نعم | أفضل اسم للعرض |
| `name.first_name` / `last_name` | نعم | احتياطي: دمجهما |
| `phones` | نعم أو `[]` | |
| `phones[i].phone` | نعم | استخدم `phone ?? wa_id` |
| `phones[i].wa_id` | نعم | رقم واتساب بلا `+` |
| `phones[i].type` | نعم | مثل `CELL` / `WORK` |
| `emails` | نعم أو `[]` | |
| `emails[i].email` | نعم | |
| `org` | نعم | |
| `org.company` | نعم | |
| `addresses` / `urls` / `birthday` | نعم | |
| `vcard` | **لا يصل** | محذوف عمدًا |

**اسم العرض:**

```dart
String contactName(Map c) {
  final name = (c['name'] as Map?) ?? {};
  final formatted = (name['formatted_name'] as String?)?.trim();
  if (formatted != null && formatted.isNotEmpty) return formatted;
  return '${name['first_name'] ?? ''} ${name['last_name'] ?? ''}'.trim();
}
```

---

## 5) كائن الرسالة الكامل — مثال وارد صورة

كما في v1 / Pusher `value`:

```json
{
  "id": 1845221,
  "uuid": null,
  "contact_uuid": "3c1e…",
  "contact_id": 88210,
  "is_new_contact": false,
  "phone": "9665XXXXXXX",
  "formatted_phone_number": "+966 5X XXX XXXX",
  "organization_id": 211,
  "latest_chat_created_at": "2026-08-20 21:10:00",
  "is_blocked": false,
  "is_favorite": false,
  "contact_full_name": "سارة علي",
  "unread_messages_count": 3,
  "created_at": "2026-08-20 21:10:04",
  "deleted_at": null,
  "metadata": "{\"type\":\"image\",\"image\":{\"caption\":\"الفاتورة\"}}",
  "type": "inbound",
  "wam_id": "wamid.HBgN…",
  "status": "delivered",
  "media": {
    "type": "image/jpeg",
    "size": 198433,
    "path": "https://cdn.example.com/uploads/media/received/211/abc.jpg",
    "name": "N/A"
  },
  "logs": [],
  "user": null
}
```

نفس الرسالة **قبل** اكتمال تحميل الميديا: كل شيء كما فوق مع `"media": null`. سيأتي بث لاحق بنفس `id` بعد إرفاق الملف.

---

## 6) تفريع العرض المقترح (Dart)

```dart
final meta = jsonDecode(message.metadata ?? '{}') as Map<String, dynamic>;
final kind = meta['type'] as String? ?? '';
final block = meta[kind]; // قد يكون Map أو List أو null

switch (kind) {
  case 'interactive':
    final i = block is Map ? block : const {};
    switch (i['type']) {
      case 'button_reply':
        showText(i['button_reply']?['title']);
      case 'list_reply':
        showText(i['list_reply']?['title']);
        showSub(i['list_reply']?['description']);
      default:
        showPlaceholder('رسالة تفاعلية');
    }
  case 'button':
    final b = block is Map ? block : const {};
    showText(b['text'] ?? b['payload']);
  case 'image':
    showImage(message.media?.path);
    showCaption(captionOf((block as Map?)?['caption']));
  case 'video':
    showVideo(message.media?.path);
    showCaption(captionOf((block as Map?)?['caption']));
  case 'audio':
    showAudio(message.media?.path, isVoice: (block as Map?)?['voice'] == true);
    showTranscript(meta['transcript']);
  case 'document':
    showFile(
      url: message.media?.path,
      name: message.media?.name ?? (block as Map?)?['filename'],
      mime: message.media?.type,
      size: message.media?.size,
    );
  case 'sticker':
    showSticker(message.media?.path);
  case 'location':
    final loc = block is Map ? block : const {};
    final lat = (loc['latitude'] as num?)?.toDouble();
    final lng = (loc['longitude'] as num?)?.toDouble();
    if (lat != null && lng != null) showMap(lat, lng, loc['name'], loc['address']);
  case 'contacts':
    final list = block is List ? block : const [];
    showContactCards(list.whereType<Map>().toList());
  default:
    // text / system / edit / revoke / template / unknown
    break;
}
```

لا تستخدم `!` على أي حقل متداخل. التطبيق ينهار حاليًا على `createdAt!` إن وصلت حمولة فارغة — الخادم لم يعد يبث رسائل بلا `id`، لكن الحقول الداخلية تبقى اختيارية.

---

## 7) ما الذي لا يصل / يُتجاهل

| النوع | السلوك |
|---|---|
| `reaction` | **لا يُرسل** للتطبيق (يُستبعد من الاستعلام والبث) |
| `metadata.id` / `url` / `sha256` داخل الميديا الواردة | محذوفة؛ الملف في `media` |
| `contacts[].vcard` | محذوف |
| حالات واتساب غير المدعومة | `played` → `delivered`؛ غيرها → `status: null` |

أنواع أخرى قد تصل (`text`, `template`, `system`, `edit`, `revoke`, `unsupported`) خارج نطاق هذا التقرير، لكنها تستخدم نفس الغلاف.

---

## 8) قائمة تحقق سريعة للمطور

- [ ] فك `metadata` كـ JSON string في كل رسالة وكل `log`
- [ ] التفريع على `metadata.type` لا على `value.type`
- [ ] `media == null` → محتوى غير متاح، لا crash
- [ ] `image`/`video`/`document`/`audio`/`sticker`: الكتلة الداخلية قد تكون `null`
- [ ] `caption` يعامل `null` و`""` و`"null"` كفارغ
- [ ] `interactive.list_reply.description` اختياري
- [ ] `location.name` / `address` / `url` / `context` اختيارية
- [ ] `contacts` قد تكون `null`؛ كل من `name` / `phones` / `emails` / `org` اختياري
- [ ] `sticker` لا محتوى سوى `media.path`
- [ ] `audio.voice === true` لتمييز الملاحظة الصوتية
- [ ] `user` للوارد = `null`
- [ ] لا تفترض وجود `latitude` كـ double؛ قد يأتي int
