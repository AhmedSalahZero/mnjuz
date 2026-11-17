# القنوات في تطبيق الجوال — دورة المحادثة كاملة وإعدادات القنوات

آخر تحديث: ١٦ نوفمبر ٢٠٢٦

هذا الملف **نقطة البداية** لتنفيذ القنوات في التطبيق. صارت المنصّة تستقبل المحادثات من **WhatsApp وTelegram وMessenger وInstagram وTikTok والمحادثة المباشرة (Web Chat)** في صندوق واحد. ما كان ينقص التطبيق: القنوات في المزامنة والإرسال والتذاكر، وشاشات إعدادات القنوات كلّها.

يكمّل هذا الملف ولا يلغي:
- [MOBILE_CHANNELS.md](MOBILE_CHANNELS.md) — أساس القنوات: `phone` قد يكون `null`، و`wam_id` معتم، وشكل `metadata` واحد على كل القنوات.
- [MOBILE_WEBCHAT.md](MOBILE_WEBCHAT.md) — زائر المحادثة المباشرة وبطاقته.
- [MOBILE_NEW_ENDPOINTS.md](MOBILE_NEW_ENDPOINTS.md) — شكل الردّ والترقيم والصلاحيات (القسم «المشترك بين كل النقاط»). **كل ما هنا يتبعه.**

---

## باختصار: المطلوب من التطبيق

**الأساس**
1. **أرسل بـ `contact_uuid` بدل `phone` في كل الإرسال**: `send-msg` (نصّ وملفات)، و`chats/upload/chunk`، و`send-template`، و`send-auth-template`. هذا أهمّ بند.
2. **`contact_uuid` هو مفتاح العميل عندك، لا الرقم.** الرقم قد يصل `null` (Telegram مثلاً) أو مخفيّاً (`+966****`).

**شاشة المحادثات**

3. **اطلب `GET /api/v1/channels` عند فتح التطبيق.** منه:
   - فلتر القنوات، حين يتجاوز عدد القنوات المربوطة واحدة.
   - إخفاء الأزرار التي لا تدعمها القناة: القوالب، الموقع، الحظر…
4. **المزامنة (v2):**
   - اقرأ `channel` من كل رسالة ومن جهة الاتصال.
   - الفلتر بـ `?channel=`، والفلتر نفسه على `/contacts` و`/tickets`.
5. **تحت اسم العميل:** الرقم، وإلّا `@channel_username`، وإلّا اسم القناة.
6. **شريط الـ ٢٤ ساعة:** من `messaging_window_hours`، و`null` = أخفِه.

**الموظّف (agent)**

7. حين يصل `assigned_only: true` في المزامنة:
   - مزامنة كاملة عند كل فتح، واحذف ما لم يعد.
   - محادثة وصلك عنها حدث ولا تعرفها: اجلبها بـ `?contact_uuid=`.
   - لا تعرض رقماً من البثّ اللحظي.

**إعدادات القنوات (المالك والمدير)**

8. **`GET /settings/channels`:**
   - Telegram يُربط من التطبيق بنموذج من `fields`.
   - الباقي زرّ يفتح `connect_url` في المتصفّح.
   - الفصل من التطبيق، وWhatsApp بتأكيد لأنه يحذف القوالب.
9. **شاشة البريد (SMTP)، وشاشة Web Chat مع الأسئلة الشائعة.**

**الإشعارات**

10. افتح المحادثة من الإشعار بـ `contactUuid`.

**بعد نزول نسخة فيها البندان ١ و٧ أبلغنا لنفعّل `MOBILE_AGENT_RESTRICTIONS`.** التفاصيل والردود كلّها أدناه.

---

## النسخ القديمة من التطبيق: لا تتأثّر

**لا endpoints جديدة للإرسال، ولا حاجة إليها.**
- الطلب بـ `phone` (ما ترسله النسخ المنشورة) يمرّ ويُرَدّ عليه كما كان حرفياً: نفس التحقّق، ونفس الرسائل، ونفس مفاتيح `data`.
- كل ما أُضيف للقنوات يعمل حين تُرسل `contact_uuid` بدل `phone` في **النقطة نفسها**.
- يحرس ذلك اختبارٌ مخصّص في الخادم (`MobileOldClientContractTest`) يرسل طلبات النسخة القديمة ويتحقّق من الردود: نصّ، وملف، وآخر قطعة، ونافذة مغلقة، وقالب.

القاعدة نفسها في كل ما سواه: مفاتيح تُضاف، ولا يُحذف مفتاح ولا يُعاد تسميته.

**ما تغيّر في القيم وحدها (الشكل كما هو):**

| الموضع | قبل | بعد |
|---|---|---|
| `channel` على الرسالة في المزامنة v2 | `"whatsapp"` دائماً | قناة الرسالة الفعلية |
| التذكرة في المزامنة و`/contacts/{id}` | أقدم تذكرة | أحدثها |
| رفض الموقع على Messenger / Instagram / TikTok | نصّ «النافذة مغلقة» أو رفض بلا `statusCode` | `422` بنصّ «غير متاحة» ومعه `statusCode` |
| قطع ملف لجهة قناتها مفصولة | تُرفع كلّها ثم `422` عند آخرها | `403` عند أولها |

