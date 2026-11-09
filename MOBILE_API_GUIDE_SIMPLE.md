# دليل مبسّط: Campaigns و Team و Invite a friend

دليل سريع لمطوّر الموبايل. في كل endpoint هتلاقي: بيعمل إيه، الداتا اللي بتبعتها له، والداتا اللي بيرجّعها، وكل ده بأمثلة حقيقية.

---

## قبل ما تبدأ

**كل request لازم يكون فيه الـ headers دي:**

```
Authorization: Bearer <token اللي جالك من login>
Accept: application/json
Accept-Language: ar
```

**كل response بيرجع بنفس الشكل:**

```json
{
  "statusCode": 200,
  "success": true,
  "message": "تم إنشاء الحملة بنجاح!",
  "data": { }
}
```

**الـ response لما يحصل error:**

```json
{
  "statusCode": 400,
  "success": false,
  "message": "البيانات المقدمة غير صالحة.",
  "errors": {
    "name": ["حقل الاسم مطلوب."]
  }
}
```

| statusCode | معناه |
|---|---|
| 200 | تمام |
| 400 | الداتا اللي بعتها فيها غلط، والتفاصيل في `errors` |
| 403 | المستخدم ملوش صلاحية |
| 404 | الحاجة دي مش موجودة |
| 422 | العملية مش مسموحة دلوقتي، والسبب مكتوب في `message` |

اعرض `message` للمستخدم زي ما هو، لأنه بيرجع مترجم وجاهز.

**أي list بيرجع بالشكل ده:**

```json
"data": {
  "items": [ ],
  "pagination": { "page": 1, "per_page": 25, "total": 40, "last_page": 2 }
}
```

عشان تجيب الصفحة اللي بعدها: `?page=2`

---

# 1) Campaigns

متاحة لكل المستخدمين: owner و manager و agent.

**ترتيب شاشة "حملة جديدة":**

1. `GET /campaigns/options`: يجيب الـ templates والـ groups.
2. المستخدم يختار template، فتنادي `GET /campaigns/templates/{uuid}`.
3. المستخدم يملا القيم، وتبعت `POST /campaigns`.

---

### 1. قائمة الحملات

`GET /api/v1/campaigns?page=1`

**بتعمل إيه:** ترجّع كل الحملات ومعاها إحصائيات كل حملة.

**فلاتر اختيارية:**

| الفلتر | القيم |
|---|---|
| `search` | بالاسم |
| `status` | `scheduled` أو `ongoing` أو `completed` أو `failed` |

**بيرجع:**

```json
{
  "statusCode": 200,
  "success": true,
  "message": null,
  "data": {
    "items": [
      {
        "uuid": "902c67bf-3915-4e9b-a315-129e97e5ec73",
        "name": "عرض نهاية الشهر",
        "status": "completed",
        "failure_reason": null,
        "template": { "uuid": "20f54a8d-09d3-4c4f-8e52-2dd1f25b74a6", "name": "offer_100_sar", "language": "ar" },
        "audience": { "type": "group", "group": { "uuid": "773f6323-48a3-4eac-bd1d-cef76587faa2", "name": "عملاء الرياض" } },
        "stats": { "total": 200, "sent": 110, "delivered": 95, "read": 60, "failed": 90 },
        "scheduled_at": "2026-10-07T12:50:00+03:00",
        "created_at": "2026-10-07T12:45:10+03:00",
        "can_resend": true
      }
    ],
    "pagination": { "page": 1, "per_page": 10, "total": 1, "last_page": 1 }
  }
}
```

**معنى الحقول:**

| الحقل | المعنى |
|---|---|
| `status` | `scheduled` مستنية ميعادها، `ongoing` بتتبعت دلوقتي، `completed` خلصت، `failed` فشلت |
| `audience.type` | `all` كل العملاء، `group` مجموعة معيّنة |
| `can_resend` | لو `true` اعرض زرار "إعادة إرسال الفاشل" |

---

