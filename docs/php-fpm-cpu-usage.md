# لماذا يستهلك PHP-FPM كل هذا الـ CPU؟

## ما الذي يحدث؟

عدة عمليات `php-fpm: pool app.mnjz.net` تظهر بحوالي **28–29% CPU** لكل واحدة. هذا يعني أن طلبات HTTP تبقى تعمل لفترة طويلة وتستهلك معالجة كثيفة.

---

## الأسباب الرئيسية

### 1. **معالجة ويب هوك واتساب داخل الطلب (الأثر الأكبر)**

في `WebhookController::handlePostRequest()` كل رسالة واتساب تُعالَج **مباشرة داخل نفس الطلب**:

- استعلامات: `Contact::where`, `Chat::where`, `Organization::find`
- إنشاء/تحديث: `Contact::create`, `contact->update`, `chat->save`, `ChatStatusLog::save`, `ChatLog::insertGetId`
- للوسائط: استدعاء **تحميل الملف من واتساب** (`getMedia` + `downloadMedia`) ثم حفظ في السيرفر — كل هذا داخل الطلب
- `event(NewChatEvent(...))` — بث قد ينتظر اتصال Pusher
- `(new AutoReplyService)->checkAutoReply($chat)` — منطق إضافي

**النتيجة:** كل طلب ويب هوك يمسك worker من PHP-FPM لوقت طويل (استعلامات + اتصال HTTP لتحميل الميديا + بث). مع عدة ويب هوكات في نفس الوقت ترى عدة workers عند ~28% CPU.

لديك بالفعل مسار يعتمد على **Jobs** (`handleAjaxPostRequest`) يرد بسرعة ويضع العمل في الطوابير، لكنه غير مستخدم في المسار الحالي (معطل بتعليق).

---

### 2. **استعلامات الإعدادات في كل طلب ويب هوك**

في `WebhookController::__construct()` يتم تنفيذ **5 استعلامات** من جدول `settings` في **كل** طلب ويب هوك:

```php
Config::set('broadcasting.connections.pusher', [
    'key' => Setting::where('key', 'pusher_app_key')->value('value'),
    'secret' => Setting::where('key', 'pusher_app_secret')->value('value'),
    // ...
]);
```

لا يوجد كاش، فكل طلب = 5 قراءات من DB لتكوين Pusher فقط.

---

### 3. **طلب قائمة المحادثات `/chats` ثقيل ومتكرر**

في `ChatService::getChatList()`:

- استعلام قائمة جهات اتصال مع محادثات: `contactsWithChatsOptimized()` فيها:
  - **Subquery مرتبط** لكل صف لحساب `unread_messages_count`
  - `with(['lastChat', 'organization'])`
  - عند التذاكر: JOIN مع `chat_tickets` وفلترة
- ثم: `Setting::whereIn(..., 4 مفاتيح)` في كل طلب
- ثم: `Template::where(...)->get()`
- عند فتح محادثة محددة: `Contact::with([...])`, `ChatTicket::with('user')`, `getChatMessages()` (وهي بدورها تحمّل `Chat::with('media','user','logs')`)

مع تكرار طلبات `GET /chats` (من الواجهة بعد كل حدث أو تنقل)، هذا المسار يُنفَّذ كثيراً ويساهم في استهلاك CPU ووقت الاستجابة.

---

### 4. **ميدلوار Inertia في كل طلب صفحة**

في `HandleInertiaRequests` يتم في كل طلب Inertia:

- `Organization::where(...)->first()`
- `Chat::where(...)->count()` لعد غير المقروء
- `Setting::whereIn('key', $keys)->get()` — قائمة طويلة من المفاتيح
- `Language::where(...)` (مرتين)

بدون كاش، كل تحميل صفحة = نفس الاستعلامات مرة أخرى.

---

## ما الذي يمكن فعله؟ (حسب الأولوية)