**يُرجى التأكّد من نقطة واحدة في كود النسخة القديمة:** إن كان يحوّل `channel` إلى enum صارم (يرمي خطأً على قيمة لا يعرفها)، فرسائل v2 ستبدأ تصل بـ `telegram` و`webchat` وغيرهما، لا `whatsapp` وحده. هذه القيم كانت تصل من قبل في v1 و`/contacts`، لكنها جديدة على رسائل v2.

**الشيء الوحيد الذي يكسر النسخ القديمة هو مفتاح قيود الموظّف** (القسم ٣). وهو مطفأ، ولن يُفعَّل إلا بعد أن يحدّث أغلب المستخدمين التطبيق. عند تفعيله، الموظّف على نسخة لم تُحدَّث لن يستطيع الإرسال في المنشآت التي تُخفي الأرقام: يرى الرقم مخفيّاً فيرسله مخفيّاً.

---

## ٠. الخلاصة في دقيقة

| # | ما تغيّر | النوع | هل يكسر النسخة المنشورة؟ |
|---|---|---|---|
| ١ | `channel` على كل رسالة في **v2** كان دائماً `"whatsapp"` — صار قناة الرسالة الفعلية | **إصلاح** | لا |
| ٢ | التذكرة في المزامنة (`ticket_status`، `ticket_assigned_to`) وفي `/contacts/{id}` (`chat_ticket`) صارت **الأحدث** | **إصلاح** | لا |
| ٣ | فلتر `?channel=` على المزامنة و`/contacts` و`/tickets`، و`?contact_uuid=` على المزامنة | إضافي | لا |
| ٤ | نقطة جديدة `GET /api/v1/channels`: القنوات المربوطة وما تدعمه كلٌّ منها | إضافي | لا |
| ٥ | حقول قناة جديدة على جهة الاتصال في المزامنة و`/contacts` و`/contacts/{id}` و`/tickets` والبثّ اللحظي | إضافي | لا |
| ٦ | `send-template` و`send-auth-template` يقبلان `contact_uuid`، ويرفضان القنوات بلا قوالب | إضافي | لا |
| ٧ | نصّ «النافذة مغلقة» صار بحسب القناة في الملفات والقطع والموقع | تحسين | لا |
| ٨ | إعدادات القنوات كاملة: Telegram، Email، Web Chat، الأسئلة الشائعة، الفصل | **نقاط جديدة** | لا |
| ٩ | إخفاء الأرقام عن الموظّف وقصر مزامنته على محادثاته | **سلوك جديد خلف مفتاح على الخادم** | **نعم إن فُعّل قبل التحديث** — القسم ٣ |

---

## ١. القاعدة أولاً

- **لم يُحذف مفتاح ولم يُعَد تسمية شيء.** كل الجديد إضافي، والنسخة المنشورة تتجاهله.
- **لا تبنِ شروطاً على اسم القناة.** اقرأ ما تدعمه القناة من `capabilities` (القسم ٢-١). إضافة منصّة لاحقاً لن تحتاج تحديث التطبيق.
- **`contact_uuid` هو مفتاح جهة الاتصال** في كل مكان: في القوائم المحلّية، وفي الإرسال، وفي فتح المحادثة. الرقم ليس هويّة: جهات القنوات الأخرى بلا رقم، والموظّف قد يراه مخفيّاً (القسم ٣).
- **اعرض `message` كما يصل من الخادم** في كل رفض. النصوص مترجمة بحسب لغة الطلب.

---

## ٢. دورة المحادثة

### ٢-١. القنوات وما تدعمه — `GET /api/v1/channels` (جديد)

لكل الأدوار. اطلبه عند فتح التطبيق وعند تبديل المنشأة، وخزّنه.

```json
{
  "statusCode": 200,
  "success": true,
  "message": null,
  "data": {
    "connected": ["whatsapp", "telegram", "webchat"],
    "channels": [
      {
        "channel": "telegram",
        "label": "Telegram",
        "connected": true,
        "capabilities": {
          "window_hours": null,
          "templates": false,
          "interactive": true,
          "location": true,
          "location_request": false,
          "media_caption": true,
          "text_with_media": true,
          "multiple_attachments": true,
          "delivery_receipts": false,
          "read_receipts": false,
          "block_contact": false,
          "reactions": false,
          "reply_to": false,
          "media_limits": { "image": 10485760, "video": 52428800 },
          "campaigns": true
        }
      }
    ]
  }
}
```

القيم في المثال توضيحية — **اقرأها من الردّ ولا تنسخها في الكود**.

- `channels` فيها القنوات الستّ دائماً بالترتيب نفسه، و`connected` يقول أيّها مربوط الآن.
- البريد (`email`) ليس هنا: قناة حملات بلا صندوق وارد.

| استعمل | من |
|---|---|
| شرائح فلتر القنوات فوق قائمة المحادثات | `connected` — **أظهر الفلتر فقط حين يتجاوز طولها واحداً** |
| أيقونة القناة واسمها | `channel` و`label` |
| إظهار أزرار الكتابة وإخفاؤها | `capabilities` بحسب `channel` جهة الاتصال |

| تُخفي | حين |
|---|---|
| زرّ القوالب | `templates === false` |
| طلب الموقع | `location_request === false` |
| إرسال الموقع | `location === false` |
| حظر العميل | `block_contact === false` |
| علامتا القراءة | `read_receipts === false` |
| حقل التعليق مع المرفق | `media_caption === false` |
| اختيار عدّة ملفات | `multiple_attachments === false` |
| شريط «٢٤ ساعة» | `window_hours === null` |

