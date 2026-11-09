# إعداد المنصّات — مرّة واحدة لكل المنصّة

هذا الملفّ لمسؤول الخادم لا للعملاء. الإعداد هنا **يُنفَّذ مرّة واحدة** ثمّ يربط كل عميل حسابه بنفسه من `/settings/channels`.

| القناة | إعداد على الخادم | ما يفعله العميل |
|---|---|---|
| **Telegram** | **لا شيء** | يُنشئ بوتاً من @BotFather ويلصق التوكن |
| **Messenger** | تطبيق ميتا | يضغط «ربط» ويختار صفحته |
| **Instagram** | نفس تطبيق ميتا | يضغط «ربط» ويختار حسابه |
| **TikTok** | تطبيق تيك توك + موافقة | يضغط «ربط» ويوافق |

---

## ١. تطبيق ميتا (Messenger + Instagram)

تطبيق واحد يخدم القناتين.

### الإنشاء

1. [developers.facebook.com/apps](https://developers.facebook.com/apps) ← **Create App** ← نوع **Business**.
2. أضف منتج **Messenger** ومنتج **Instagram**.

### Facebook Login

**Products ← Facebook Login ← Settings**، وأضف في *Valid OAuth Redirect URIs* **بالضبط**:

```
https://YOUR-DOMAIN/channels/oauth/messenger/callback
https://YOUR-DOMAIN/channels/oauth/instagram/callback
```

> ميتا تُطابق العنوان حرفياً. شرطة مائلة زائدة أو `http` بدل `https` تُفشل الربط برسالة غامضة.

### Webhook

**Messenger ← Settings ← Webhooks**، و**Instagram ← Webhooks**:

| | القيمة |
|---|---|
| Callback URL | `https://YOUR-DOMAIN/webhook/channel/messenger`<br>`https://YOUR-DOMAIN/webhook/channel/instagram` |
| Verify Token | أي قيمة — تُقارَن بما نُولّده لكل حساب |
| حقول Messenger | `messages`, `messaging_postbacks`, `message_deliveries`, `message_reads`, `message_echoes` |
| حقول Instagram | `messages`, `messaging_postbacks`, `message_reactions`, `messaging_seen` |

### الصلاحيات (App Review)

قبل خروج التطبيق من وضع التطوير تحتاج مراجعة ميتا لـ:

`pages_show_list` · `pages_messaging` · `pages_manage_metadata` · `business_management` · `instagram_basic` · `instagram_manage_messages`

> في وضع التطوير يعمل كل شيء **لحسابات المطوّرين والمُختبِرين فقط**. جرّب بها أولاً.

### `.env`

```
META_APP_ID=…
META_APP_SECRET=…
```

`META_APP_SECRET` يُستعمل في التحقّق من توقيع الـ webhook. **غيابه يعني قبول أي حمولة يُرسلها أي أحد** — السائق يُسجّل تحذيراً حين يجده فارغاً.

---

## ٢. تطبيق تيك توك

### الإنشاء

1. [business-api.tiktok.com](https://business-api.tiktok.com) ← **My Apps** ← **Create**.
2. اطلب **Business Messaging API** — الموافقة تستغرق أياماً. **ابدأها مبكراً.**

### Redirect URI

```
https://YOUR-DOMAIN/channels/oauth/tiktok/callback
```

### Webhook

عنوان **واحد لكل العملاء** — يُسجَّل تلقائياً عند أول ربط، ولا حاجة لضبطه يدوياً:

```
https://YOUR-DOMAIN/webhook/channel/tiktok
```

التوجيه بعده يتمّ بـ `user_openid` داخل الحمولة.

### `.env`

```
TIKTOK_APP_ID=…
TIKTOK_APP_SECRET=…
TIKTOK_API_VERSION=v1.3
```

### ⚠️ ساعة الخادم

توقيع تيك توك يشمل الوقت، و**يُرفض إذا تجاوز الفارق خمس ثوانٍ**:

```bash
timedatectl status          # يجب أن يكون NTP synchronized: yes
sudo timedatectl set-ntp true
```

ساعة منحرفة = **كل** الرسائل مرفوضة، بلا خطأ مفهوم.

### القيود التي يجب أن يعرفها العميل

- نافذة ردّ **٤٨ ساعة**، والعميل يبدأ المحادثة.
- نصّ **أو** صورة واحدة — لا الاثنان، ولا فيديو ولا مستندات. الصورة ≤ ٣ ميغابايت.
- حساب **أعمال** لا شخصي، ومعه «قبول الرسائل من الجميع».
- غير متاح للحسابات المسجَّلة في الاتحاد الأوروبي وسويسرا وبريطانيا. **السعودية مؤهّلة.**

---

## ٣. تيليجرام — لا إعداد

كل عميل يُنشئ بوته:

1. محادثة مع [@BotFather](https://t.me/BotFather) ← `/newbot`.
2. نسخ التوكن ولصقه في `/settings/channels`.

الـ webhook يُسجَّل تلقائياً بعنوان يحمل `uuid` الحساب — لأن تحديثات تيليجرام لا تحمل معرّف البوت، فالمسار وحده ما يُميّز بوتاً عن آخر.

**تيليجرام تعمل اليوم بلا أي إعداد منك ولا انتظار موافقة.** ابدأ بها.

---

## ٤. التحقّق قبل الإطلاق

```bash
# الإعدادات وصلت
php artisan tinker --execute="dump(config('services.meta.app_id'), config('services.tiktok.app_id'));"

# المسارات مسجَّلة
php artisan route:list --path=channels

# الساعة مضبوطة (تيك توك)
timedatectl status | grep NTP

# مهمّة تجديد التوكنات تعمل
php artisan channels:refresh-tokens
php artisan schedule:list | grep channels
```

### تجربة يدوية لكل قناة

1. اربط الحساب من `/settings/channels`.
2. أرسل رسالة من حساب عميل حقيقي → يجب أن تظهر في `/chats` بأيقونة المنصّة.
3. ردّ من لوحة التحكّم → يجب أن تصل العميل.
4. أرسل صورة في الاتجاهين.
5. أنشئ حملة على القناة وتحقّق من الجمهور والإرسال.
6. افصل الحساب وأعد ربطه.

---

## ٥. أخطاء شائعة

| العَرَض | السبب الغالب |
|---|---|
| «رابط التفويض لم يعد صالحاً» | مرّت أكثر من ١٠ دقائق، أو استُعمل الرابط مرّتين |
| ميتا ترفض التحويل | `redirect_uri` لا يطابق المسجَّل حرفياً |
| الربط ينجح ولا تصل رسائل | الـ webhook لم يُسجَّل، أو الحقول غير مشتركة |
| توقيع تيك توك مرفوض دائماً | ساعة الخادم غير مضبوطة على NTP |
| «لم نجد صفحة فيسبوك» | الحساب لا يُدير صفحات، أو لم تُمنح `pages_show_list` |
| «لم نجد حساب إنستغرام» | حساب إنستغرام غير مرتبط بصفحة فيسبوك، أو ليس احترافياً |
| تيك توك: صلاحيات ناقصة | المستخدم رفض بعضها — يجب قبولها كلّها |
| القناة تتوقّف بعد ٢٤ ساعة | `channels:refresh-tokens` لا تعمل — تحقّق من الـ scheduler |
