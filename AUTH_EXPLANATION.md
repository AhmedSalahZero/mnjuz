# شرح منطق المصادقة (Authentication Logic)

## 📋 المنطق الحالي (Web Authentication)

### 1. **دالة `login()` - السطر 68-89**

```php
public function login(LoginRequest $request)
```

**الخطوات:**
1. **البحث عن المستخدم**: البحث بالبريد الإلكتروني
2. **التحقق من TFA**: إذا كان المستخدم مفعّل Two-Factor Authentication
3. **التحقق من المنظمات**: التأكد من وجود منظمة نشطة
4. **استدعاء `doLogin()`**: تنفيذ عملية تسجيل الدخول

### 2. **دالة `doLogin()` - السطر 103-132**

```php
private function doLogin(Request $request, $user, $remember)
```

**الخطوات:**
1. **تحديد Guard**: `user` أو `admin` حسب دور المستخدم
2. **تسجيل الدخول**: استخدام `Auth::guard()->attempt()` أو `login()`
3. **إعداد Session**: حفظ `current_organization` إذا كان هناك منظمة واحدة فقط
4. **إعداد اللغة**: التحقق من لغة المستخدم
5. **إعادة التوجيه**: توجيه المستخدم للـ dashboard

### 3. **Two-Factor Authentication (TFA)**

- إذا كان TFA مفعّل، يتم حفظ `user_id` في Session
- إعادة التوجيه لصفحة `/tfa`
- بعد التحقق، يتم استدعاء `doLogin()` مرة أخرى

---

## 📱 التعديلات المطلوبة للموبايل

### المشاكل الحالية:
1. ❌ يستخدم **Session-based** authentication (لا يعمل مع API)
2. ❌ يرجع **redirects** بدلاً من JSON responses
3. ❌ لا يدعم **API tokens** (Sanctum موجود لكن غير مستخدم)

### الحل: إضافة Mobile Authentication