الخادم يرفض أي عملية غير مدعومة (القسم ٢-٦)، لكن إخفاء الزرّ أفضل من رسالة خطأ بعد الضغط.

### ٢-٢. المزامنة — `list-messages-from-uuid-to-end` و`-v2`

**معاملات جديدة (اختيارية):**

| المعامل | القيمة | الأثر |
|---|---|---|
| `channel` | `whatsapp` \| `telegram` \| `messenger` \| `instagram` \| `tiktok` \| `webchat` | جهات تلك القناة وحدها. **قيمة مجهولة تُتجاهل** فيُرجَع الكلّ (لا خطأ) |
| `contact_uuid` | uuid | محادثة واحدة كاملة. لاستكمال محادثة وصلك عنها حدث ولا تعرفها (القسم ٣-٢). uuid غير صالح ⇒ `400` |

يجتمعان مع `created_at` و`per_page` و`page` كما هي.

**على كائن جهة الاتصال** (`data[].contact` في v2، و`data[]` نفسه في v1):

```json
{
  "channel": "telegram",
  "channel_identifier": "123456789",
  "channel_username": "khaled_tg",
  "messaging_window_hours": null
}
```

| الحقل | المعنى |
|---|---|
| `channel` | قناة جهة الاتصال. لا يكون `null` (القديم يصل `"whatsapp"`) |
| `channel_identifier` | معرّف العميل لدى المنصّة. `null` لواتساب. للزائر: معرّف الزائر |
| `channel_username` | اسمه الظاهر على المنصّة **بلا @**. `null` لمن لا اسم مستخدم له |
| `messaging_window_hours` | `24` لواتساب وMessenger وInstagram، `48` لـ TikTok، **`null` = بلا نافذة** (Telegram وWeb Chat) |

**على الردّ نفسه** (بجوار `data` و`pagination`):

```json
{ "assigned_only": false }
```

`true` حين تُرجع المزامنة ما أُسند إلى الموظّف وحده. انظر القسم ٣-٢.

**إصلاحان يغيّران قيماً تصلك:**

1. **`channel` على الرسالة في v2** كان يصل `"whatsapp"` لكل رسالة، حتى على Telegram والمحادثة المباشرة. صار قناة الرسالة الفعلية. في v1 لم يتغيّر (كان صحيحاً).
2. **التذكرة:** جهةٌ لها أكثر من تذكرة كانت تُرجع حالة **أقدمها** ومُسنَدها. صارت الأحدث، كما يعرضها الويب.

### ٢-٣. رأس المحادثة: ماذا تعرض تحت الاسم

بهذا الترتيب، أوّل ما ليس فارغاً:

1. `formatted_phone_number` — رقم واتساب، أو رقم الزائر الذي كتبه (قد يصل مخفيّاً، القسم ٣).
2. `"@" + channel_username` — Telegram مثلاً.
3. `label` القناة من `GET /channels`.

وللاسم نفسه: `contact_full_name`، وإلّا `@channel_username`، وإلّا اسم القناة. **لا تعرض «—» ولا سطراً فارغاً.**

### ٢-٤. جهات الاتصال

**`GET /api/v1/contacts`**
- معامل جديد: `channel` (نفس قواعد المزامنة).
- حقول جديدة على كل عنصر: `channel_identifier`، `channel_username`، `messaging_window_hours`.
- `webchat_visitor` **صار يصل فعلاً** لجهات المحادثة المباشرة (كان موثّقاً في MOBILE_WEBCHAT.md ولا يصل). شكله هناك.

**`GET /api/v1/contacts/{id}`** — نفس الردّ القديم، ومعه:

```json
{
  "channel_capabilities": { "...": "كما في GET /channels" },
  "channel_username": "khaled_tg",
  "messaging_window_hours": null,
  "is_messaging_window_open": true,
  "webchat_visitor": null
}
```

`webchat_visitor` كائن لزائر المحادثة المباشرة، و`null` لغيره.

**إصلاح:** `chat_ticket` كان يُرجع **أقدم** تذكرة لمن له أكثر من تذكرة، فتظهر حالة وموظف قديمان، ويبدو أن «إغلاق/فتح» لم يغيّر شيئاً (لأن `toggle-ticket-status` يعدّل الأحدث). صار الأحدث، بالشكل نفسه.

**تعديل جهة الاتصال (`PUT /contacts/{uuid}`) لم يعد يمحو `channel_username`**: كان إرسال `metadata` (الحقول المخصّصة) يمحو اسم المستخدم فيختفي `@username` من رأس المحادثة. لا تغيير مطلوب منك.

### ٢-٥. التذاكر — `GET /api/v1/tickets` و`/tickets/summary`

- معامل جديد على الاثنين: `channel`.
- البحث (`search`) صار يجد زائر المحادثة المباشرة برقمه المعروض.
- حقول جديدة على كل عنصر:

```json
{
  "phone": null,
  "channel": "webchat",
  "channel_identifier": "6f1c…",
  "formatted_phone_number": "+966 50 123 4567"
}
```

> تذكير: `/tickets` يقصر الموظّف **دائماً** على ما أُسند إليه (كما كان). المزامنة تتبع إعداد المنشأة (القسم ٣-٢).