### 2. بيانات فورم الإنشاء

`GET /api/v1/campaigns/options`

**بتعمل إيه:** ترجّع كل اللي محتاجه عشان تملا فورم "حملة جديدة": الـ templates، والـ groups، والميعاد الحالي.

**بيرجع:**

```json
{
  "statusCode": 200,
  "success": true,
  "message": null,
  "data": {
    "templates": [
      { "uuid": "b7a31610-8151-4807-bb82-5b99df4fa699", "name": "chat", "language": "ar", "category": "MARKETING", "header_format": null },
      { "uuid": "20f54a8d-09d3-4c4f-8e52-2dd1f25b74a6", "name": "offer_100_sar", "language": "ar", "category": "MARKETING", "header_format": "IMAGE" }
    ],
    "contact_groups": [
      { "uuid": "773f6323-48a3-4eac-bd1d-cef76587faa2", "name": "عملاء الرياض", "contacts_count": 120 }
    ],
    "all_contacts_count": 2500,
    "dynamic_values": ["first name", "last name", "name", "phone", "email"],
    "timezone": "Asia/Riyadh",
    "now": "2026-11-05 14:20",
    "limits": { "campaign_limit": null, "used": 11, "remaining": null },
    "subscription_active": true
  }
}
```

**معنى الحقول:**

| الحقل | المعنى |
|---|---|
| `header_format` | نوع الـ header بتاع الـ template: `null` يعني مفيش header، أو `TEXT`، أو `IMAGE`، أو `VIDEO`، أو `DOCUMENT` |
| `contacts_count` | عدد اللي هتوصلهم الرسالة فعلاً |
| `now` | الوقت الحالي للشركة، واستخدمه كأقل ميعاد مسموح في اختيار الجدولة |
| `campaign_limit` | `null` يعني مفيش حد |

---

### 3. تفاصيل template معيّن

`GET /api/v1/campaigns/templates/b7a31610-8151-4807-bb82-5b99df4fa699`

**بتعمل إيه:** لما المستخدم يختار template، ترجّعلك شكله والخانات اللي المستخدم محتاج يملاها.

**بيرجع:**

```json
{
  "statusCode": 200,
  "success": true,
  "message": null,
  "data": {
    "template": { "uuid": "b7a31610-8151-4807-bb82-5b99df4fa699", "name": "chat", "language": "ar", "category": "MARKETING", "status": "APPROVED" },
    "parameters": {
      "header": { "format": null, "text": null, "parameters": [] },
      "body": {
        "text": "مرحبا {{1}} 🖐️ يطلب أحد موظفينا إذن التواصل معك",
        "parameters": [ { "type": "text", "selection": "static", "value": "فواز" } ]
      },
      "footer": { "text": "اضغط بدء المحادثة للسماح بالتواصل" },
      "buttons": [
        { "type": "QUICK_REPLY", "text": "بدء المحادثة", "value": null, "parameters": [ { "type": "static", "value": null } ] }
      ]
    },
    "dynamic_values": ["first name", "last name", "name", "phone", "email"]
  }
}
```

**إزاي تستخدمه:**

- كل `{{1}}` و`{{2}}` في النص ليها parameter، والمستخدم يملا الـ `value` بتاعها.
  - `selection: "static"`: نص ثابت يكتبه المستخدم، زي `"خصم 30%"`.
  - `selection: "dynamic"`: يتملى من بيانات كل عميل. الـ `value` بتبقى واحدة من `dynamic_values`، زي `"first name"`.
- زرار `QUICK_REPLY` لازم المستخدم يكتب له `value`، أقصاه 25 حرف.
- القيم اللي راجعة في الـ response أمثلة بس. اعرضها كـ placeholder.

---

### 4. إنشاء حملة

`POST /api/v1/campaigns`

**بتعمل إيه:** تعمل حملة جديدة، يا إما مجدولة على ميعاد يا إما تتبعت فوراً.

**بتبعت (JSON):** template من غير صورة. ده المثال الأشهر:

