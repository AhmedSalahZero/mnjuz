# نقاط جديدة لتطبيق الجوال

كل ما كان المستخدم يفعله في الويب وحده: التقارير، وتذاكر الدعم، والملف الشخصي، والإعدادات العامّة، وأوقات العمل، والأتمتة الأساسية.

**18 نقطة جديدة**، كلها تحت `/api/v1` وبنفس التوثيق والغلاف المستعملين اليوم.

---

## المشترك بين كل النقاط

```
Authorization: Bearer <sanctum token>
Accept: application/json
```

### شكل الردّ

```jsonc
// نجاح
{
  "statusCode": 200,
  "success": true,
  "message": "Settings updated successfully",
  "data": { "timezone": "Asia/Riyadh" }
}

// خطأ في البيانات المُرسَلة
{
  "statusCode": 400,
  "success": false,
  "message": "The provided data is invalid.",
  "errors": { "email": ["The email has already been taken."] }
}

// الصلاحية لا تسمح، أو الميزة خارج الباقة
{
  "statusCode": 403,
  "success": false,
  "message": "You are not allowed to access this page."
}

// غير موجود
{
  "statusCode": 404,
  "success": false,
  "message": "Data not found"
}
```

**قواعد ثابتة في كل ردّ:**

| المفتاح | متى يظهر | ملاحظة |
|---|---|---|
| `statusCode` | دائمًا | يساوي HTTP status |
| `success` | دائمًا | boolean |
| `message` | دائمًا | **قد يكون `null` عند النجاح** — عرّفه nullable |
| `data` | عند النجاح فقط | **غائب تمامًا في كل ردود الخطأ** |
| `errors` | في 400 فقط | مفاتيحه أسماء الحقول، وقيمة كل مفتاح `string[]` |

### شكل الترقيم

كل قائمة تعيد نفس المفاتيح:

```json
{
  "data": {
    "items": [],
    "pagination": { "page": 1, "per_page": 25, "total": 130, "last_page": 6 }
  }
}
```

`items` مصفوفة العناصر، وتكون فارغة `[]` حين لا نتيجة — لا `null`.

يُمرَّر `?page=2&per_page=50`. الحدّ الأقصى لـ `per_page` هو **100**.

### الصلاحيات

| الصلاحية | ما تستطيعه |
|---|---|
| `owner` | كل شيء، ووحده يحذف التقييمات |
| `manager` | كل شيء عدا حذف التقييمات |
| `agent` | يقرأ الأتمتة والإعدادات ولا يكتبها · لا يرى التقارير إطلاقًا · يرى تذاكره وحدها |

صلاحية المستخدم تصل في `GET /api/v1/profile` تحت المفتاح `role`. اقرأها مرّة عند فتح التطبيق، وابنِ عليها إظهار الشاشات وإخفاءها — أفضل من استدعاء نقطة ثم استقبال **403**.

### شروط الباقة

ثلاث نقاط تحتاج ميزةً مفعّلة في باقة المنشأة، وترجع **403** إن لم تكن مفعّلة:

| النقطة | الميزة المطلوبة |
|---|---|
| `GET /reports/agent-performance` | `agent_performance` |
| `GET /reports/activity-log` | `activity_log` |
| `DELETE /reports/ratings/{uuid}` | `rating_delete` |
| `GET` و`POST /settings/working-hours` | إضافة **Working Hours** |

**403 ليست عطلًا برمجيًا**: إمّا أن الصلاحية لا تسمح، وإمّا أن الباقة لا تتضمّن الميزة. اعرض `message` كما هو — نصّه مترجم وجاهز للعرض.

---

## 1) التقارير

### أداء الموظفين

```
GET /api/v1/reports/agent-performance?from=2026-01-01&to=2026-01-31
```

**شروط الاستخدام**