### ٢-٦. الإرسال: بـ `contact_uuid` على كل القنوات

**القاعدة:** أرسل `contact_uuid` **بدل** `phone` لكل جهة، على كل القنوات. جهة واتساب بـ `contact_uuid` تكمل مسار الرقم نفسه (الرقم الحقيقي يُقرأ من الخادم). الإرسال بالرقم باقٍ كما هو.

| النقطة | `contact_uuid` | ملاحظة |
|---|---|---|
| `POST /send-msg` (نصّ) | ✅ منذ إصدار سابق | **جديد:** `msg_uuid` صار يُحفظ على القنوات الأخرى أيضاً. كان يضيع فتبقى الفقاعة المؤقّتة معلّقة |
| `POST /send-msg` (ملفات) | ✅ | |
| `POST /chats/upload/chunk` | ✅ | **جديد:** قناة مفصولة تُرفض `403` **قبل** تخزين أول قطعة |
| `POST /send-template` | ✅ **جديد** | القناة بلا قوالب ⇒ `422` |
| `POST /send-auth-template` | ✅ **جديد** | القناة بلا قوالب ⇒ `422` |

```http
POST /api/v1/send-template
{ "contact_uuid": "4895c0cc-…", "template_uuid": "a1b2…" }
```

| الحالة | الردّ |
|---|---|
| جهة واتساب | كما كان بالرقم |
| جهة على قناة بلا قوالب | `422` — `"القوالب غير متاحة على Telegram."` |
| `contact_uuid` لجهة غير موجودة أو في منشأة أخرى | `404` — `Contact not found.` |
| لا `phone` ولا `contact_uuid` | `400` كما كان (`errors.phone`) |

> **سببٌ عملي للتحوّل:** كان التطبيق يرسل القالب برقم زائر المحادثة المباشرة المعروض، فتُنشأ له جهة واتساب جديدة ويخرج إليها قالب لم يقصده أحد. بـ `contact_uuid` يُرفض بدل ذلك.

### ٢-٧. النافذة والقدرات: ما يُرفض ولماذا

**نصّ «النافذة مغلقة»** صار بحسب القناة في الملفات (`send-msg` بملف) والقطع وطلب الموقع وإرسال الموقع. نصّ واتساب لم يتغيّر حرفياً. مثال Messenger:

> «Messenger لا تسمح بإرسال رسالة بعد 24 ساعة من آخر رسالة للعميل. انتظر حتى يراسلك مرّة أخرى.»

**الموقع:** القدرة تُفحص **قبل** النافذة. على Messenger كان الموظّف بنافذة مغلقة يُنصح بإرسال قالب، والصحيح أن الميزة غير متاحة أصلاً:

```json
{ "statusCode": 422, "success": false, "message": "طلب الموقع غير متاحة على Messenger." }
```

صار الرفض يحمل `statusCode` كبقية الردود (كان يصل بلا `statusCode`).

### ٢-٨. البثّ اللحظي والإشعارات

**`NewChatEvent`** — مفتاحان جديدان بجوار `channel`:

```json
{ "channel": "telegram", "channel_identifier": "123456789", "channel_username": "khaled_tg" }
```

**إشعار FCM** — مفتاحان جديدان في `data`:

| المفتاح | المعنى |
|---|---|
| `contactUuid` | افتح المحادثة به — جهات القنوات الأخرى بلا رقم |
| `channel` | لأيقونة القناة |

وعنوان الإشعار لعميل بلا اسم ولا رقم صار `@username` بدل «Unknown».

---

## ٣. قيود الموظّف (agent) — خلف مفتاح على الخادم

### ما الذي يتغيّر

ثغرتان قديمتان في التطبيق، يغلقهما مفتاح واحد على الخادم (`MOBILE_AGENT_RESTRICTIONS`):

| | قبل (المفتاح مطفأ — الوضع الحالي) | بعد (المفتاح مفعّل) |
|---|---|---|
| منشأة فعّلت «إخفاء الأرقام عن الموظّفين» | الموظّف يرى الأرقام **كاملة** في التطبيق (والويب يخفيها) | تصله **مخفيّة** كالويب: `+966*********`، و`+966 ** *** ****` للمعروض |
| منشأة منعت الموظّفين من رؤية كل المحادثات | المزامنة تُرجع للموظّف **كل** المحادثات | تُرجع ما أُسند إليه فقط، و`assigned_only: true` |

**المفتاح مطفأ اليوم ولن يُفعَّل قبل أن ينزل تحديث التطبيق.** السبب: النسخة المنشورة ترسل بالرقم، وموظّفٌ يرى الرقم مخفيّاً لا يستطيع الإرسال به.

المالك والمدير لا يتأثّران في الحالتين.

### ٣-١. المطلوب قبل التفعيل: الإرسال بـ `contact_uuid`

- أرسل بـ `contact_uuid` **دائماً** (القسم ٢-٦)، حتى لجهات واتساب. ردّ الإرسال بـ `contact_uuid` يحمل `data.phone` مخفيّاً لمن يُخفى عنه.
- لا تعتمد على الرقم مفتاحاً ولا في البحث المحلّي: قد يصل مخفيّاً.
- الإخفاء يطال: `/contacts`، و`/contacts/{id}`، والمزامنة v1 وv2 (`phone` و`formatted_phone_number`)، و`/tickets`، و`webchat_visitor.phone`.
- **البثّ اللحظي وFCM لا يُخفيان الرقم بعد** (معروف، خارج هذا الإصدار): **لا تعرض رقماً من حدثٍ لحظي** — اعرضه من ردّ الـ API.