```json
{
  "name": "تذكير العملاء",
  "template": "b7a31610-8151-4807-bb82-5b99df4fa699",
  "contacts": "773f6323-48a3-4eac-bd1d-cef76587faa2",
  "skip_schedule": false,
  "time": "2026-12-01 10:30",
  "header": { "format": null, "parameters": [] },
  "body": {
    "parameters": [ { "type": "text", "selection": "dynamic", "value": "first name" } ]
  },
  "footer": { "text": "اضغط بدء المحادثة للسماح بالتواصل" },
  "buttons": [
    { "type": "QUICK_REPLY", "text": "بدء المحادثة", "value": null, "parameters": [ { "type": "static", "value": "start" } ] }
  ]
}
```

**معنى الحقول:**

| الحقل | المعنى |
|---|---|
| `contacts` | `"all"` لكل العملاء، أو الـ `uuid` بتاع group |
| `skip_schedule` | `true` يعني ابعت دلوقتي، و`false` يعني جدولة |
| `time` | ميعاد الإرسال بصيغة `YYYY-MM-DD HH:mm`، بتوقيت الشركة. مطلوب لو `skip_schedule = false` |
| `header` و`body` و`footer` و`buttons` | نفس اللي رجع من endpoint رقم 3، بعد ما المستخدم يملاه. ابعت كل الـ buttons بنفس ترتيبها |

**لو الـ template فيه صورة في الـ header** (`header_format = IMAGE`)، قدامك طريقتين:

- **صورة اترفعت قبل كده:** نفس JSON اللي فوق، والـ header بالشكل ده:

  ```json
  "header": {
    "format": "IMAGE",
    "parameters": [ { "type": "IMAGE", "selection": "history", "value": "d62fa9a5-7e40-4e9b-9852-b05e90e7fe3c" } ]
  }
  ```

  الـ `value` ده هو الـ `uuid` اللي جاي من endpoint رقم 8.

- **صورة جديدة من الموبايل:** ابعت الـ request كـ **form-data** بدل JSON:

  ```
  name                              = عرض نهاية الشهر
  template                          = 20f54a8d-09d3-4c4f-8e52-2dd1f25b74a6
  contacts                          = all
  skip_schedule                     = 0
  time                              = 2026-12-01 10:30
  header[format]                    = IMAGE
  header[parameters][0][type]       = IMAGE
  header[parameters][0][selection]  = upload
  header[parameters][0][value]      = (ملف الصورة)
  buttons[0][type]                  = QUICK_REPLY
  buttons[0][parameters][0][type]   = static
  buttons[0][parameters][0][value]  = stop
  ```

  حدود الملف:

  | النوع | الصيغة | أقصى حجم |
  |---|---|---|
  | صورة | png أو jpg | 5MB |
  | فيديو | mp4 | 16MB |
  | ملف | pdf وغيره | 100MB |

**بيرجع:**

```json
{
  "statusCode": 200,
  "success": true,
  "message": "تم إنشاء الحملة بنجاح!",
  "data": {
    "uuid": "0073043c-217f-440d-b52e-7541deb5664e",
    "name": "تذكير العملاء",
    "status": "scheduled",
    "failure_reason": null,
    "template": { "uuid": "b7a31610-8151-4807-bb82-5b99df4fa699", "name": "chat", "language": "ar" },
    "audience": { "type": "group", "group": { "uuid": "773f6323-48a3-4eac-bd1d-cef76587faa2", "name": "عملاء الرياض" } },
    "stats": { "total": 0, "sent": 0, "delivered": 0, "read": 0, "failed": 0 },
    "scheduled_at": "2026-12-01T10:30:00+03:00",
    "created_at": "2026-11-05T14:21:07+03:00",
    "can_resend": false
  }
}
```

**لو فيه غلط:**

```json
{
  "statusCode": 400,
  "success": false,
  "message": "البيانات المقدمة غير صالحة.",
  "errors": { "contacts": ["لا توجد جهات اتصال لإرسال هذه الحملة إليها."] }
}
```