| الشرط | القيمة |
|---|---|
| من يستطيع | `owner` · `manager` — الموظّف يتلقّى **403** |
| شرط الباقة | ميزة `agent_performance` مفعّلة، وإلّا **403** |
| المعاملات | `from` و`to` بصيغة `YYYY-MM-DD`، كلاهما **اختياري** |
| بلا معاملات | آخر **30 يومًا** |
| الأخطاء | `403` صلاحية أو باقة |

```json
{
  "data": {
    "metrics": {
      "from": "2026-08-16 00:00:00",
      "to": "2026-09-14 23:59:59",
      "agents": [
        {
          "user_id": 50,
          "name": "أحمد صلاح",
          "email": "ahmed@example.com",
          "role": "manager",
          "online": true,
          "last_activity_at": "2026-09-14 10:22:00",
          "active_seconds": 18240,
          "messages_sent": 312,
          "tickets_assigned": 40,
          "tickets_closed": 35,
          "avg_first_response_seconds": 95,
          "avg_resolution_seconds": 5400
        }
      ]
    },
    "filters": { "from": "2026-08-16", "to": "2026-09-14" }
  }
}
```

| الحقل | النوع | المعنى |
|---|---|---|
| `online` | boolean | نشِط الآن، ويُحسب من آخر نبضة أرسلها التطبيق |
| `last_activity_at` | string \| **null** | آخر ظهور. يكون `null` لمن لم يعمل في المدّة المحدَّدة |
| `active_seconds` | int | مجموع الوقت النشط بالثواني |
| `messages_sent` | int | عدد الرسائل المُرسَلة في المدّة |
| `tickets_assigned` | int | التذاكر التي أُسندت إليه |
| `tickets_closed` | int | التذاكر التي أغلقها |
| `avg_first_response_seconds` | int \| **null** | متوسّط زمن أوّل ردّ. `null` إذا لم يردّ على شيء |
| `avg_resolution_seconds` | int \| **null** | متوسّط زمن إغلاق التذكرة |

### تقييمات العملاء

```
GET /api/v1/reports/ratings?rating=5&search=سارة&page=1&per_page=25
```

**شروط الاستخدام**

| الشرط | القيمة |
|---|---|
| من يستطيع | `owner` · `manager` — الموظّف يتلقّى **403** |
| شرط الباقة | لا يوجد |
| المعاملات | `rating` (1–5) · `search` · `page` · `per_page` — كلها **اختيارية** |
| الأخطاء | `403` للموظّف |

```json
{
  "data": {
    "items": [{
      "uuid": "7d3a1b90-2c44-4e8f-a1d5-33e9c0b7f412",
      "contact_name": "سارة",
      "contact_phone": "+966501234567",
      "agent_name": "أحمد",
      "rating": 5,
      "comment": "خدمة ممتازة",
      "submitted_at": "2026-09-14 10:00:00"
    }],
    "pagination": { "page": 1, "per_page": 25, "total": 40, "last_page": 2 },
    "summary": { "total": 40, "average": 4.35, "pending": 6 },
    "can_delete": true,
    "deletion_allowed_by_plan": true
  }
}
```

**مهم**: `summary` محسوب على **كامل النتائج المُرشَّحة** لا على الصفحة المعروضة — فلا يتغيّر المتوسّط بتقليب الصفحات. و`pending` عدد من أُرسل لهم طلب تقييم ولم يجيبوا بعد (لا يظهرون في `items`).

استعمل `can_delete` لإظهار زر الحذف أو إخفائه بدل تجربة الحذف ثم استقبال 403.

### حذف تقييم

```
DELETE /api/v1/reports/ratings/{uuid}
```

**شروط الاستخدام**

| الشرط | القيمة |
|---|---|
| من يستطيع | **`owner` وحده** — المدير يتلقّى **403** |
| شرط الباقة | ميزة `rating_delete` مفعّلة، وإلّا **403** |
| المعاملات | `uuid` التقييم في المسار — **مطلوب** |
| الأخطاء | `403` صلاحية أو باقة · `404` تقييم غير موجود أو يخصّ منشأة أخرى |