### ٣-٢. المطلوب قبل التفعيل: مزامنة قائمة الموظّف

حين يصل `assigned_only: true`:

1. المحادثة التي تُسحب من الموظّف **لا يصله عنها شيء**: لا حدث ولا سطر في المزامنة. لذلك عند كل فتح للتطبيق (أو عودته من الخلفية) نفّذ **مزامنة كاملة بلا `created_at`** بالصفحات، واحذف من القائمة المحلّية كل جهة لم تعد.
2. حين يصل حدث لحظي أو إشعار عن محادثة لا تعرفها (أُسندت إليه للتوّ): اطلبها وحدها بـ `?contact_uuid=` فتأتي كاملة.
3. محادثة خارج نطاق الموظّف تُرجع `data: []` حتى مع `contact_uuid`.

---

## ٤. إعدادات القنوات (جديد كلّه)

**للمالك والمدير وحدهما**، كما في الويب. الموظّف يأخذ `403` على كل ما تحت `/settings/*`. أخفِ الشاشة عنه.

### ٤-١. ما يُربط من التطبيق وما يُربط من المتصفّح

| القناة | الربط | الفصل |
|---|---|---|
| Telegram | **من التطبيق** (توكن البوت) | من التطبيق |
| Email (SMTP) | **من التطبيق** | من التطبيق |
| Web Chat | **من التطبيق** (الإعدادات كاملة) | يُوقف من إعداداته (`enabled: false`)، لا يُحذف |
| Messenger / Instagram / TikTok | **من المتصفّح** — افتح `connect_url` | من التطبيق |
| WhatsApp | **من المتصفّح** — افتح `connect_url` | من التطبيق (مع تأكيد) |

الربط بتفويض المنصّة (OAuth) والربط المدمج لواتساب يمرّان بجلسة الويب، فلا يعملان داخل التطبيق.

- **افتح `connect_url` في المتصفّح الخارجي** لا في WebView: المستخدم يحتاج جلسة لوحة التحكّم.
- **تنبيه للمستخدم:** صفحة الويب تفتح على المنشأة المختارة في لوحة التحكّم، وقد تكون غير منشأة التطبيق. اعرض جملة تذكّره بذلك.
- بعد عودته إلى التطبيق أعد طلب `GET /settings/channels`.

### ٤-٢. قائمة القنوات — `GET /api/v1/settings/channels`

```json
{
  "whatsapp": {
    "connected": true,
    "name": "متجر المثال",
    "connect_mode": "web",
    "connect_url": "https://app.mnjz.net/settings/whatsapp",
    "capabilities": { "...": "..." }
  },
  "channels": [
    {
      "channel": "telegram",
      "label": "Telegram",
      "account": {
        "uuid": "9b1d…",
        "channel": "telegram",
        "channel_label": "Telegram",
        "external_id": "777001",
        "name": "بوت الدعم",
        "username": "support_bot",
        "avatar": null,
        "status": "active",
        "is_active": true,
        "needs_reauthorization": false,
        "last_error": null,
        "token_expires_at": null,
        "created_at": "2026-11-16T10:00:00+00:00"
      },
      "connect_mode": "in_app",
      "connect_url": "https://app.mnjz.net/settings/channels/telegram",
      "fields": [
        { "key": "bot_token", "label": "توكن البوت", "type": "password", "hint": "احصل عليه من @BotFather على تيليجرام." }
      ],
      "platform_ready": true,
      "capabilities": { "...": "..." },
      "notes": ["لا نافذة ردّ — تستطيع مراسلة العميل متى شئت.", "…"]
    }
  ],
  "email": { "connected": false, "needs_attention": false, "from_address": null, "connect_mode": "in_app" },
  "webchat": { "exists": true, "enabled": true, "available": true }
}
```

| الحقل | الاستعمال |
|---|---|
| `channels` | Telegram ثم Messenger ثم Instagram ثم TikTok |
| `account` | `null` = غير مربوطة |
| `account.needs_reauthorization` أو `status = reauthorization_required` | شارة «تحتاج انتباهاً» مع `last_error`، وزرّ إعادة ربط |
| `connect_mode` | `in_app` ⇒ ارسم نموذجاً من `fields` · `web` ⇒ زرّ يفتح `connect_url` |
| `fields` | فارغة حين `connect_mode = web`. لكلٍّ: `key` (مفتاح الإرسال)، `label`، `type` (`password` ⇒ حقل مخفي)، `hint` |
| `platform_ready: false` | المنصّة غير مضبوطة على الخادم: اعرض «غير متاحة حالياً» بدل الزرّ |
| `notes` | قيود المنصّة: اعرضها نقاطاً تحت البطاقة |
| `webchat.available: false` | الإضافة خارج باقة المنشأة: اعرض القناة مقفلة، ونقاط Web Chat تُرجع `403` |
| `email.needs_attention` | حساب البريد موقوف (فشل الدخول لاحقاً): أعد الحفظ بكلمة المرور |

### ٤-٣. ربط Telegram — `POST /api/v1/settings/channels`