---

### 5. تفاصيل حملة

`GET /api/v1/campaigns/902c67bf-3915-4e9b-a315-129e97e5ec73`

**بتعمل إيه:** ترجّع بيانات حملة واحدة، ومعاها القيم اللي اتبعتت بيها.

**بيرجع:** نفس شكل الحملة اللي في القائمة، ومتزوّد عليه حقلين:

```json
{
  "statusCode": 200,
  "success": true,
  "message": null,
  "data": {
    "uuid": "902c67bf-3915-4e9b-a315-129e97e5ec73",
    "name": "عرض نهاية الشهر",
    "status": "completed",
    "stats": { "total": 200, "sent": 110, "delivered": 95, "read": 60, "failed": 90 },
    "can_resend": true,
    "resendable_count": 85,
    "metadata": {
      "header": { "format": "IMAGE", "parameters": [ { "type": "IMAGE", "selection": "history", "value": "https://cdn.mnjz.net/media/offer.jpg" } ] },
      "body": { "parameters": [] },
      "footer": null,
      "buttons": [ { "type": "QUICK_REPLY", "text": "إيقاف الإعلانات؟", "parameters": [ { "type": "static", "value": "stop" } ] } ]
    }
  }
}
```

| الحقل | المعنى |
|---|---|
| `resendable_count` | عدد الرسائل اللي هتتعاد لو المستخدم داس "إعادة إرسال الفاشل". اكتبه على الزرار |

---

### 6. العملاء اللي اتبعتلهم الحملة

`GET /api/v1/campaigns/902c67bf-3915-4e9b-a315-129e97e5ec73/logs?status=failed`

**بتعمل إيه:** ترجّع كل عميل اتبعتله الحملة، وحالة رسالته، وسبب الفشل لو فشلت.

**فلاتر اختيارية:**

| الفلتر | القيم |
|---|---|
| `status` | `pending` أو `sent` أو `delivered` أو `read` أو `failed` |
| `search` | بالاسم أو الرقم |

**بيرجع:**

```json
{
  "statusCode": 200,
  "success": true,
  "message": null,
  "data": {
    "items": [
      {
        "id": 1064938,
        "contact": { "uuid": "5b1e0c3a-9d2f-4a7e-8c11-2f6d9e4a7b10", "name": "خالد العتيبي", "phone": "+966551234567" },
        "status": "failed",
        "retry_count": 0,
        "error": {
          "code": 131049,
          "reason": "لم تُسلّم واتساب هذه الرسالة التسويقية كي لا يستقبل المستلِم رسائل كثيرة. أعد المحاولة لاحقاً، أو انتظر أن يراسلك العميل أولاً.",
          "raw": "This message was not delivered to maintain healthy ecosystem engagement."
        },
        "sent_at": "2026-10-07T12:52:07+03:00"
      },
      {
        "id": 1064939,
        "contact": { "uuid": "8a2f4c6e-1b3d-4e5f-9a7b-3c5d7e9f1a2b", "name": "نورة القحطاني", "phone": "+966509876543" },
        "status": "read",
        "retry_count": 0,
        "error": null,
        "sent_at": "2026-10-07T12:52:09+03:00"
      }
    ],
    "pagination": { "page": 1, "per_page": 25, "total": 90, "last_page": 4 }
  }
}
```

**معنى الحقول:**

| الحقل | المعنى |
|---|---|
| `error` | بيرجع `null` إلا لو الرسالة فشلت |
| `error.reason` | سبب الفشل مترجم، اعرضه للمستخدم |
| `error.code` | كود خطأ واتساب |

---

### 7. إعادة إرسال الرسائل الفاشلة

`POST /api/v1/campaigns/902c67bf-3915-4e9b-a315-129e97e5ec73/resend-failed`

**بتعمل إيه:** تعيد إرسال الرسائل اللي فشلت. الرسائل اللي فشلها نهائي، زي رقم مش على واتساب، مش بتتعاد.