المدير يرى التقييمات ولا يحذفها — قد يكون هو الطرف الذي وقع عليه التقييم السيّئ.


### سجلّ النشاط

```
GET /api/v1/reports/activity-log?user_id=50&group=chats&event=message_sent&search=سارة&from=2026-09-01&to=2026-09-14
```

**شروط الاستخدام**

| الشرط | القيمة |
|---|---|
| من يستطيع | `owner` · `manager` — الموظّف يتلقّى **403** |
| شرط الباقة | ميزة `activity_log` مفعّلة، وإلّا **403** |
| المعاملات | `user_id` · `group` · `event` · `search` · `from` · `to` · `page` · `per_page` — كلها **اختيارية** |
| الأخطاء | `403` صلاحية أو باقة |

```json
{
  "data": {
    "items": [{
      "id": 1,
      "user_name": "أحمد صلاح",
      "user_id": 50,
      "event": "message_sent",
      "description": "أرسل رسالة إلى «سارة»",
      "subject_label": "سارة",
      "ip": "1.2.3.4",
      "created_at": "2026-09-14 10:00:00"
    }],
    "pagination": { "page": 1, "per_page": 50, "total": 620, "last_page": 13 },
    "members": [
      { "id": 50, "name": "أحمد صلاح" },
      { "id": 51, "name": "خالد العتيبي" }
    ],
    "groups": ["chats", "contacts", "campaigns", "settings", "team"],
    "retention_days": 7
  }
}
```

`members` و`groups` لتغذية المُرشِّحات بلا استدعاء إضافي. و`retention_days` اعرضها للمستخدم: السجلّ يُحذف تلقائيًا بعدها، فلا يبدو ناقصًا بلا سبب.

---

## 2) تذاكر الدعم

التذكرة في هذا النظام ليست كيانًا يكتبه العميل، بل **حالة المحادثة**: لكل جهة اتصال تذكرة واحدة «أحدث» تحمل حالتها ومن أُسندت إليه.

### القائمة

```
GET /api/v1/tickets?status=open&assigned_to=50&search=سارة
```

**شروط الاستخدام**

| الشرط | القيمة |
|---|---|
| من يستطيع | الجميع. **الموظّف يرى تذاكره وحدها** تلقائيًا بلا معامل |
| شرط الباقة | لا يوجد |
| المعاملات | `status` (`open`/`closed`) · `assigned_to` (معرّف أو `unassigned`) · `search` · `page` · `per_page` — كلها **اختيارية** |
| الأخطاء | لا أخطاء خاصّة |

```json
{
  "data": {
    "items": [{
      "id": 12094,
      "status": "open",
      "contact_id": 130835,
      "contact_uuid": "030b2120-fb32-4d14-8e5a-dd58eafddfd9",
      "contact_name": "سارة",
      "phone": "+966501234567",
      "assigned_to": 50,
      "assigned_to_name": "خالد العتيبي",
      "assigned_seen": false,
      "last_message_at": "2026-09-14 09:30:00",
      "updated_at": "2026-09-14 09:31:00"
    }],
    "pagination": { "page": 1, "per_page": 25, "total": 52, "last_page": 3 }
  }
}
```

| الحقل | النوع | ملاحظة |
|---|---|---|
| `status` | string | `open` أو `closed` |
| `assigned_to` | int \| **null** | `null` تعني تذكرة غير مُسندة إلى أحد |
| `assigned_to_name` | string \| **null** | اسم من أُسندت إليه، جاهز للعرض بلا استدعاء آخر |
| `assigned_seen` | boolean | هل فتحها من أُسندت إليه بعد الإسناد |
| `last_message_at` | string \| **null** | وقت آخر رسالة في المحادثة |
| `contact_name` | string \| **null** | يكون `null` إذا كانت جهة الاتصال بلا اسم |