```http
POST /api/v1/settings/channels
{ "channel": "telegram", "credentials": { "bot_token": "777001:AAF-…" } }
```

- مفاتيح `credentials` هي `fields[].key` من البطاقة. **أي مفتاح غيرها يُهمل.**
- السيرفر يتحقّق من التوكن لدى Telegram **قبل** الحفظ، ثم يسجّل الـ webhook.
- **نفس الطلب يعيد الربط** بتوكن جديد (لا نقطة تعديل منفصلة).
- `throttle`: ١٠ طلبات في الدقيقة.

| الحالة | الردّ |
|---|---|
| نجح | `200`، `data.account` كما في البطاقة، و`data.webhook_registered: true` |
| حُفظ ولم يُسجَّل الـ webhook | `200`، و`webhook_registered: false`، و`message` تشرح أن الرسائل لن تصل. **اعرضها تحذيراً** واقترح إعادة المحاولة |
| التوكن مرفوض / تعذّر الوصول / البوت مربوط بمنشأة أخرى | `422` برسالة جاهزة. **لا شيء يُحفظ** |
| حقل ناقص (`credentials.bot_token`) | `400` مع `errors` |
| `channel` = `messenger` / `instagram` / `tiktok` | `422` — «اربط … من لوحة التحكّم في المتصفّح.» |
| `channel` = `whatsapp` / `email` / `webchat` / مجهول | `400` (`errors.channel`) |

> ردّ `422` لقناة تُربط من المتصفّح يعني أن التطبيق أرسل طلب ربط لبطاقة `connect_mode: "web"`. لا يحمل `connect_url` (ردود الخطأ بلا `data`)، والرابط موجود في البطاقة نفسها: افتحه بدل إرسال الطلب.

### ٤-٤. الفصل

| الطلب | ماذا يفعل |
|---|---|
| `DELETE /api/v1/settings/channels/{account.uuid}` | يفصل Telegram أو Messenger أو Instagram أو TikTok أو **البريد**. المحادثات والسجلّ يبقيان |
| `DELETE /api/v1/settings/channels/whatsapp` | يفصل واتساب **ويحذف كل قوالب واتساب** (حذفاً ناعماً) |

| الحالة | الردّ |
|---|---|
| حساب غير موجود أو لمنشأة أخرى | `404` |
| uuid المحادثة المباشرة | `422` — تُوقف من إعداداتها |
| واتساب غير مربوطة | `422` |

> **فصل واتساب يحتاج تأكيداً صريحاً في التطبيق** — نصٌّ يقول إن القوالب ستُحذف والحملات المجدولة عليها ستفشل.

### ٤-٥. البريد (SMTP) للحملات

| الطلب | الوصف |
|---|---|
| `GET /api/v1/settings/channels/email` | الإعداد الحالي |
| `POST /api/v1/settings/channels/email` | حفظ. `throttle` ١٠/دقيقة |
| `POST /api/v1/settings/channels/email/test` | رسالة تجربة إلى بريد المستخدم نفسه. `throttle` ٥/دقيقة |
| `DELETE /api/v1/settings/channels/{account.uuid}` | فصل |

```json
{
  "account": { "uuid": "…", "status": "active", "is_active": true, "needs_reauthorization": false, "last_error": null },
  "smtp": {
    "host": "smtp.office365.com", "port": 587, "encryption": "tls",
    "username": "sales@shop.example", "has_password": true,
    "from_address": "sales@shop.example", "from_name": "متجر المثال", "reply_to": "support@shop.example"
  },
  "ports": [465, 587, 2525]
}
```

`account` و`smtp` يصلان `null` قبل أول ربط.

**الحفظ:** نفس الحقول: `host`، `port`، `encryption` (`tls`|`ssl`)، `username`، `password`، `from_address`، `from_name`، `reply_to`.

- **كلمة المرور لا تُرسل إليك أبداً.** `has_password: true` يعني أنها محفوظة. اترك الحقل فارغاً لإبقائها.
  - الفارغة تُقبل **فقط** إن بقي الخادم والمنفذ واسم المستخدم كما هي. غير ذلك ⇒ `400` على `password`.
- السيرفر يسجّل الدخول **قبل** الحفظ. الرفض يصل `400` على حقله، فاعرضه تحته:
  - `errors.password`: كلمة مرور خاطئة.
  - `errors.host`: خادم لا يُوصل إليه، أو عنوان داخلي ممنوع.
- اقتراح للواجهة كما في الويب: المنفذ `465` ⇒ `ssl`، وغيره ⇒ `tls`.
- الحفظ الناجح يُرجع الإعداد كاملاً كما في `GET`، و`message` جاهزة للعرض.

### ٤-٦. المحادثة المباشرة (Web Chat)

**محروسة مرّتين:** المالك أو المدير، **و**إضافة Web Chat في الباقة. بدون الإضافة ⇒ `403` «هذه الميزة غير متاحة في باقتك.».

| الطلب | الوصف |
|---|---|
| `GET /api/v1/settings/webchat` | الإعدادات. **يُنشئ الـ widget عند أول فتح** (كالويب) |
| `POST /api/v1/settings/webchat` | حفظ النموذج **كاملاً** |
| `POST /api/v1/settings/webchat/logo` | رفع الشعار (multipart: `logo`) |
| `DELETE /api/v1/settings/webchat/logo` | إزالة الشعار |
| `POST /api/v1/settings/webchat/rotate-key` | مفتاح جديد — **الكود القديم في موقع التاجر يتوقّف فوراً**. اطلب تأكيداً |