### أولاً: نقل معالجة الويب هوك إلى Jobs (الأهم)

- جعل المسار الافتراضي لطلبات ويب هوك واتساب يستدعي **نفس منطق** `handleAjaxPostRequest` (أو ما يعادله بـ Jobs):
  - الرد فوراً بـ `200` مع `['status' => 'success']`
  - إرسال الرسائل والحالات إلى **Queue** (مثل `ProcessIncomingMessageJob`, `ProcessMessageStatusJob`)
- تحميل الميديا ومعالجة الـ AutoReply يجب أن يحدثان **داخل الـ Job** وليس داخل طلب الـ webhook.

بهذا لا يبقى الـ worker مشغولاً طويلاً بكل رسالة، وينخفض استهلاك CPU لعمليات الـ webhook بشكل كبير.

---

### ثانياً: تخزين إعدادات البث (Pusher) في الكاش

- في `WebhookController` (أو في ميدلوار/ـ provider مشترك إذا أردت):
  - قراءة إعدادات Pusher من **Cache** (مثلاً مفتاح `pusher_config` مع TTL 5–15 دقيقة).
  - عند عدم وجود كاش: استعلام `Setting` مرة واحدة ثم `Cache::put(...)`.
- تقليل استعلامات الـ Settings في كل طلب ويب هوك من 5 إلى 0 (بعد التحميلة الأولى).

---

### ثالثاً: تخفيف استعلامات الإعدادات واللغة في Inertia

- في `HandleInertiaRequests`:
  - كاش لنتيجة `Setting::whereIn('key', $keys)` (مثلاً مفتاح `app_settings` أو حسب الـ keys).
  - كاش لـ `Language` المستخدمة في القائمة والـ RTL إن أمكن.
- تقليل تكرار نفس الاستعلامات على كل تحميل صفحة.

---

### رابعاً: تحسين استعلام قائمة المحادثات

- مراجعة `contactsWithChatsOptimized`:
  - إن أمكن استبدال الـ subquery المرتبط لـ `unread_messages_count` بعمود مخزّن مسبقاً أو بعلاقة/استعلام أقل تكلفة.
  - التأكد من وجود فهارس مناسبة: `chats.contact_id`, `chats.organization_id`, `chats.is_read`, `chats.type`, `contacts.organization_id`, `contacts.latest_chat_created_at`.
- في `getChatList`:
  - وضع نتيجة `Setting::whereIn(...)` لـ Pusher في كاش (نفس الفكرة في Webhook).
  - إن أمكن عدم جلب كل القوالب في كل طلب؛ أو كاش للقوالب حسب `organization_id`.

---

### خامساً: MySQL و Queue

- **MySQL** يظهر ~11% CPU و ~25% ذاكرة — طبيعي مع حمل كثيف. مع تخفيف الطلبات الثقيلة (ويب هوك + /chats) سينخفض الحمل.
- التأكد من أن **queue workers** (مثل `queue:work redis --queue=high`) تعمل وتستطيع استهلاك الـ jobs بسرعة كافية بعد نقل معالجة الويب هوك إليها.

---

## ملخص سريع

| السبب                         | الأثر        | الإجراء المقترح                    |
|------------------------------|-------------|-------------------------------------|
| معالجة ويب هوك داخل الطلب   | عالي جداً   | نقل المعالجة إلى Jobs والرد فوراً  |
| 5 استعلامات Settings لكل ويب هوك | متوسط       | كاش لإعدادات Pusher                |
| getChatList ثقيل ومتكرر      | متوسط       | كاش للـ Settings/Templates، تحسين الاستعلام والفهارس |
| HandleInertiaRequests        | متوسط       | كاش للإعدادات واللغات               |

بعد تطبيق **نقل الويب هوك إلى Jobs** و**كاش إعدادات Pusher في Webhook** ستلاحظ انخفاضاً واضحاً في استهلاك PHP-FPM للـ CPU.