**الموظّف (agent) يرى تذاكره وحدها** — نفس ما يحكم قائمة المحادثات. المالك والمدير يريان الكل.

### العدّادات

```
GET /api/v1/tickets/summary
```

**شروط الاستخدام**

| الشرط | القيمة |
|---|---|
| من يستطيع | الجميع. عدّاد الموظّف يُحسب على تذاكره وحدها |
| شرط الباقة | لا يوجد |
| المعاملات | لا يوجد |
| الأخطاء | لا أخطاء خاصّة |

```json
{ "data": { "open": 12, "closed": 40, "unassigned": 5 } }
```

وللموظّف تُحسب على تذاكره وحدها.

---

## 3) الملف الشخصي

### القراءة

```
GET /api/v1/profile
```

**شروط الاستخدام**

| الشرط | القيمة |
|---|---|
| من يستطيع | الجميع — كلٌّ يرى ملفّه هو |
| شرط الباقة | لا يوجد |
| المعاملات | لا يوجد |
| الأخطاء | لا أخطاء خاصّة |

```json
{
  "data": {
    "id": 50,
    "first_name": "أحمد",
    "last_name": "صلاح",
    "email": "a@example.com",
    "phone": "+966501234567",
    "avatar": "public/3p8CtCIzygRYIAhPwQRO7bxrbHHCFYzH1cnKX4M1.png",
    "language": "ar",
    "role": "owner",
    "organization": { "id": 211, "uuid": "4895c0cc-0366-4650-8aa9-ff83e6e36160", "name": "متجري" }
  }
}
```

`role` هنا **صلاحية المنشأة** (`owner`/`manager`/`agent`) لا صلاحية المنصّة — نفس ما تُرجعه `list-teams` بعد تعديلها.

### التعديل

```
PUT /api/v1/profile
{
  "first_name": "أحمد",
  "last_name": "صلاح",
  "email": "ahmed@example.com",
  "phone": "+966501234567",
  "language": "ar"
}
```

**شروط الاستخدام**

| الشرط | القيمة |
|---|---|
| من يستطيع | الجميع — كلٌّ يعدّل ملفّه هو |
| شرط الباقة | لا يوجد |
| المطلوب | `first_name` · `last_name` · `email` |
| الاختياري | `phone` · `language` |
| الأخطاء | `400` بريد مستعمَل من عضو آخر، أو حقل مطلوب ناقص |


### كلمة المرور

```
PUT /api/v1/profile/password
{
  "old_password": "كلمة المرور الحالية",
  "password": "كلمة المرور الجديدة",
  "password_confirmation": "كلمة المرور الجديدة"
}
```

**شروط الاستخدام**

| الشرط | القيمة |
|---|---|
| من يستطيع | الجميع — كلٌّ يغيّر كلمته هو |
| شرط الباقة | لا يوجد |
| المطلوب | `old_password` · `password` · `password_confirmation` |
| القيود | الجديدة **6 أحرف فأكثر**، ويجب أن تطابق التأكيد |
| الأخطاء | `400` كلمة قديمة خاطئة (مفتاح `old_password`) أو تأكيد غير مطابق |


---

## 4) الإعدادات العامّة

### القراءة

```
GET /api/v1/settings/general
```

**شروط الاستخدام**

| الشرط | القيمة |
|---|---|
| من يستطيع | الجميع — الموظّف يقرأ ولا يكتب |
| شرط الباقة | لا يوجد |
| المعاملات | لا يوجد |
| الأخطاء | `404` إن لم تكن هناك منشأة مختارة |

