# الحملات والفريق وادعُ صديقاً — نقاط تطبيق الجوال

ثلاثة أقسام كانت في الويب وحده (أو غير موجودة أصلاً):

- **الحملات**: 10 نقاط.
- **الفريق**: 7 نقاط.
- **ادعُ صديقاً**: نقطتان، وهي للتطبيق وحده ولا شاشة لها في لوحة التحكم.

كلها تحت `/api/v1`، وبالتوثيق والغلاف والترقيم نفسها الموصوفة في [`MOBILE_NEW_ENDPOINTS.md`](MOBILE_NEW_ENDPOINTS.md#المشترك-بين-كل-النقاط):

```
Authorization: Bearer <sanctum token>
Accept: application/json
Accept-Language: ar        // أو en — لغة الرسائل والأخطاء
```

وللتجربة على localhost: **Postman collection** جاهزة. التفاصيل في [قسم الاختبار](#الاختبار).

---

## الصلاحيات

| القسم | `owner` | `manager` | `agent` |
|---|---|---|---|
| الحملات | كل شيء | كل شيء | كل شيء (كالويب) |
| الفريق | كل شيء | كل شيء | **403** على كل النقاط |
| ادعُ صديقاً | ✓ | ✓ | ✓ (الرابط شخصي) |

**قيود إضافية:**
- إنشاء حملة وإعادة إرسال فاشلها يحتاجان اشتراكاً فعّالاً.
- الحملات محكومة بـ `campaign_limit`، والدعوة واستعادة عضو محكومتان بـ `team_limit`.
- عند بلوغ الحدّ يصل الخطأ برسالة مترجمة جاهزة للعرض.

---

## 1) الحملات

```
GET    /api/v1/campaigns                       القائمة
GET    /api/v1/campaigns/options               ما يحتاجه نموذج الإنشاء
GET    /api/v1/campaigns/templates/{uuid}      هيكل معاملات القالب
GET    /api/v1/campaigns/media-history?type=   ملفات الترويسة السابقة
DELETE /api/v1/campaigns/media-history/{uuid}
POST   /api/v1/campaigns                       إنشاء
GET    /api/v1/campaigns/{uuid}                التفاصيل
GET    /api/v1/campaigns/{uuid}/logs           المستلمون وحالة كل رسالة
POST   /api/v1/campaigns/{uuid}/resend-failed  إعادة إرسال الفاشل
DELETE /api/v1/campaigns/{uuid}                حذف
```

### تسلسل شاشة الإنشاء

1. `GET /campaigns/options` لعرض القوالب والمجموعات.
2. يختار المستخدم قالباً، فتنادي `GET /campaigns/templates/{uuid}` وتحصل على هيكل جاهز.
3. يملأ المستخدم القيم.
4. `POST /campaigns` بالهيكل نفسه.

### `GET /campaigns/options`

```jsonc
{
  "templates": [
    { "uuid": "…", "name": "welcome_offer", "language": "ar", "category": "MARKETING", "header_format": "TEXT" }
  ],
  "contact_groups": [ { "uuid": "…", "name": "VIP", "contacts_count": 120 } ],
  "all_contacts_count": 5400,
  "dynamic_values": ["first name", "last name", "name", "phone", "email"],
  "timezone": "Asia/Riyadh",
  "now": "2026-11-05 14:20",
  "limits": { "campaign_limit": 10, "used": 3, "remaining": 7 },
  "subscription_active": true
}
```

**ملاحظات على الحقول:**
- **القوالب** المعتمدة (`APPROVED`) فقط. قيمة `header_format` واحدة من: `TEXT` | `IMAGE` | `VIDEO` | `DOCUMENT` | `null`.
- **`contacts_count`** هو عدد من سيصلهم فعلاً: يستثني المحذوفين ومن أوقفوا الرسائل التسويقية.
- **`campaign_limit`**: القيمة `null` تعني بلا حدّ.
- **`now`** هو وقت المنشأة الحالي. استعمله لتحديد أقلّ قيمة يقبلها حقل وقت الجدولة.

### `GET /campaigns/templates/{uuid}`

```jsonc
{
  "template": { "uuid": "…", "name": "welcome_offer", "language": "ar", "category": "MARKETING", "status": "APPROVED" },
  "parameters": {
    "header": { "format": "TEXT", "text": "عرض {{1}}",
                "parameters": [ { "type": "text", "selection": "static", "value": "الصيف" } ] },
    "body":   { "text": "مرحباً {{1}}، خصم {{2}}",
                "parameters": [ { "type": "text", "selection": "static", "value": "سارة" },
                                { "type": "text", "selection": "static", "value": "20%" } ] },
    "footer": { "text": "منجز" },
    "buttons": [
      { "type": "QUICK_REPLY", "text": "إيقاف", "value": null, "parameters": [ { "type": "static", "value": null } ] },
      { "type": "URL", "text": "تسوّق", "value": "https://shop.test/{{1}}",
        "parameters": [ { "type": "static", "value": "https://shop.test/summer" } ] }
    ]
  },
  "dynamic_values": ["first name", "last name", "name", "phone", "email"]
}
```

القيم المبدئية أمثلةٌ من Meta، فاعرضها placeholder ليغيّرها المستخدم. وهذا ما يملؤه التطبيق:

| الموضع | المفتاح | القيم |
|---|---|---|
| `header` نصّية، `body` | `selection` | `static` يعني أن `value` نصّ يكتبه المستخدم. `dynamic` يعني أن `value` واحدة من `dynamic_values` وتُملأ من بيانات كل جهة اتصال |
| `buttons[].parameters` | `type` | `static` أو `dynamic`، بالمعنى نفسه |
| ترويسة وسائط | `selection` | `upload` (ملف في الطلب نفسه)، أو `history` (قيمة `value` = uuid من media-history)، أو `default` (قيمة `value` = رابط مباشر) |

**قواعد الأزرار:**
- أرسل **كل** أزرار القالب بترتيبها، حتى ما ليس له معاملات: Meta تعرف الزر برقم موضعه.
- معامل `QUICK_REPLY` مطلوب، وطوله حتى 25 حرفاً.
- معامل `COPY_CODE` حتى 15 حرفاً.
- معامل `URL` يجب أن يكون رابطاً صحيحاً.

**ترويسة الوسائط:**
- لها معامل واحد دائماً.
- الحقل `example_url` رابط مؤقّت للمعاينة فقط، ولا يُرسل كـ `value` في حملة حقيقية.

### `POST /campaigns`

```json
{
  "name": "حملة الشتاء",
  "template": "<template uuid>",
  "contacts": "all",
  "skip_schedule": false,
  "time": "2026-12-01 10:30",
  "header":  { "format": "TEXT", "parameters": [ { "type": "text", "selection": "static", "value": "الشتاء" } ] },
  "body":    { "parameters": [ { "type": "text", "selection": "dynamic", "value": "first name" },
                               { "type": "text", "selection": "static",  "value": "30%" } ] },
  "footer":  { "text": "منجز" },
  "buttons": [ { "type": "QUICK_REPLY", "text": "إيقاف", "value": null, "parameters": [ { "type": "static", "value": "STOP" } ] },
               { "type": "URL", "text": "تسوّق", "value": "https://shop.test/{{1}}",
                 "parameters": [ { "type": "static", "value": "https://shop.test/winter" } ] } ]
}
```

| الحقل | القواعد |
|---|---|
| `name` | مطلوب، حتى 128 حرفاً، ويخضع لـ `campaign_limit` |
| `template` | uuid قالب في المنشأة نفسها |
| `contacts` | `all`، أو uuid مجموعة. الجمهور الفارغ يُرفض بـ 400 |
| `skip_schedule` | `true` للإرسال **فوراً**. أي قيمة أخرى أو غياب الحقل يعني جدولة |
| `time` | مطلوب حين لا يكون `skip_schedule` بقيمة true. الصيغة `Y-m-d H:i` **بتوقيت المنشأة** (`options.timezone`) |
| `header` و`body` و`footer` و`buttons` | الهيكل من النقطة السابقة. يجوز حذف `header` للقالب الذي بلا ترويسة |

**رفع ملف الترويسة**: أرسل الطلب `multipart/form-data` بالمفاتيح نفسها بصيغة الأقواس:

```
name=حملة فيديو
template=<uuid>
contacts=<group uuid>
skip_schedule=0
time=2026-12-01 10:30
header[format]=VIDEO
header[parameters][0][type]=VIDEO
header[parameters][0][selection]=upload
header[parameters][0][value]=<file>
body[parameters][0][type]=text
body[parameters][0][selection]=static
body[parameters][0][value]=…
```

حدود الملف:

| النوع | الصيغ | الحجم الأقصى |
|---|---|---|
| صورة | png، jpg | 5MB |
| فيديو | mp4 | 16MB |
| مستند | pdf، doc(x)، xls(x)، ppt(x)، txt | 100MB |

الملف المرفوع يُضاف إلى media-history، فيظهر للاختيار في الحملات التالية بـ `selection: history`.

**الردّ:** الحملة كما تظهر في القائمة (الوصف أدناه)، والرسالة `Campaign created successfully!`.

**الأخطاء:** 400 ومعه `errors`، والقواعد هي نفسها قواعد الويب:
- الحقول ناقصة.
- ملف الترويسة مفقود أو مخالف.
- الجمهور فارغ.
- القالب أو المجموعة ليسا من المنشأة.
- بلوغ `campaign_limit`، ويظهر الخطأ على حقل `name`.

### القائمة والتفاصيل

```
GET /api/v1/campaigns?page=1&per_page=10&search=&status=
```

قيمة `status` واحدة من: `scheduled` | `ongoing` | `completed` | `failed`. والحدّ الأقصى لـ `per_page` هو 50.

```jsonc
{
  "uuid": "…",
  "name": "حملة الشتاء",
  "status": "scheduled",
  "failure_reason": null,          // "no_contacts" حين تفشل الحملة لأن جمهورها فارغ
  "template": { "uuid": "…", "name": "welcome_offer", "language": "ar" },
  "audience": { "type": "group", "group": { "uuid": "…", "name": "VIP" } },   // أو { "type": "all", "group": null }
  "stats": { "total": 120, "sent": 118, "delivered": 110, "read": 75, "failed": 2 },
  "scheduled_at": "2026-12-01T10:30:00+03:00",
  "created_at": "2026-11-05T14:21:07+03:00",
  "can_resend": false
}
```

**ما تضيفه نقطة التفاصيل `GET /campaigns/{uuid}` على هذا:**
- `metadata`: معاملات القالب كما حُفظت.
- `resendable_count`: عدد الرسائل التي ستُعاد فعلاً لو ضُغط زرّ إعادة الإرسال.

التواريخ كلها بتوقيت المنشأة بصيغة ISO 8601. والحملة المجدولة ليس لها سجلّات بعد: إحصاءاتها أصفار حتى يحين وقتها.

### المستلمون — `GET /campaigns/{uuid}/logs`

```
?page=1&per_page=25&status=failed&search=سارة
```

قيمة `status` واحدة من: `pending` | `sent` | `delivered` | `read` | `failed`. أمّا `search` فيبحث في الاسم والرقم.

```jsonc
{
  "id": 9812,
  "contact": { "uuid": "…", "name": "خالد", "phone": "+966501234567" },
  "status": "failed",
  "retry_count": 1,
  "error": {
    "code": 131049,
    "reason": "لم يسلّم واتساب هذه الرسالة التسويقية…",
    "raw": "Ecosystem — Not delivered"
  },
  "sent_at": "2026-11-05T14:30:02+03:00"
}
```

**حقل `error`:**
- قيمته `null` إلا في الرسائل الفاشلة.
- `reason` شرحٌ مترجَم، وهو الشرح نفسه الذي يعرضه الويب. للكود المجهول يصل نصّ واتساب كما هو.

### إعادة إرسال الفاشل — `POST /campaigns/{uuid}/resend-failed`

بلا جسم. يعيد الفاشل **القابل للإعادة** وحده، ويستثني الفشل النهائي: 131026 و131050 و131047 و130472.

| الحالة | الردّ |
|---|---|
| أُعيد شيء | `200`، ومعه `{ "queued": 5, "status": "ongoing" }` |
| الحملة مجدولة أو فشلت | `422` «This campaign cannot be resent.» |
| لا يوجد فاشل يُعاد | `422` «No failed campaign messages to resend.» |

### الحذف — `DELETE /campaigns/{uuid}`

حذف ناعم. الإرسال يتوقّف إن كانت الحملة جارية، وتختفي من القائمة.

### ملفات الترويسة السابقة

- **`GET /campaigns/media-history?type=IMAGE`**: قيمة `type` واحدة من `IMAGE` | `VIDEO` | `DOCUMENT`. تعيد آخر 30 ملفاً، كل عنصر بالشكل `{ uuid, name, path, media_type, size, created_at }`.
- **`DELETE /campaigns/media-history/{uuid}`**: يحذف الملف من القائمة.

---

## 2) الفريق (إضافة موظف)

```
GET    /api/v1/team                     الأعضاء + الحدود + الدعوات المعلّقة
POST   /api/v1/team/invite              دعوة بالبريد
POST   /api/v1/team/invites/{id}/resend إعادة إرسال الدعوة
DELETE /api/v1/team/invites/{id}        إلغاء الدعوة
PUT    /api/v1/team/{uuid}              تغيير الدور
DELETE /api/v1/team/{uuid}              إزالة عضو
POST   /api/v1/team/{uuid}/restore      استعادة عضو
```

**للمالك والمدير فقط**. الموظّف يحصل على 403 من كل النقاط، وهذا هو سلوك الويب نفسه. النقطة القديمة `GET /list-teams` لم تتغيّر، وهي ما يُستعمل لاختيار موظّف عند إسناد محادثة.

### `GET /team`

```
?page=1&per_page=25&trashed=1&search=
```

`trashed=1` يعرض الأعضاء المحذوفين.

```jsonc
{
  "items": [
    {
      "uuid": "…",                    // معرّف العضوية — يُستعمل في PUT/DELETE/restore
      "user": { "id": 7, "first_name": "سارة", "last_name": "أحمد", "name": "سارة أحمد", "email": "sara@x.com", "avatar": null },
      "role": "agent",                // owner | manager | agent
      "status": "active",
      "is_owner": false,
      "is_me": false,
      "deleted": false,
      "deleted_at": null,
      "joined_at": "2026-10-01T09:00:00+00:00",
      "can_edit": true,
      "can_delete": true,
      "can_restore": false
    }
  ],
  "pagination": { "page": 1, "per_page": 25, "total": 4, "last_page": 1 },
  "limits": { "team_limit": 5, "used": 4, "remaining": 1 },   // team_limit = null يعني بلا حدّ
  "roles": ["manager", "agent"],
  "pending_invites": [
    { "id": 12, "email": "new@x.com", "role": "agent", "invited_by": "Ahmed", "expires_at": "…", "expired": false }
  ]
}
```

استعمل `can_edit` و`can_delete` و`can_restore` لإظهار الأزرار وإخفائها. المالك وأنت لا يُعدَّلان ولا يُحذفان.

### الدعوة — `POST /team/invite`

```json
{ "email": "new.agent@example.com", "role": "agent" }
```

| الحقل | القواعد |
|---|---|
| `email` | بريد صحيح، ولا ينتمي لعضو نشط في الفريق. يخضع لـ `team_limit` |
| `role` | `manager` أو `agent` |

**الردّ:**

```json
{ "invite": { "id": 12, "email": "new.agent@example.com", "role": "agent", "invited_by": "Ahmed", "expires_at": "…", "expired": false }, "email_sent": true }
```

**كيف تكتمل الدعوة:**
- يصل المدعوَّ بريدٌ فيه رابط `/invite/{code}` صالح **يوماً واحداً**.
- يقبل المدعوّ الدعوة من المتصفّح: ينشئ حساباً، أو يدخل بحسابه القائم.
- دعوة البريد نفسه مرّة ثانية تجدّد الكود وتأخذ الدور الجديد.

**`email_sent: false`** يعني أن البريد غير مفعّل في إعدادات المنصّة: الدعوة حُفظت لكن البريد لم يُرسل. أخبر المستخدم بذلك.

**رابط الدعوة لا يرجع في الردّ عمداً.** قبول دعوة لبريد له حساب قائم يُدخل صاحبه بلا كلمة مرور، فالرابط في يد غير صاحب البريد مفتاحٌ لحسابه.

### إعادة الإرسال والإلغاء

- **`POST /team/invites/{id}/resend`**: يرسل البريد مرّة أخرى، ويجدّد الكود إن كان قد انتهى.
- **`DELETE /team/invites/{id}`**: يلغي الدعوة، فيبطل الرابط الذي أُرسل.

### تغيير الدور — `PUT /team/{uuid}`

```json
{ "role": "manager" }
```

يعيد العضو بشكله في القائمة. وهذه حالات الرفض:

| الحالة | الردّ |
|---|---|
| تغيير دور المالك | 422 |
| تغيير دورك أنت | 422 |
| `role` غير `manager` أو `agent` | 400 |

### الإزالة والاستعادة

- **`DELETE /team/{uuid}`**: حذف ناعم. المالك ونفسك ممنوعان (422).
- **`POST /team/{uuid}/restore`**: يرجع العضو، ويخضع لـ `team_limit` (422 إن امتلأ الحدّ).

---

## 3) ادعُ صديقاً

**للتطبيق فقط**: لا شاشة لها في لوحة التحكم. لكل مستخدم رمز ورابط شخصيان، ومن يسجّل بالرابط يُنسب إليه.

```
GET /api/v1/referral              الرابط ونصّ المشاركة والأرقام
GET /api/v1/referral/referrals    من سجّلوا بالرابط
```

### `GET /referral`

```json
{
  "code": "P7BGQVN9",
  "link": "https://app.mnjz.net/r/P7BGQVN9",
  "share_text": "أدِر كل محادثات عملائك على واتساب في مكان واحد مع منجز. سجّل من رابطي: https://app.mnjz.net/r/P7BGQVN9",
  "stats": { "total": 4, "subscribed": 1 }
}
```

**حقول الردّ:**
- **`code`**: يُنشأ عند أوّل طلب، ويبقى ثابتاً. أحرفه بلا المتشابه (0/O، 1/I/L).
- **`share_text`**: جاهز لـ share sheet، بلغة `Accept-Language`.
- **`stats.subscribed`**: من يملك منهم منشأة باشتراك مدفوع ساري (باقة لا تجربة).

### `GET /referral/referrals`

```jsonc
{
  "items": [
    { "id": 3, "name": "Omar Ali", "email": "o•••••@example.com", "status": "subscribed", "joined_at": "2026-11-02T08:15:00+00:00" }
  ],
  "pagination": { "page": 1, "per_page": 25, "total": 4, "last_page": 1 }
}
```

قيمة `status` إمّا `registered` أو `subscribed`. البريد يصل مخفيّاً جزئياً.

### ماذا يحدث بعد المشاركة

1. يفتح الصديق `/r/CODE`، فيُحفظ الرمز في الجلسة وفي cookie ثلاثين يوماً، ويُوجَّه إلى صفحة التسجيل `/signup`.
2. التسجيل يكون من الويب: بالنموذج، أو بجوجل، أو بفيسبوك. وتُكتب الإحالة لصاحب الرابط.
3. كل مستخدم يُحال **مرّة واحدة**، بأوّل رابط سجّل به. ومن يفتح رابطه هو لا يُحسب.
4. `/signup?ref=CODE` يعمل كالرابط تماماً.

---

## الاختبار

### Postman على localhost

الملفات في جذر المشروع:

| الملف | المحتوى |
|---|---|
| `mnjz-mobile-campaigns-team-referral.postman_collection.json` | الأقسام الثلاثة ومعها تسجيل الدخول |
| `mnjz-local.postman_environment.json` | `base_url` = `http://localhost:8000`، و`email`، و`password`، و`lang` |
| `mnjz-mobile-api.postman_collection.json` | الـ collection الأساسية، وأُضيفت لها الأقسام الثلاثة نفسها |

**الخطوات:**

1. `php artisan migrate`، ثم `php artisan serve`.
2. استورد الـ collection والـ environment، واختر **mnjz local**.
3. اكتب في `email` بريد مالك أو مدير على قاعدتك المحلية. على `APP_ENV=local` تُقبل أي كلمة مرور.
4. شغّل الـ collection كاملة من Runner، أو من سطر الأوامر:

   ```
   postman collection run mnjz-mobile-campaigns-team-referral.postman_collection.json -e mnjz-local.postman_environment.json
   ```

**ماذا تفعل الـ collection:**
- `auth/login` يحفظ التوكن والمنشأة، وكل الطلبات بعده تستعملهما.
- الحملة التجريبية تُجدوَل بعد 7 أيام **ولا تُرسل شيئاً**، ثم يحذفها آخر طلب في القسم.
- الدعوة التجريبية تُرسل إلى `postman+<timestamp>@example.com` ثم تُلغى.
- تغيير الدور يرسل دور العضو الحالي نفسه، فلا يتغيّر شيء.

**المتطلّبات:**
- في المنشأة **قالب معتمد** و**جهة اتصال** على الأقل.
- إضافة **Mobile App** مفعّلة.

**الطلبات اليدوية:**
- الطلبات التي يبدأ اسمها بـ **[manual]** يتخطّاها الـ Runner إلا إذا كانت `run_manual_examples = true`.
- هذه الطلبات هي: رفع ملف ترويسة، وإزالة عضو، واستعادته.

**تحذيرات:**
- ⚠️ `login` يلغي **كل** توكنات المستخدم، فيخرج التطبيق على الجوال بالحساب نفسه. استعمل حساب تجربة.
- لو توقّف `php artisan serve` فجأة (segfault) على صفحات الويب، فالسبب Xdebug مع PHP 8.4. شغّله بـ `XDEBUG_MODE=off php artisan serve`.

### الاختبارات الآلية

```
php artisan test tests/Feature/MobileApi/MobileCampaignTest.php \
                 tests/Feature/MobileApi/MobileTeamTest.php \
                 tests/Feature/MobileApi/MobileReferralTest.php \
                 tests/Unit/WhatsappErrorExplainerTest.php
```

---

## ملخّص النقاط

| # | النقطة | الصلاحية |
|---|---|---|
| 1 | `GET /campaigns` | الجميع |
| 2 | `GET /campaigns/options` | الجميع |
| 3 | `GET /campaigns/templates/{uuid}` | الجميع |
| 4 | `GET /campaigns/media-history` | الجميع |
| 5 | `DELETE /campaigns/media-history/{uuid}` | الجميع |
| 6 | `POST /campaigns` | الجميع · اشتراك فعّال · `campaign_limit` |
| 7 | `GET /campaigns/{uuid}` | الجميع |
| 8 | `GET /campaigns/{uuid}/logs` | الجميع |
| 9 | `POST /campaigns/{uuid}/resend-failed` | الجميع · اشتراك فعّال |
| 10 | `DELETE /campaigns/{uuid}` | الجميع |
| 11 | `GET /team` | owner · manager |
| 12 | `POST /team/invite` | owner · manager · `team_limit` |
| 13 | `POST /team/invites/{id}/resend` | owner · manager |
| 14 | `DELETE /team/invites/{id}` | owner · manager |
| 15 | `PUT /team/{uuid}` | owner · manager |
| 16 | `DELETE /team/{uuid}` | owner · manager |
| 17 | `POST /team/{uuid}/restore` | owner · manager · `team_limit` |
| 18 | `GET /referral` | الجميع |
| 19 | `GET /referral/referrals` | الجميع |