**بتبعت:** مفيش body.

**بيرجع:**

```json
{
  "statusCode": 200,
  "success": true,
  "message": "تمت جدولة 85 رسالة حملة فاشلة لإعادة الإرسال.",
  "data": { "queued": 85, "status": "ongoing" }
}
```

**لو مينفعش:**

```json
{ "statusCode": 422, "success": false, "message": "لا توجد رسائل حملة فاشلة لإعادة الإرسال." }
```

---

### 8. الصور والملفات اللي اترفعت قبل كده

`GET /api/v1/campaigns/media-history?type=IMAGE`

**بتعمل إيه:** ترجّع آخر الملفات اللي اترفعت في حملات قبل كده، عشان المستخدم يختار منها بدل ما يرفع تاني.

**القيم المتاحة لـ `type`:** `IMAGE` أو `VIDEO` أو `DOCUMENT`

**بيرجع:**

```json
{
  "statusCode": 200,
  "success": true,
  "message": null,
  "data": [
    {
      "uuid": "d62fa9a5-7e40-4e9b-9852-b05e90e7fe3c",
      "name": "اعلان العرض.jpg",
      "path": "https://cdn.mnjz.net/uploads/media/sent/40/offer.jpg",
      "media_type": "IMAGE",
      "size": "245760",
      "created_at": "2026-10-01 09:15:00"
    }
  ]
}
```

اعرض الصورة من `path`. ولما المستخدم يختار واحدة، ابعت الـ `uuid` بتاعها في endpoint رقم 4 بـ `selection: "history"`.

**حذف ملف من القائمة:** `DELETE /api/v1/campaigns/media-history/d62fa9a5-7e40-4e9b-9852-b05e90e7fe3c`

---

### 9. حذف حملة

`DELETE /api/v1/campaigns/0073043c-217f-440d-b52e-7541deb5664e`

**بتعمل إيه:** تحذف الحملة. لو كانت لسه بتتبعت، الإرسال بيقف.

**بيرجع:**

```json
{ "statusCode": 200, "success": true, "message": "تم حذف البيانات بنجاح!", "data": null }
```

---

# 2) Team (إضافة موظف)

متاحة لـ owner و manager بس. الـ agent بياخد 403.

**الأدوار:**

| الدور | المعنى |
|---|---|
| `owner` | صاحب الحساب |
| `manager` | مدير |
| `agent` | موظف |

---

### 1. قائمة الفريق

`GET /api/v1/team`

**بتعمل إيه:** ترجّع:
- أعضاء الفريق.
- الدعوات اللي لسه متقبلتش.
- عدد الأعضاء المسموح في الباقة.

**فلاتر اختيارية:**

| الفلتر | المعنى |
|---|---|
| `trashed=1` | يعرض الأعضاء المحذوفين |
| `search` | بالاسم أو الإيميل |

**بيرجع:**

```json
{
  "statusCode": 200,
  "success": true,
  "message": null,
  "data": {
    "items": [
      {
        "uuid": "998b2f10-0c29-4fa9-b33b-4daa3833fbb5",
        "user": { "id": 2, "first_name": "فواز", "last_name": "الشريف", "name": "فواز الشريف", "email": "fawaz@example.com", "avatar": null },
        "role": "owner",
        "status": "active",
        "is_owner": true,
        "is_me": true,
        "deleted": false,
        "deleted_at": null,
        "joined_at": "2025-03-01T09:00:00+00:00",
        "can_edit": false,
        "can_delete": false,
        "can_restore": false
      },
      {
        "uuid": "e38bf93b-998f-4d5e-b208-811dbaf1a63c",
        "user": { "id": 15, "first_name": "سارة", "last_name": "أحمد", "name": "سارة أحمد", "email": "sara@example.com", "avatar": null },
        "role": "agent",
        "status": "active",
        "is_owner": false,
        "is_me": false,
        "deleted": false,
        "deleted_at": null,
        "joined_at": "2026-09-10T11:30:00+00:00",
        "can_edit": true,
        "can_delete": true,
        "can_restore": false
      }
    ],
    "pagination": { "page": 1, "per_page": 25, "total": 2, "last_page": 1 },
    "limits": { "team_limit": 5, "used": 2, "remaining": 3 },
    "roles": ["manager", "agent"],
    "pending_invites": [
      { "id": 12, "email": "ali@example.com", "role": "agent", "invited_by": "فواز", "expires_at": "2026-11-06T14:20:00+00:00", "expired": false }
    ]
  }
}
```