```json
{
  "data": {
    "organization": {
      "id": 211,
      "uuid": "4895c0cc-0366-4650-8aa9-ff83e6e36160",
      "name": "متجري",
      "address": {
        "street": "الروضة",
        "city": "جدة",
        "state": "Makkah",
        "zip": "23444",
        "country": "Saudi Arabia",
        "latitude": 21.5433,
        "longitude": 39.1728
      }
    },
    "timezone": "Asia/Riyadh",
    "notifications": {
      "enable_sound": true,
      "tone": "/sounds/message-pop-alert.mp3",
      "volume": 0.5
    },
    "campaigns": {
      "enable_resend": true,
      "resend_intervals": [5, 30],
      "move_failed_contacts_to_group": true,
      "failed_campaign_group": "9f1c2e40-7a11-4a3e-9d2b-1b7c5e8a4d30"
    },
    "support": { "ticket_form_url": "https://support.example.com/new" },
    "auth_template": {
      "uuid": "6a1f0e22-33bd-4a0e-9f2c-8d41b2c7e590",
      "name": "startchat",
      "language": "ar",
      "status": "APPROVED",
      "components": [
        { "type": "HEADER", "format": "TEXT", "text": "رسالة من متجرنا" },
        { "type": "BODY", "text": "أهلاً {{1}}، رمز التحقق هو {{2}}" },
        { "type": "FOOTER", "text": "شكراً لك" },
        { "type": "BUTTONS", "buttons": [ { "type": "URL", "text": "موقعنا", "url": "https://example.com" } ] }
      ],
      "parameters": { "template": "6a1f0e22-33bd-4a0e-9f2c-8d41b2c7e590", "body": { "parameters": [] } }
    },
    "contact_groups": [
      { "uuid": "9f1c2e40-7a11-4a3e-9d2b-1b7c5e8a4d30", "name": "عملاء مميزون" }
    ],
    "auth_templates": [
      { "uuid": "6a1f0e22-33bd-4a0e-9f2c-8d41b2c7e590", "name": "startchat", "language": "ar" }
    ],
    "timezones": [
      { "value": "Asia/Riyadh", "label": "Asia/Riyadh" },
      { "value": "Asia/Dubai", "label": "Asia/Dubai" }
    ],
    "sounds": [
      { "value": "/sounds/message-pop-alert.mp3", "label": "Message pop alert" },
      { "value": "/sounds/long-pop.wav", "label": "Long pop alert" }
    ]
  }
}
```

`timezones` و`sounds` قوائم الاختيار الجاهزة، فلا تحتاج استدعاءً آخر. **كلّ عنصر فيهما كائن `{value, label}` لا نصّ**: اعرض `label` وأرسل `value`.

و`notifications.tone` قيمته أحد `sounds[].value` — مسار ملف مثل `/sounds/message-pop-alert.mp3`، لا اسم مختصر.

**`contact_groups`** بدائل `campaigns.failed_campaign_group` — بها ترسم قائمة اختيار «مجموعة الحملات الفاشلة».

**`auth_templates`** بدائل قالب المصادقة: **القوالب المعتمدة (`APPROVED`) وحدها**، لأن غيرها تردّه Meta عند الإرسال. لا تبنِ هذه القائمة من `GET /list-templates` — تلك لا تُرشِّح بالحالة.

**`auth_template`** القالب المختار حاليًا، أو `null` إن لم يُختَر أو حُذف. و`parameters` متغيّراته المحفوظة، أو `null` إن كانت لقالب آخر — فالمُرسِل يُهملها عندها.

**`auth_template.components`** بنية القالب كما تحفظها Meta — بها تُرسم المعاينة:

| المكوّن | ما يحمله |
|---|---|
| `HEADER` | `format` و`text` (أو وسائط) |
| `BODY` | `text` المتن، وفيه `{{1}}` و`{{2}}` مواضع المتغيّرات |
| `FOOTER` | `text` |
| `BUTTONS` | `buttons[]` ولكل زرّ `type` و`text` و`url` |

وهي **للقالب المختار وحده**. `auth_templates` تبقى خفيفة (`uuid` · `name` · `language`) لأن ردّ الإعدادات يُستدعى عند كل فتح للشاشة.