كلّها تُرجع الإعدادات كاملة بعد التغيير:

```json
{
  "key": "wc_Ab12…",
  "snippet": "<script async src=\"https://app.mnjz.net/widget/v1/loader.js\" data-mnjz-key=\"wc_Ab12…\"></script>",
  "settings": {
    "enabled": true,
    "allowed_domains": ["shop.example.com"],
    "appearance": {
      "color": "#16a34a", "position": "right", "offset_x": 20, "offset_y": 20,
      "title": "", "subtitle": "", "welcome_message": "",
      "title_en": "", "subtitle_en": "", "welcome_message_en": "",
      "language": "ar", "logo_url": null, "logo_path": null
    },
    "prechat": {
      "default_country": "SA", "phone": "required", "email": "off",
      "name_label": null, "phone_label": null, "consent_text": null, "consent_text_en": null,
      "handoff_message": null, "handoff_message_en": null,
      "questions": [ { "id": "name", "target": "name", "field": null, "validation": "text", "required": true, "label_ar": null, "label_en": null } ]
    },
    "behavior": { "show_agent_names": true, "sound": true, "uploads": true }
  },
  "logo": null,
  "questions": [ "…ما يسأله الـ widget الآن…" ],
  "default_questions": [ "…" ],
  "contact_fields": [ { "uuid": "…", "name": "المدينة", "type": "text", "value": null } ],
  "faqs": [ { "id": 12, "question_ar": "…", "question_en": null, "answer_ar": "…", "answer_en": null, "image_url": null, "is_active": true } ],
  "faqs_max": 30,
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
```

| الحقل | الاستعمال |
|---|---|
| `snippet` | الكود الذي يلصقه التاجر في موقعه: زرّ «نسخ» و«مشاركة». اللصق نفسه عمل مكتبي |
| `logo` | رابط الشعار للعرض (لا `logo_path`) |
| `questions` | ما يسأله الـ widget للزائر الآن — **اعرض منشئ الأسئلة منه** |
| `contact_fields` | الحقول المخصّصة التي يصحّ ربط سؤال بها (`target: field` و`field: uuid`). مربّع الاختيار (`checkbox`) لا يُسأل |
| `options` | **ارسم كل الاختيارات منها**، ولا تحفظ القيم في كود التطبيق |

**الحفظ (`POST /settings/webchat`)** — نفس body الويب:

```json
{
  "enabled": true,
  "allowed_domains": ["shop.example.com", "*.example.org"],
  "appearance": { "color": "#123abc", "position": "left", "offset_x": 10, "offset_y": 30, "title": "تحدّث معنا", "subtitle": "", "welcome_message": "أهلاً بك", "title_en": "Chat with us", "subtitle_en": "", "welcome_message_en": "", "language": "ar" },
  "prechat": { "default_country": "SA", "email": "off", "name_label": "", "phone_label": "", "consent_text": "", "consent_text_en": "", "handoff_message": "سنحوّل محادثتك إلى أحد موظفي خدمة العملاء.", "handoff_message_en": "", "questions": [ { "target": "name", "required": true }, { "target": "phone", "required": false } ] },
  "behavior": { "show_agent_names": true, "sound": true, "uploads": true }
}
```

- **الحقول المطلوبة:** `enabled`، و`appearance.color` (`#RRGGBB`)، و`position`، و`offset_x`، و`offset_y` (0–200)، و`language`، و`prechat.default_country` (حرفان)، و`prechat.email`، و`behavior.*` الثلاثة.
- **ما تغفله يبقى كما حُفظ** (لا يُمحى):
  - الشعار.
  - النصوص الإنجليزية (`*_en`).
  - `consent_text_en`.
  - `handoff_message` و`handoff_message_en`.
  - `prechat.questions`.
- **`prechat.handoff_message` / `handoff_message_en`** (اختياريان، حتى ٥٠٠ حرف): رسالة تظهر للزائر في الـ widget بعد أن يجيب أسئلة البداية، **حين لا توجد أسئلة شائعة مفعّلة** (مثل «سنحوّل محادثتك إلى أحد موظفي خدمة العملاء»). الفارغة = لا شيء. اعرض حقل الإنجليزية حين تُكتب العربية، كنصّ الموافقة.
- **`prechat.questions` إن أرسلتها تحكم:** منها يُشتقّ `prechat.phone` و`prechat.email`. سؤال الجوال `required: false` ⇒ `phone: optional`، وغيابه ⇒ `off`.
  - لكل سؤال: `target`، و`field` (لـ `target: field`)، و`validation`، و`required`، و`label_ar`، و`label_en`.
  - الحقل الواحد يُسأل مرّة واحدة، وحتى ١٢ سؤالاً.
- `allowed_domains` فارغة = كل المواقع.
- الخطأ ⇒ `400` و`errors` بمفاتيح مثل `appearance.color` و`prechat.questions.1.target`.

**الشعار:** `png` أو `jpg` أو `webp`، حتى ١ ميغابايت. SVG مرفوض.

**الأسئلة الشائعة:**

