# المحادثة المباشرة (Web Chat) — ما يخصّ تطبيق الجوال

آخر تحديث: ٩ نوفمبر ٢٠٢٦

صار للتاجر **نافذة محادثة في موقعه**. الزائر يكتب من المتصفّح، والمحادثة تصل صندوق المحادثات نفسه بقناة جديدة اسمها `webchat`. هذا الملفّ يكمّل [MOBILE_CHANNELS.md](MOBILE_CHANNELS.md) — اقرأه أولاً، فكل ما فيه ينطبق هنا.

## القاعدة أولاً

**لا شيء قديم تغيّر.** كل ما في هذا الملفّ إضافي. لكن **الردّ على الزائر يحتاج تعديلاً** (القسم ٢)، وإلا لن يستطيع الموظّف الردّ من التطبيق.

---

## ١. جهة الزائر

```json
{
  "uuid": "6f1c…",
  "channel": "webchat",
  "phone": null,
  "formatted_phone_number": "+966 50 123 4567",
  "channel_capabilities": { "templates": false, "location": false, "location_request": false, "interactive": true, "window_hours": null },
  "webchat_visitor": {
    "phone": "+966501234567",
    "email": null,
    "first_page_url": "https://shop.example.com/products/9",
    "last_page_url": "https://shop.example.com/cart",
    "referrer": "https://google.com/",
    "country": "SA",
    "browser": "Safari · iOS",
    "locale": "ar",
    "created_at": "2026-11-09T12:00:00+00:00",
    "last_seen_at": "2026-11-09T12:40:00+00:00"
  }
}
```

| الحقل | المعنى |
|---|---|
| `phone` | **دائماً `null`** للزائر. الرقم الذي كتبه في النموذج ليس رقم واتساب ولا يُرسل عليه شيء. |
| `formatted_phone_number` | الرقم للعرض فقط. اعرضه تحت الاسم كما تعرض رقم واتساب. **قد يكون `null`**: التاجر يستطيع جعل الجوال اختيارياً أو إخفاءه من النموذج — فلا تعرض شيئاً، كجهة Telegram. |
| `channel_capabilities` | ما تدعمه القناة. **أخفِ القوالب وطلب الموقع وإرسال الموقع** حين تكون `false`. |
| `window_hours: null` | لا نافذة ٢٤ ساعة: الموظّف يردّ على الزائر في أي وقت. |
| `webchat_visitor` | بطاقة «الزائر»: الصفحة التي يتصفّحها الآن ومن أين جاء. يصل حين تُحمَّل الجهة مع علاقتها (`ContactResource`). |

> الزائر جهة **منفصلة** حتى لو كتب رقم عميلٍ موجود على واتساب: لا ربط بالرقم، لأنه غير متحقَّق منه.

---

## ٢. التعديل المطلوب: الإرسال بـ `contact_uuid`

`POST /api/v1/send-msg` و`POST /api/v1/chats/upload/chunk` صارا يقبلان **`contact_uuid` بدل `phone`**. أرسل واحداً منهما:

- جهة واتساب: `phone` كما كان (أو `contact_uuid` — يُكمَل بنفس مسار الرقم).
- **جهة أي قناة أخرى (`webchat`، تيليجرام…): `contact_uuid` إجباري**، فلا رقم لها.

### نصّ

```http
POST /api/v1/send-msg
{ "type": "text", "contact_uuid": "6f1c…", "message": "أهلاً، كيف نخدمك؟" }
```

```json
{
  "statusCode": 200,
  "success": true,
  "message": null,
  "data": { "contact_id": 41, "contact_uuid": "6f1c…", "channel": "webchat", "message_id": "wc:…" }
}
```

الإرسال **فوري** على هذه القنوات (لا طابور)، والرسالة تُنسب إلى الموظّف كما في الويب.

### ملف

```http
POST /api/v1/send-msg   (multipart)
contact_uuid=6f1c…  file=@menu.png  caption=القائمة
```

نفس الردّ القديم (`data.queued`, `data.files`)، و`phone` فيه `null`.

### ملف كبير على قطع

```http
POST /api/v1/chats/upload/chunk
contact_uuid=6f1c…  upload_id=…  index=0  total=3  chunk=@…  file_name=video.mp4
```

عند اكتمال القطع يُرسل الملف مباشرة، ويحمل الردّ `data.channel` و`data.queued: false`.

### الأخطاء

| الحالة | الردّ |
|---|---|
| `contact_uuid` لجهة غير موجودة أو في منشأة أخرى | `404` — `Contact not found.` |
| لا `phone` ولا `contact_uuid` | `400` مع `errors` |
| القناة غير مفعّلة (الـ widget معطّل) | `422` برسالة توضّح السبب |

> مفتاح الـ API (`/api/send`) **لا يقبل** `contact_uuid`: لا موظّف خلفه تُنسب إليه الرسالة.

---

## ٣. رسائل البوت على المحادثة المباشرة

الردّ الآلي والـ flows والمساعد الذكي تعمل على زوّار الموقع كما على واتساب. رسالة بأزرار تصل الزائر أزراراً يضغطها، وتُحفظ هكذا:

```json
{
  "type": "text",
  "text": { "body": "هل تريد المتابعة؟" },
  "interactive_options": {
    "kind": "buttons",
    "footer": "اختر واحداً",
    "buttons": [{ "id": "button1", "title": "نعم" }, { "id": "button2", "title": "لا" }]
  }
}
```

- `type` يبقى `text` و`text.body` هو المتن: **العرض الحالي يكفي**، و`interactive_options` اختياري لمن أراد رسم الأزرار.
- `kind` واحد من `buttons` أو `list` (مع `button` و`sections`) أو `cta_url` (مع `display_text` و`url`).
- زرّ الرابط يُكتب في `text.body` كما في واتساب: `المتن\n\nالنصّ: الرابط`.
- ضغطة الزائر تصل رسالةً واردة بنفس شكل واتساب (`interactive.button_reply.title`).

---

## ٤. ما لم يتغيّر

- أيقونة القناة: `channel = "webchat"` — ارسم أيقونة محادثة (لون مقترح `#16a34a`).
- الإشعارات وصندوق المحادثات والتذاكر والإسناد كما هي.
- علامات القراءة: حين يقرأ الزائر الردّ تصل حالة `read` بنفس البثّ المعتاد.