`components` **مصفوفة دائمًا** — تكون `[]` إن كانت بنية القالب ناقصة، فمرّ عليها بحلقة بلا فحص `null`.

ولا تحتاج `GET /list-templates` إلا في حالة واحدة: أن يُبدّل المستخدم القالب ويريد معاينة قالبٍ لم يُحفظ بعد.

**`address` يُعاد كما هو محفوظ**، ويشمل `latitude` و`longitude` متى ضبطهما العميل من الويب. الحقول كلّها **للقراءة فقط** من التطبيق — العنوان والاسم والإحداثيات تُزامَن مع منصّة الفوترة فبقيت في الويب. ومنشأة بلا عنوان تُرجع `[]` لا `null`.

### التعديل

```
POST /api/v1/settings/general
{ "timezone": "Asia/Dubai", "notifications": { "tone": "chime" } }
```

**شروط الاستخدام**

| الشرط | القيمة |
|---|---|
| من يستطيع | `owner` · `manager` — الموظّف يتلقّى **403** |
| شرط الباقة | لا يوجد |
| الحقول | كلها **اختيارية**: `timezone` · `notifications` · `campaigns` · `support` · `auth_template` · `auth_template_parameters` |
| الإرسال | **جزئي** — ما لا تُرسله يبقى كما هو، حتى داخل القسم الواحد |
| غير متاح | اسم المنشأة وعنوانها وإحداثياتها — تبقى في الويب |
| الأخطاء | `403` للموظّف · `400` رابط دعم غير صالح، أو قالب غير معتمد، أو متغيّرات لقالب آخر |

سبب استثناء الاسم والعنوان: تغييرهما يُزامَن مع منصّة الفوترة وله أثر محاسبي.

مثال على الإرسال الجزئي: إرسال `notifications.tone` وحده يغيّر النغمة ويُبقي `volume` كما هو.

### قالب المصادقة

القالب الذي يُرسَل به رمز التحقق عبر `POST /api/v1/send-auth-template`.

```json
{
  "auth_template": "6a1f0e22-33bd-4a0e-9f2c-8d41b2c7e590",
  "auth_template_parameters": {
    "template": "6a1f0e22-33bd-4a0e-9f2c-8d41b2c7e590",
    "body": { "parameters": [] },
    "buttons": []
  }
}
```

**قواعده**

| الحالة | ما يحدث |
|---|---|
| `auth_template` من `auth_templates` | يُحفظ |
| قالب غير معتمد أو لمنشأة أخرى أو غير موجود | **400** |
| `auth_template: null` أو `""` | يُلغى الاختيار، وتُحذف متغيّراته معه |
| تبديل القالب بلا إرسال متغيّرات | متغيّرات القالب السابق تُحذف — لا تصلح للجديد |
| إعادة حفظ القالب نفسه بلا متغيّرات | متغيّراته المحفوظة **تبقى** |
| `auth_template_parameters` فيها `template` مخالف للمختار | **400** |
| `auth_template_parameters: []` | تُحذف المتغيّرات ويبقى القالب |

**`parameters.template` يجب أن يساوي `auth_template`.** المُرسِل يشترط ذلك، فمتغيّرات لا تطابقه كانت تُحفظ ثم تُهمَل بلا أثر — صارت تُردّ برسالة.


---

## 5) أوقات العمل

### القراءة

```
GET /api/v1/settings/working-hours
```

**شروط الاستخدام**

| الشرط | القيمة |
|---|---|
| من يستطيع | الجميع |
| شرط الباقة | إضافة **Working Hours** مفعّلة، وإلّا **403** |
| المعاملات | لا يوجد |
| الأخطاء | `403` الإضافة معطّلة |

```json
{
  "data": {
    "working_hours": [{ "day": 0, "open": "09:00", "close": "17:00" }],
    "working_hours_outside_message": "نعتذر، خارج الدوام"
  }
}
```

