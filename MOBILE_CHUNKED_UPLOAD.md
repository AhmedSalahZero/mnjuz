# رفع الملفات بالـ chunks — تعليمات تطبيق الجوال

أنواع الرسائل وشكل الـ chat response في `MOBILE_CHAT_EXAMPLES.md`.

## متى تستخدمها

| مجموع أحجام الملفات | الـ endpoint |
|---|---|
| ≤ 5MB | `POST /api/v1/send-msg` مع `files[]` — request واحد |
| > 5MB | `POST /api/v1/chats/upload/chunk` |

تصلح للحجمين معًا: الملف الصغير يصير chunk واحدة (`index=0`, `total=1`) تُجمّع وتُرسل في نفس الـ request. استخدامها دائمًا يعني مسار واحد في كود التطبيق، مقابل requests أكثر.

**السبب**: Cloudflare يقطع أي request يتجاوز 125 ثانية مهما كان حجمه. على شبكة بطيئة يفشل الملف الكبير دائمًا بلا رسالة خطأ — 499 في لوج السيرفر و 524 عند المستخدم.

## الـ endpoint

```
POST /api/v1/chats/upload/chunk
Authorization: Bearer <sanctum token>
Accept: application/json
Content-Type: multipart/form-data
```

## الحقول

تُرسل كاملة مع **كل** chunk.

| الحقل | النوع | مطلوب | ملاحظات |
|---|---|---|---|
| `upload_id` | string ≤ 64 | نعم | معرّف مستقل **لكل ملف** |
| `index` | int ≥ 0 | نعم | رقم الـ chunk، يبدأ من صفر |
| `total` | int ≥ 1 | نعم | عدد chunks هذا الملف (≤ 4000) |
| `chunk` | file | نعم | بايتات الـ chunk |
| `phone` | string | نعم | صيغة E.164 مثل `+966500000001` |
| `file_name` | string ≤ 255 | نعم | الاسم بامتداده — منه نحدد النوع |
| `msg_uuid` | string ≤ 64 | لا | UUID رسالة هذا الملف |
| `caption` | string | لا | مع **الملف الأول فقط** |
| `file_type` | string | لا | `image` \| `video` \| `audio` \| `document`. يُستنتج من الامتداد إن غاب |
| `first_name` / `last_name` | string | لا | عند إنشاء contact جديد |

## الخطوات

لكل ملف على حدة، بالترتيب:

1. `upload_id` خاص بهذا الملف: `"<sessionId>-<i>"`. **معرّف مشترك بين ملفين يخلط الـ chunks ويتلف الملفين.**
2. `total = ceil(file.size / 5MB)`.
3. أرسل الـ chunks من `index = 0` حتى `total - 1`، **واحدة واحدة مع انتظار الـ response**.
4. `data.completed = true` ⇒ الملف اكتمل ودخل الـ queue ⇒ انتقل للملف التالي.

```
CHUNK = 5 * 1024 * 1024

for (i, file) in files:
    uploadId = "${sessionId}-${i}"
    total    = ceil(file.size / CHUNK)

    for index in 0 until total:
        response = POST /api/v1/chats/upload/chunk {
            upload_id: uploadId,
            index:     index,
            total:     total,
            chunk:     file.bytes(index * CHUNK, CHUNK),
            phone:     phone,
            file_name: file.name,
            msg_uuid:  msgUuids[i],
            caption:   (i == 0) ? caption : null
        }
        // فشل الشبكة ⇒ أعد إرسال نفس الـ index

    // response.data.completed == true ⇒ الملف التالي
```

## الردود

كلها ردود حقيقية من الـ endpoint.

### chunk وصلت والملف لم يكتمل

```json
{
  "statusCode": 200,
  "success": true,
  "message": null,
  "data": {
    "completed": false,
    "received": 1,
    "total": 2
  }
}
```

### آخر chunk — الملف اكتمل ودخل الـ queue

```json
{
  "statusCode": 200,
  "success": true,
  "message": "Message sent successfully",
  "data": {
    "completed": true,
    "queued": true,
    "received": 2,
    "total": 2,
    "contact_id": 1,
    "contact_uuid": "92615169-3f8d-4eba-9af1-d00381aaac2c",
    "phone": "+966500000001"
  }
}
```

