# تعليمات إعداد قاعدة بيانات الاختبارات

## ✅ نعم، الاختبارات ستعمل على `mnjuz_testing`!

### 📋 الخطوات المطلوبة:

---

## 1️⃣ إنشاء قاعدة البيانات

```bash
# خيار 1: من MySQL CLI
mysql -u root -p
CREATE DATABASE mnjuz_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
EXIT;

# خيار 2: من Terminal مباشرة (بدون كلمة مرور)
mysql -u root -e "CREATE DATABASE IF NOT EXISTS mnjuz_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# خيار 3: من Terminal مباشرة (مع كلمة مرور)
mysql -u root -pYOUR_PASSWORD -e "CREATE DATABASE IF NOT EXISTS mnjuz_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

---

## 2️⃣ تحديث phpunit.xml

### إذا كان MySQL بدون كلمة مرور:
```xml
<env name="DB_CONNECTION" value="mysql"/>
<env name="DB_DATABASE" value="mnjuz_testing"/>
<env name="DB_USERNAME" value="root"/>
<env name="DB_PASSWORD" value=""/>
```

### إذا كان MySQL يحتاج كلمة مرور:
```xml
<env name="DB_CONNECTION" value="mysql"/>
<env name="DB_DATABASE" value="mnjuz_testing"/>
<env name="DB_USERNAME" value="root"/>
<env name="DB_PASSWORD" value="your_password_here"/>
```

---

## 3️⃣ كيف يعمل RefreshDatabase؟

```php
use RefreshDatabase; // في Test class

// يقوم تلقائياً بـ:
// ✅ إنشاء جميع الجداول (migrations)
// ✅ تنظيف البيانات بعد كل test
// ✅ إعادة إنشاء الجداول قبل كل test suite
```

**لذلك لا تحتاج لإنشاء الجداول يدوياً!**

---

## 4️⃣ اختبار الاتصال

```bash
# اختبار الاتصال بقاعدة البيانات
php artisan tinker
>>> DB::connection()->getPdo();
# إذا نجح، الاتصال يعمل ✅
```

---

## 5️⃣ تشغيل الاختبارات

```bash
# جميع الاختبارات
php artisan test

# اختبار محدد
php artisan test --filter test_api_login_success
```

---

## ⚠️ حل المشاكل الشائعة

### مشكلة: "Access denied"
```bash
# الحل: تأكد من كلمة المرور في phpunit.xml
# أو استخدم مستخدم آخر
```

### مشكلة: "Database doesn't exist"
```bash
# الحل: أنشئ قاعدة البيانات أولاً
mysql -u root -p -e "CREATE DATABASE mnjuz_testing;"
```

### مشكلة: "Table doesn't exist"
```bash
# هذا طبيعي! RefreshDatabase سينشئها تلقائياً
# فقط تأكد من وجود migrations في database/migrations/
```

---

## ✅ الخلاصة

1. ✅ **نعم، الاختبارات ستعمل** على `mnjuz_testing`
2. ✅ **RefreshDatabase** يقوم بكل شيء تلقائياً
3. ✅ **أنت تحتاج فقط** إنشاء قاعدة بيانات فارغة
4. ✅ **Laravel سينشئ الجداول** تلقائياً
