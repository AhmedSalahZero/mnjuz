# API Authentication Tests

## 📋 نظرة عامة

هذا المجلد يحتوي على Unit Tests و Feature Tests لجميع API Authentication routes.

---

## 🧪 الاختبارات المتوفرة

### Feature Tests (`tests/Feature/ApiAuthTest.php`)

#### 1. **Login Tests**
- ✅ `test_api_login_success` - تسجيل الدخول الناجح
- ✅ `test_api_login_invalid_credentials` - بيانات دخول خاطئة
- ✅ `test_api_login_email_not_found` - بريد إلكتروني غير موجود
- ✅ `test_api_login_no_active_organization` - لا توجد منظمة نشطة
- ✅ `test_api_login_requires_tfa` - يتطلب TFA

#### 2. **TFA Verification Tests**
- ✅ `test_api_tfa_verify_structure` - هيكل استجابة TFA
- ✅ `test_api_tfa_verify_invalid_token` - Token غير صحيح
- ✅ `test_api_tfa_verify_missing_parameters` - معاملات مفقودة

#### 3. **Logout Tests**
- ✅ `test_api_logout_success` - تسجيل الخروج الناجح
- ✅ `test_api_logout_unauthenticated` - تسجيل خروج بدون مصادقة

#### 4. **Set Current Organization Tests**
- ✅ `test_api_set_current_organization_success` - تعيين منظمة ناجح
- ✅ `test_api_set_current_organization_invalid` - منظمة غير صحيحة
- ✅ `test_api_set_current_organization_not_member` - المستخدم ليس عضواً

#### 5. **Mobile App Test Endpoint**
- ✅ `test_api_test_endpoint_success` - endpoint يعمل
- ✅ `test_api_test_endpoint_unauthenticated` - بدون مصادقة
- ✅ `test_api_test_endpoint_addon_disabled` - Addon معطل

---

### Unit Tests (`tests/Unit/AuthControllerTest.php`)

#### 1. **doLogin Method Tests**
- ✅ `test_do_login_returns_json_for_api` - يرجع JSON للـ API
- ✅ `test_do_login_creates_token_with_device_name` - ينشئ token مع device name
- ✅ `test_do_login_includes_organizations` - يتضمن المنظمات
- ✅ `test_do_login_sets_current_organization_single` - يحدد منظمة واحدة
- ✅ `test_do_login_no_current_organization_multiple` - لا يحدد عند وجود عدة منظمات

#### 2. **Other Methods Tests**
- ✅ `test_login_handles_tfa_requirement` - يتعامل مع TFA
- ✅ `test_set_current_organization_updates_user` - يحدث المستخدم
- ✅ `test_logout_deletes_token` - يحذف Token

---

## 🚀 كيفية تشغيل الاختبارات

### تشغيل جميع الاختبارات:
```bash
php artisan test
```

### تشغيل Feature Tests فقط:
```bash
php artisan test --testsuite=Feature
```

### تشغيل Unit Tests فقط:
```bash
php artisan test --testsuite=Unit
```

### تشغيل اختبار محدد:
```bash
php artisan test --filter test_api_login_success
```

### تشغيل اختبارات API Auth فقط:
```bash
php artisan test tests/Feature/ApiAuthTest.php
php artisan test tests/Unit/AuthControllerTest.php
```

---

## 📝 ملاحظات مهمة

### 1. **Database Setup**
- الاختبارات تستخدم `RefreshDatabase` trait
- يتم إنشاء قاعدة بيانات اختبار مؤقتة
- جميع البيانات تُحذف بعد كل test

### 2. **Factories**
الـ Factories المطلوبة:
- `UserFactory` - إنشاء مستخدمين
- `OrganizationFactory` - إنشاء منظمات
- `TeamFactory` - إنشاء فرق
- `AddonFactory` - إنشاء إضافات

### 3. **TFA Testing**
- اختبار TFA معقد لأنه يحتاج كود صحيح من Google Authenticator
- الاختبارات الحالية تتحقق من الهيكل فقط
- للاختبار الكامل، تحتاج إلى mock أو استخدام مكتبة TFA

---

## ✅ Coverage

### Routes المغطاة:
- ✅ `POST /api/auth/login`
- ✅ `POST /api/auth/tfa/verify`
- ✅ `POST /api/auth/logout`
- ✅ `POST /api/auth/set-current-organization`
- ✅ `POST /api/test`

### Scenarios المغطاة:
- ✅ Success cases
- ✅ Validation errors
- ✅ Authentication errors
- ✅ Authorization errors
- ✅ Edge cases

---

## 🔧 Troubleshooting

### مشكلة: "Class not found"
```bash
# تأكد من تشغيل composer autoload
composer dump-autoload
```

### مشكلة: "Database connection failed"
```bash
# تأكد من إعدادات قاعدة البيانات في phpunit.xml
# أو استخدم SQLite في الذاكرة
```

### مشكلة: "Factory not found"
```bash
# تأكد من وجود جميع الـ Factories
# UserFactory, OrganizationFactory, TeamFactory, AddonFactory
```