`day` من **0 (الأحد) إلى 6 (السبت)** — يوافق `date('w')` في PHP.

### الحفظ

```
POST /api/v1/settings/working-hours
{
  "slots": [
    { "day": 0, "open": "09:00", "close": "17:00" },
    { "day": 1, "open": "10:00", "close": "18:00" }
  ],
  "working_hours_outside_message": "نعود غدًا"
}
```

**شروط الاستخدام**

| الشرط | القيمة |
|---|---|
| من يستطيع | `owner` · `manager` — الموظّف يتلقّى **403** |
| شرط الباقة | إضافة **Working Hours** مفعّلة، وإلّا **403** |
| المطلوب داخل كل فترة | `day` (0–6) · `open` · `close` بصيغة `HH:MM` |
| الاختياري | `working_hours_outside_message` (4096 حرفًا كحدّ أقصى) |
| القيود | النهاية **بعد** البداية · 64 فترة كحدّ أقصى · `"slots": []` تُلغي الدوام |
| الأخطاء | `403` صلاحية أو إضافة · `400` نهاية قبل بداية، أو يوم أو وقت غير صالح |

للمالك والمدير. القواعد:

- أكثر من فترة لليوم الواحد مسموح.
- **النهاية بعد البداية إلزامًا** — وإلا 400، لأن فترة لا تُغلق تجعل المنشأة «خارج الدوام» دائمًا.
- صيغة الوقت `HH:MM` حصرًا.
- `"slots": []` تُلغي الدوام كلّه (مقصود).
- حدّ 64 فترة.

---

## 6) الأتمتة الأساسية (الردود الجاهزة)

ردٌّ يُرسل تلقائيًا حين تطابق رسالة العميل كلمة محدَّدة.

```
GET    /api/v1/automation/basic?search=فاتورة
GET    /api/v1/automation/basic/{uuid}
POST   /api/v1/automation/basic
PUT    /api/v1/automation/basic/{uuid}
DELETE /api/v1/automation/basic/{uuid}
```

**شروط الاستخدام**

| النقطة | من يستطيع | ملاحظات |
|---|---|---|
| `GET` القائمة | الجميع | `search` و`page` و`per_page` اختيارية |
| `GET` واحد | الجميع | `404` إن لم يوجد أو كان لمنشأة أخرى |
| `POST` إضافة | `owner` · `manager` | كل الحقول الخمسة **مطلوبة** |
| `PUT` تعديل | `owner` · `manager` | كل الحقول الخمسة **مطلوبة** — التعديل ليس جزئيًا |
| `DELETE` حذف | `owner` · `manager` | حذف **ناعم**: يختفي من القائمة ويبقى أثره |

لا شرط باقة على أيٍّ منها. والموظّف يقرأ ولا يكتب: الكتابة تُعيد له **403**.

### الحقول

```json
{
  "name": "الفواتير",
  "trigger": "فاتورة",
  "match_criteria": "contains",
  "response_type": "text",
  "response": "تجد فاتورتك في حسابك على الموقع، وإن احتجت مساعدة فاكتب لنا"
}
```

| الحقل | القيم |
|---|---|
| `match_criteria` | `exact match` أو `contains` |
| `response_type` | **`text` فقط في هذه النسخة** |

الصورة والصوت يحتاجان رفع ملف ولم يُدرجا بعد. الردود القديمة التي نوعها صورة أو صوت **تظهر في القائمة** بنوعها و`response = null` — اعرضها للقراءة ولا تفتحها للتحرير.


---

## 7) متغيّرات الرسائل

تقابل نافذة **«اختر متغيّر»** في الويب. يحتاجها موضعان في التطبيق: **رسالة خارج أوقات العمل** (النقطة 13)، و**نصّ الردّ الجاهز** (النقطتان 16 و17).

