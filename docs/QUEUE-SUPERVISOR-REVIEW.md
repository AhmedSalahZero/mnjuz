# مراجعة إعدادات Supervisor للـ Queue (مع QUEUE_CONNECTION=redis)

## 1. برنامج `laravel-queue`

```ini
command=/usr/bin/php8.3 .../artisan queue:work --queue=default,campaign-logs --memory=1024
 --tries=3 --timeout=3600
```

**مشاكل:**
- **السطر مكسور:** وجود سطر جديد بين `--memory=1024` و `--tries=3` قد يفسّر الأمر بشكل خاطئ في Supervisor. يُفضّل أن يكون الأمر في سطر واحد.
- **لم يُحدد الاتصال صراحة:** الأمر `queue:work` بدون اسم الاتصال يستخدم الـ default من `.env` (redis)، وهذا صحيح، لكن من الوضوح والأمان تحديده: `queue:work redis`.

**ما يغطيه هذا الـ worker:**  
`default` + `campaign-logs` → يشغّل **CreateCampaignLogsJob** (من الـ Scheduler على queue `campaign-logs`). صحيح.

---

## 2. برنامج `mnjz-campaigns`

```ini
command=... queue:work redis --queue=campaign-messages ...
```

**ما يغطيه:**  
queue `campaign-messages` → يشغّل:
- **ProcessCampaignMessagesJob** (من الـ Scheduler كل دقيقة)
- **ProcessSingleCampaignLogJob** (الـ jobs اللي داخل الـ batch)
- **RetryCampaignLogJob**

الاتصال `redis` واسم الـ queue `campaign-messages` متوافقان مع الكود. الإعداد صحيح من ناحية الـ connection والـ queue.

---

## 3. لماذا سجلات `job_batches` تبقى ثابتة؟

جدول **job_batches** يحدّثه Laravel عندما:
- تُنشأ دفعة جديدة (سجل جديد)
- كل **job ابن** في الـ batch يُنفَّذ وينتهي (يُنقص `pending_jobs` ويحدّث الحالة)

إذا كانت السجلات **ثابتة** (لا يتغير `pending_jobs` ولا `finished_at`) فمعناه عملياً أحد الأمرين:

1. **الـ jobs الأبناء (ProcessSingleCampaignLogJob) لا تُنفَّذ أصلاً**  
   → إما لا تصل إلى Redis، أو الـ worker لا يلتقطها، أو يلتقطها لكنها تفشل قبل إكمال الـ job (فلا يصل تحديث الـ batch).

2. **الـ worker يلتقطها لكن يفشل أثناء التنفيذ**  
   → الـ job يذهب لـ `failed_jobs` والـ batch قد لا يُحدَّث كما يجب (خصوصاً مع Redis).

لذلك المطلوب: التأكد أن الـ worker الذي يسمع على **redis** + **campaign-messages** يعمل فعلاً وأن الـ jobs لا تفشل بصمت.

---

## 4. التوصيات

### أ) إصلاح أمر `laravel-queue` (سطر واحد + تحديد redis)

```ini
[program:laravel-queue]
process_name=%(program_name)s_%(process_num)02d
command=/usr/bin/php8.3 /home/mnjz-app/htdocs/app.mnjz.net/artisan queue:work redis --queue=default,campaign-logs --memory=1024 --tries=3 --timeout=3600
numprocs=3
autostart=true
autorestart=true
user=mnjz-app
redirect_stderr=true
```

- استخدام **redis** صراحة.
- وضع كل الخيارات في **سطر واحد** لتجنب تفسير خاطئ من Supervisor.

### ب) التأكد من تشغيل worker الـ campaigns

- التأكد أن برنامج **mnjz-campaigns** يعمل:
  - `supervisorctl status mnjz-campaigns`
- مراجعة الـ log:
  - `stdout_logfile=.../worker-campaigns.log`
- إن أمكن، مراقبة Redis أن الـ jobs تُستهلك من queue `campaign-messages` (عدد العناصر في الـ list ينزل عند تشغيل الـ worker).

### ج) زيادة عدد عمليات campaign-messages إن لزم

إذا كان حجم الـ batches كبير (مثلاً آلاف الـ jobs) وعمليتين فقط، قد يظهر تراكم. يمكن رفع `numprocs` لـ `mnjz-campaigns` (مثلاً 4 أو 5) حسب الحمل.

### د) التحقق من الـ batch في الكود

الـ batch يُرسل إلى الـ queue المحدد في `onQueue('campaign-messages')` على **نفس الـ connection الافتراضي (redis)**. لا حاجة لتغيير الكود من ناحية الاتصال أو اسم الـ queue طالما الـ worker يسمع على `redis` و `campaign-messages`.

---

## 5. الخلاصة

| البند | الحالة |
|--------|--------|
| QUEUE_CONNECTION=redis | صحيح |
| استخدام redis في الـ workers | يُفضّل أن يكون صريحاً (مثل: `queue:work redis`) |
| queue للـ campaigns (إنشاء السجلات) | `campaign-logs` → يغطيه `laravel-queue` |
| queue لإرسال الرسائل والـ batch | `campaign-messages` → يغطيه `mnjz-campaigns` |
| ثبات سجلات job_batches | غالباً لأن jobs الـ batch لا تُنفَّذ أو تفشل قبل الإكمال → مراجعة تشغيل وعمل الـ worker والـ logs |

بعد تصحيح سطر الأمر في `laravel-queue` والتأكد أن `mnjz-campaigns` يعمل ويلتقط الـ jobs، أعد تشغيل الـ workers وراقب جدول `job_batches` وملف `worker-campaigns.log`.