### إلغاء الرفع

```
DELETE /api/v1/chats/upload/chunk
{ "upload_id": "a3f9c1-0" }
```

```json
{
  "statusCode": 200,
  "success": true,
  "message": null,
  "data": { "discarded": true }
}
```

### 400 — بيانات غير صالحة

```json
{
  "statusCode": 400,
  "success": false,
  "message": "The provided data is invalid.",
  "errors": {
    "total": ["The total field is required."]
  }
}
```

### 422 — نافذة الـ 24 ساعة مغلقة

```json
{
  "statusCode": 422,
  "success": false,
  "message": "WhatsApp does not allow sending messages 24 hours after they last messaged you. However, you can send them a template message."
}
```

## الـ model

```kotlin
data class ApiResponse<T>(
    val statusCode: Int,
    val success: Boolean,
    val message: String?,                    // nullable حتى عند النجاح
    val data: T?,                            // غائب في كل الأخطاء
    val errors: Map<String, List<String>>?   // في 400 فقط
)

data class ChunkData(
    val completed: Boolean,                  // مفتاح التفريع الوحيد
    val received: Int,
    val total: Int,
    val queued: Boolean?,                    // عند completed = true فقط
    val contactId: Int?,                     // عند completed = true فقط
    val contactUuid: String?,                // عند completed = true فقط
    val phone: String?                       // عند completed = true فقط
)
```

## التفريع

```
success == false          ⇒ اعرض message، وإن كان statusCode == 400 فصّل من errors
data.completed == false   ⇒ حدّث الـ progress bar بـ received / total
data.completed == true    ⇒ الملف التالي
```

`queued: true` تعني دخول الـ queue، لا الوصول للعميل. حالة التسليم تصل لاحقًا في `logs` عبر Pusher أو الـ history.

## قاعدتان

**chunk واحدة في كل مرة.** الـ chunks تُقبل بأي ترتيب لأن التجميع يعتمد على `index`. لكن إرسالها parallel قد يجعل requestين يريان الملف مكتملًا في نفس اللحظة، فيُجمّع مرتين ويصل العميل مرتين.

**إعادة المحاولة آمنة.** انقطعت الشبكة؟ أعد إرسال **نفس الـ index** — يُكتب مكان القديم ولا يُحتسب مرتين. وللاستئناف: نفس `upload_id` وأعد ما لم يصله response. `received` يخبرك بكم وصل.

## الحدود

| البند | القيمة |
|---|---|
| حجم الـ chunk | 5MB (يمكن أقل على شبكة ضعيفة) |
| أقصى عدد chunks للملف | 4000 |
| video / audio | 16MB للملف |
| document | 100MB للملف |
| image | بلا حد — السيرفر يضغطها لحد واتساب (5MB) |

الامتدادات: صور `jpg, jpeg, png, gif, bmp, webp, heic, heif` — فيديو `mp4, mov, mkv, webm, 3gp, avi` — صوت `mp3, wav, ogg, aac, m4a, amr, opus` — مستندات `pdf, doc, docx, xls, xlsx, ppt, pptx, txt, csv`.

## حالات الرفض

| الحالة | `statusCode` |
|---|---|
| حقل ناقص أو امتداد غير مدعوم | 400 |
| الملف بعد التجميع أكبر من حد نوعه | 400 |
| اشتراك غير فعّال أو واتساب غير مربوط | 403 |
| نافذة الـ 24 ساعة مغلقة | 422 |
| فشل التجميع | 500 |

كل الفحوصات تتم **قبل تخزين أي byte**. وما يُترك بلا إلغاء يُحذف تلقائيًا بعد 24 ساعة.

## ترتيب الصور

كل ملف يدخل الـ queue لحظة اكتمال chunks الخاصة به، فترتيب الوصول = ترتيب رفعك. ارفع الملفات واحدًا بعد الآخر.

للمجموعات الصغيرة (≤ 5MB) استخدم `POST /api/v1/send-msg` مع `files[]`: السيرفر يرسلها في chain واحد ويضمن الترتيب بنفسه.