```
GET /api/v1/settings/placeholders
```

**شروط الاستخدام**

| النقطة | من يستطيع | ملاحظات |
|---|---|---|
| `GET` القائمة | الجميع | بلا معاملات · بلا ترقيم صفحات · بلا شرط باقة |

### الرد

```json
{
  "statusCode": 200,
  "success": true,
  "data": [
    { "value": "{first_name}", "label": "First name" },
    { "value": "{url:first_name}", "label": "First name (URL encoded)" },
    { "value": "{رقم_الطلب}", "label": "رقم الطلب" },
    { "value": "{url:رقم_الطلب}", "label": "رقم الطلب (URL encoded)" }
  ]
}
```

### كيف يستعملها التطبيق

اعرض `label` زرًّا، وعند الضغط أدرج `value` في موضع المؤشّر داخل نصّ الرسالة. **لا تستبدل شيئًا في التطبيق** — الاستبدال كلّه في الخادم وقت الإرسال، ببيانات جهة الاتصال التي ستصلها الرسالة.

### لماذا endpoint ولا تُكتب في التطبيق

القائمة نصفان: جزء ثابت (الاسم · البريد · الجوال · المجموعة · العنوان · اسم المنشأة…)، وجزء **يخصّ كل منشأة** — الحقول المخصّصة التي يعرّفها العميل في جهات الاتصال. الثاني يتغيّر متى أضاف العميل حقلًا، فحفظه في كود التطبيق يجعله يعرض متغيّرات لا وجود لها أو يُخفي متغيّرات موجودة.

`{url:...}` هي نسخة الحقل بعد ترميز URL — تُستعمل حين يوضع المتغيّر داخل رابط.

**تنبيه**: إن كان الحقل فارغًا عند جهة الاتصال، يصلها الرمز كما هو (`{email}`) — عدا `{group}` فيختفي. تجنّب المتغيّرات التي قد لا يملأها كل العملاء، أو اكتب نصًّا يحتمل فراغها.

---

## ملخّص النقاط

| # | النقطة | الصلاحية |
|---|---|---|
| 1 | `GET /reports/agent-performance` | owner · manager |
| 2 | `GET /reports/ratings` | owner · manager |
| 3 | `DELETE /reports/ratings/{uuid}` | **owner فقط** |
| 4 | `GET /reports/activity-log` | owner · manager |
| 5 | `GET /tickets` | الكل (الموظّف يرى تذاكره) |
| 6 | `GET /tickets/summary` | الكل |
| 7 | `GET /profile` | الكل |
| 8 | `PUT /profile` | الكل |
| 9 | `PUT /profile/password` | الكل |
| 10 | `GET /settings/general` | الكل |
| 11 | `POST /settings/general` | owner · manager |
| 12 | `GET /settings/working-hours` | الكل |
| 13 | `POST /settings/working-hours` | owner · manager |
| 14 | `GET /automation/basic` | الكل |
| 15 | `GET /automation/basic/{uuid}` | الكل |
| 16 | `POST /automation/basic` | owner · manager |
| 17 | `PUT /automation/basic/{uuid}` | owner · manager |
| 18 | `DELETE /automation/basic/{uuid}` | owner · manager |
| 19 | `GET /settings/placeholders` | الكل |

---

## ملاحظات للتنفيذ

**403 ليست خطأ برمجيًا.** تعني إمّا أن الصلاحية لا تسمح، أو أن الباقة لا تتضمّن الميزة. اعرض `message` كما هو — نصّه مترجم وجاهز.

**عزل المنشأة مضمون في كل نقطة.** بيانات منشأة أخرى لا تظهر ولا تُعدَّل ولو عُرف معرّفها، وتعود 404 لا 403 كي لا يُستدلّ على وجودها.

**لا تفترض وجود `data` في ردود الخطأ** — الغلاف يحمل `statusCode` و`success` و`message` فقط.
