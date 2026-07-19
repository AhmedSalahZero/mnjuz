# إعداد قاعدة بيانات الاختبارات

## ✅ نعم، الاختبارات ستعمل على `mnjuz_testing`!

### كيف يعمل RefreshDatabase؟

```php
use RefreshDatabase; // في Test class

// يقوم تلقائياً بـ:
// 1. ✅ إنشاء قاعدة البيانات (إذا لم تكن موجودة)
// 2. ✅ تشغيل جميع migrations
// 3. ✅ تنظيف البيانات بعد كل test
// 4. ✅ إعادة تشغيل migrations قبل كل test suite
```

---

## 🚀 الخطوات المطلوبة

### الخطوة 1: إنشاء قاعدة البيانات

```bash
# خيار 1: من MySQL CLI
mysql -u root -p
CREATE DATABASE mnjuz_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
EXIT;

# خيار 2: من Terminal مباشرة
mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS mnjuz_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

### الخطوة 2: التحقق من الإعدادات

تأكد من أن `phpunit.xml` يحتوي على:
```xml
<env name="DB_CONNECTION" value="mysql"/>
<env name="DB_DATABASE" value="mnjuz_testing"/>
<env name="DB_USERNAME" value="root"/>
<env name="DB_PASSWORD" value=""/> <!-- أو كلمة المرور الخاصة بك -->
```

### الخطوة 3: تشغيل الاختبارات

```bash
php artisan test
```

**Laravel سيقوم تلقائياً بـ:**
- ✅ إنشاء الجداول (migrations)
- ✅ تنظيف البيانات
- ✅ إعادة إنشاء الجداول قبل كل test suite

---

## ⚠️ ملاحظات مهمة

### 1. قاعدة البيانات يجب أن تكون فارغة
Laravel سيقوم بإنشاء الجداول تلقائياً، لكن يجب أن تكون القاعدة موجودة.

### 2. كلمة المرور
إذا كان MySQL يحتاج كلمة مرور، قم بتحديث `phpunit.xml`:
```xml
<env name="DB_PASSWORD" value="your_password"/>
```

### 3. الصلاحيات
المستخدم يجب أن يكون لديه صلاحيات:
- CREATE
- DROP
- ALTER
- INSERT
- UPDATE
- DELETE

---

## 🧪 اختبار سريع

```bash
# 1. إنشاء قاعدة البيانات
mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS mnjuz_testing;"

# 2. تشغيل اختبار واحد للتأكد
php artisan test --filter test_api_login_success
```

---

## ✅ النتيجة

**نعم، الاختبارات ستعمل تلقائياً!**

`RefreshDatabase` trait يقوم بكل شيء:
- ✅ إنشاء الجداول
- ✅ تنظيف البيانات
- ✅ إعادة الإنشاء

**كل ما تحتاجه هو إنشاء قاعدة البيانات الفارغة فقط!**