**معنى الحقول:**

| الحقل | المعنى |
|---|---|
| `uuid` | الـ id اللي بتستخدمه في تعديل الموظف وحذفه واستعادته |
| `can_edit` و`can_delete` و`can_restore` | استخدمهم عشان تظهر أو تخفي الأزرار |
| `team_limit` | `null` يعني مفيش حد |
| `roles` | الأدوار اللي ينفع تختار منها في الدعوة |

---

### 2. دعوة موظف

`POST /api/v1/team/invite`

**بتعمل إيه:** تبعت إيميل دعوة للموظف. الموظف يدوس على الرابط اللي في الإيميل ويعمل حسابه. الرابط صالح يوم واحد.

**بتبعت:**

```json
{
  "email": "ali@example.com",
  "role": "agent"
}
```

**القيم المتاحة لـ `role`:** `manager` أو `agent`

**بيرجع:**

```json
{
  "statusCode": 200,
  "success": true,
  "message": "تمت دعوة المستخدم بنجاح!",
  "data": {
    "invite": { "id": 12, "email": "ali@example.com", "role": "agent", "invited_by": "فواز", "expires_at": "2026-11-06T14:20:00+00:00", "expired": false },
    "email_sent": true
  }
}
```

**`email_sent`:** لو `false` يبقى الإيميل متبعتش لأن إعدادات الإيميل مش متفعّلة. بلّغ المستخدم.

**لو الموظف موجود في الفريق أصلاً:**

```json
{
  "statusCode": 400,
  "success": false,
  "message": "البيانات المقدمة غير صالحة.",
  "errors": { "email": ["يوجد مستخدم بهذا البريد في فريقك بالفعل."] }
}
```

---

### 3. إعادة إرسال الدعوة

`POST /api/v1/team/invites/12/resend`

**بتعمل إيه:** تبعت إيميل الدعوة تاني، ولو الرابط القديم انتهى بتعمل رابط جديد.

**بتبعت:** مفيش body.

**بيرجع:**

```json
{
  "statusCode": 200,
  "success": true,
  "message": "تم إرسال الدعوة مرة أخرى.",
  "data": {
    "invite": { "id": 12, "email": "ali@example.com", "role": "agent", "invited_by": "فواز", "expires_at": "2026-11-06T15:00:00+00:00", "expired": false },
    "email_sent": true
  }
}
```

---

### 4. إلغاء الدعوة

`DELETE /api/v1/team/invites/12`

**بتعمل إيه:** تلغي الدعوة، والرابط اللي اتبعت يبطل.

**بيرجع:**

```json
{ "statusCode": 200, "success": true, "message": "تم إلغاء الدعوة.", "data": null }
```

---

### 5. تغيير دور موظف

`PUT /api/v1/team/e38bf93b-998f-4d5e-b208-811dbaf1a63c`

**بتعمل إيه:** تغيّر دور الموظف، مثلاً من `agent` لـ `manager`.

**بتبعت:**

```json
{ "role": "manager" }
```

**بيرجع:** بيانات الموظف بعد التعديل، بنفس شكل العنصر اللي في القائمة:

```json
{
  "statusCode": 200,
  "success": true,
  "message": "تم تحديث حساب المستخدم بنجاح!",
  "data": {
    "uuid": "e38bf93b-998f-4d5e-b208-811dbaf1a63c",
    "user": { "id": 15, "name": "سارة أحمد", "email": "sara@example.com" },
    "role": "manager",
    "can_edit": true,
    "can_delete": true
  }
}
```