| الطلب | الوصف |
|---|---|
| `POST /api/v1/settings/webchat/faqs` | إضافة (multipart عند الصورة) |
| `POST /api/v1/settings/webchat/faqs/{id}` | تعديل (POST لا PUT: multipart) |
| `DELETE /api/v1/settings/webchat/faqs/{id}` | حذف |
| `POST /api/v1/settings/webchat/faqs/reorder` | `{ "ids": [14, 12, 13] }` بالترتيب الجديد |

- **الحقول:** `question_ar` (مطلوب، ≤150)، `question_en`، `answer_ar` و`answer_en` (≤1000)، `image` (`png`/`jpg`/`webp` ≤ ٢ ميغابايت)، `remove_image` (bool، في التعديل)، `is_active` (bool).
- **إجابةٌ نصّاً أو صورةً** إلزاماً ⇒ وإلّا `400` على `answer_ar`.
- حتى ٣٠ سؤالاً ⇒ وإلّا `400` على `question_ar`.
- `is_active` الغائب: الجديد مفعّل، والمعدَّل يبقى كما كان.
- **الردّ:** `{ "faq": {…}, "faqs": [ …كلّها بالترتيب… ] }`، والحذف والترتيب يُرجعان `faqs` وحدها. استبدل القائمة المحلّية بها.
- `id` لسؤال منشأة أخرى ⇒ `404`. وفي الترتيب يُتجاهل.

**غير متاح في التطبيق:** المعاينة الحيّة للـ widget (صفحة ويب بجلسة). يمكن اقتراح فتح موقع التاجر نفسه للتجربة.

---

## ٥. جدول الحالات

| الحالة | المعنى | ما يعرضه التطبيق |
|---|---|---|
| `400` | بيانات غير صالحة — `errors` بمفاتيح الحقول، كل قيمة `string[]` | الخطأ تحت حقله |
| `403` | الدور لا يسمح، أو الميزة خارج الباقة، أو القناة غير مربوطة (القطع) | `message` |
| `403` + `code: organization_required` | لا منشأة مختارة للتطبيق (مستخدم في أكثر من منشأة) | شاشة اختيار المنشأة ثم `set-current-organization` (MOBILE_AUTH_GUIDE.md). ليست عطلاً في البثّ |
| `404` | غير موجود أو في منشأة أخرى | `message` |
| `422` | رفض عمل: نافذة مغلقة، ميزة لا تدعمها القناة، توكن مرفوض، قناة تُربط من الويب | `message` كما هي |
| `429` | تجاوز حدّ الطلبات (ربط القنوات، البريد) | «حاول بعد قليل» |

`data` غائب في كل ردود الخطأ، كما في MOBILE_NEW_ENDPOINTS.md.

---

## ٦. قائمة التنفيذ بالأولوية

| # | العمل | الأولوية |
|---|---|---|
| ١ | الإرسال بـ `contact_uuid` في **كل** نقاط الإرسال، والقوالب منها (٢-٦) | **عالية — شرط تفعيل القسم ٣** |
| ٢ | قراءة `channel` الرسالة في v2 بعد الإصلاح (لا تفترض `whatsapp`) | عالية |
| ٣ | `GET /channels` وإخفاء الأزرار بحسب `capabilities` | عالية |
| ٤ | رأس المحادثة: الرقم أو `@channel_username` أو اسم القناة (٢-٣) | عالية |
| ٥ | شاشة القنوات: القائمة، وربط Telegram، والفصل، وفتح `connect_url` | عالية |
| ٦ | مزامنة الموظّف: `assigned_only`، والمزامنة الكاملة عند الفتح، و`?contact_uuid=` (٣-٢) | **عالية — شرط تفعيل القسم ٣** |
| ٧ | لا رقم من الأحداث اللحظية للموظّف (٣-١) | **عالية — شرط تفعيل القسم ٣** |
| ٨ | فلتر القنوات في المحادثات وجهات الاتصال والتذاكر | متوسطة |
| ٩ | إعدادات Web Chat والأسئلة الشائعة | متوسطة |
| ١٠ | بريد الحملات (SMTP) | متوسطة |
| ١١ | فتح المحادثة من إشعار FCM بـ `contactUuid` | متوسطة |
| ١٢ | شريط النافذة بحسب `messaging_window_hours` (`null` = لا شريط) | منخفضة |

**بعد إنجاز ١ و٦ و٧ ونزول التحديث:** أخبر فريق الخادم ليُفعّل `MOBILE_AGENT_RESTRICTIONS`.

---

## ٧. أسئلة شائعة

**هل تعمل النسخة المنشورة اليوم بلا تعديل؟**
نعم. كل ما سبق إضافي أو إصلاح، والمفتاح (القسم ٣) مطفأ.

**لماذا لا يُربط Messenger من التطبيق مثل Telegram؟**
Messenger وInstagram وTikTok تُربط بتفويض المنصّة (OAuth)، وعودته مربوطة بجلسة لوحة التحكّم والعنوان المسجَّل عند المنصّة. التطبيق يفتح الصفحة في المتصفّح، والفصل من التطبيق.

**أين حملات Telegram والبريد والقوالب المحفوظة لهما؟**
ليست في هذا الإصدار: الحملات من التطبيق ما زالت واتساب وحدها.

**عميل راسلنا من واتساب ومن Telegram: جهة أم اثنتان؟**
اثنتان، لكلٍّ `contact_uuid` ومحادثة مستقلّة (كما في MOBILE_CHANNELS.md).