**ممنوع تغيّر دور الـ owner أو دورك انت:**

```json
{ "statusCode": 422, "success": false, "message": "لا يمكنك تغيير صلاحيتك بنفسك." }
```

---

### 6. حذف موظف

`DELETE /api/v1/team/e38bf93b-998f-4d5e-b208-811dbaf1a63c`

**بتعمل إيه:** تشيل الموظف من الفريق. ينفع ترجّعه بعدين بالـ endpoint اللي بعده.

**بيرجع:**

```json
{ "statusCode": 200, "success": true, "message": "تم حذف البيانات بنجاح!", "data": null }
```

مينفعش تحذف الـ owner أو تحذف نفسك. الاتنين بيرجعوا 422.

---

### 7. استعادة موظف محذوف

`POST /api/v1/team/e38bf93b-998f-4d5e-b208-811dbaf1a63c/restore`

**بتعمل إيه:** ترجّع موظف محذوف للفريق.

**بتبعت:** مفيش body.

**بيرجع:** بيانات الموظف:

```json
{
  "statusCode": 200,
  "success": true,
  "message": "تمت استعادة العضو بنجاح!",
  "data": { "uuid": "e38bf93b-998f-4d5e-b208-811dbaf1a63c", "role": "agent", "deleted": false, "can_restore": false }
}
```

---

# 3) Invite a friend

متاحة لكل المستخدمين، وموجودة في الموبايل بس.

**الفكرة:**
- كل مستخدم ليه رابط خاص بيه، بيشاركه مع أصحابه.
- الصاحب يفتح الرابط ويسجّل في منجز.
- التسجيل بيتحسب للمستخدم اللي شارك الرابط.

---

### 1. رابط الدعوة بتاعي

`GET /api/v1/referral`

**بتعمل إيه:** ترجّع الرابط الخاص بالمستخدم، ونص جاهز للمشاركة، وعدد اللي سجّلوا بالرابط.

**بيرجع:**

```json
{
  "statusCode": 200,
  "success": true,
  "message": null,
  "data": {
    "code": "P7BGQVN9",
    "link": "https://app.mnjz.net/r/P7BGQVN9",
    "share_text": "أدِر كل محادثات عملائك على واتساب في مكان واحد مع منجز. سجّل من رابطي: https://app.mnjz.net/r/P7BGQVN9",
    "stats": { "total": 4, "subscribed": 1 }
  }
}
```

**إزاي تستخدم الحقول:**

| الحقل | استخدامه |
|---|---|
| `share_text` | ابعته للـ share sheet على طول |
| `link` | لزرار "نسخ الرابط" |
| `stats.total` | عدد اللي سجّلوا |
| `stats.subscribed` | عدد اللي اشتركوا في باقة مدفوعة |

---

### 2. اللي سجّلوا برابطي

`GET /api/v1/referral/referrals?page=1`

**بتعمل إيه:** ترجّع قائمة الناس اللي سجّلوا عن طريق رابط المستخدم.

**بيرجع:**

```json
{
  "statusCode": 200,
  "success": true,
  "message": null,
  "data": {
    "items": [
      { "id": 3, "name": "عمر علي", "email": "o•••••@example.com", "status": "subscribed", "joined_at": "2026-11-02T08:15:00+00:00" },
      { "id": 2, "name": "منى سالم", "email": "m•••@example.com", "status": "registered", "joined_at": "2026-10-28T19:40:00+00:00" }
    ],
    "pagination": { "page": 1, "per_page": 25, "total": 2, "last_page": 1 }
  }
}
```

**قيم `status`:**

| القيمة | المعنى |
|---|---|
| `registered` | سجّل بس |
| `subscribed` | سجّل واشترك في باقة مدفوعة |

الإيميل بيرجع مخفي جزء منه، وده مقصود.
