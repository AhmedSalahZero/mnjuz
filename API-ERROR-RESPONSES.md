# 📋 دليل رسائل الأخطاء في API

## 🎯 نظرة عامة

تم تحسين جميع رسائل الأخطاء في API لتكون:
- ✅ مترجمة حسب لغة المستخدم (عربي/إنجليزي)
- ✅ موحّدة في الصيغة
- ✅ واضحة ومفيدة
- ✅ متوافقة مع المعايير الدولية

---

## 🔐 أخطاء المصادقة (Authentication Errors)

### 1️⃣ **401 Unauthenticated** - غير مصادق

**متى يحدث:**
- عند محاولة الوصول لـ endpoint محمي بدون Bearer Token
- عند استخدام Bearer Token منتهي الصلاحية
- عند استخدام Bearer Token محذوف/ملغي

**Response القديم (قبل التحسين):**
```json
{
  "message": "Unauthenticated."
}
```
❌ غير واضح، إنجليزي فقط، لا يتبع صيغة موحدة

**Response الجديد (بعد التحسين):**

**للمستخدم العربي:**
```json
{
  "success": false,
  "message": "غير مصادق",
  "error": "لم يتم التحقق من هويتك. يرجى تسجيل الدخول أولاً."
}
```

**للمستخدم الإنجليزي:**
```json
{
  "success": false,
  "message": "Unauthenticated",
  "error": "You are not authenticated. Please login first."
}
```

✅ واضح، مترجم، يتبع صيغة موحدة

---

### 2️⃣ **403 Forbidden** - ممنوع الوصول

**متى يحدث:**
- عند محاولة الوصول لمورد ليس لديك صلاحية للوصول إليه
- عند محاولة تنفيذ عملية غير مسموح بها لدورك

**Response:**
```json
{
  "success": false,
  "message": "غير مسموح",
  "error": "ليس لديك صلاحية للوصول إلى هذا المورد."
}
```

---

### 3️⃣ **422 Validation Error** - خطأ في التحقق

**متى يحدث:**
- عند إرسال بيانات غير صحيحة
- عند فشل التحقق من صحة البيانات

**Response:**
```json
{
  "message": "The tfa code field is invalid.",
  "errors": {
    "tfa_code": [
      "رمز غير صالح"
    ]
  }
}
```

---

## 📱 أمثلة عملية

### مثال 1: الوصول بدون Token

**Request:**
```http
GET /api/auth/user
Accept: application/json
```

**Response: 401**
```json
{
  "success": false,
  "message": "غير مصادق",
  "error": "لم يتم التحقق من هويتك. يرجى تسجيل الدخول أولاً."
}
```

---

### مثال 2: Token منتهي الصلاحية

**Request:**
```http
GET /api/auth/user
Authorization: Bearer 1|old_expired_token
Accept: application/json
```

**Response: 401**
```json
{
  "success": false,
  "message": "غير مصادق",
  "error": "لم يتم التحقق من هويتك. يرجى تسجيل الدخول أولاً."
}
```

**الحل:** سجّل دخول مرة أخرى للحصول على token جديد.

---

### مثال 3: Token صحيح

**Request:**
```http
GET /api/auth/user
Authorization: Bearer 3|valid_token_here
Accept: application/json
```

**Response: 200 ✅**
```json
{
  "id": 1,
  "first_name": "أحمد",
  "last_name": "محمد",
  "email": "admin@example.com",
  "role": "user",
  "language": "ar"
}
```

---

## 🎨 صيغة موحدة للـ Errors

جميع الأخطاء تتبع نفس الصيغة:

```json
{
  "success": false,          // دائماً false في الأخطاء
  "message": "رسالة قصيرة",   // رسالة مختصرة
  "error": "رسالة تفصيلية"   // (اختياري) شرح أكثر تفصيلاً
}
```

أو في حالة أخطاء التحقق:

```json
{
  "message": "رسالة عامة",
  "errors": {
    "field_name": [
      "رسالة الخطأ للحقل"
    ]
  }
}
```

---

## 🔄 كيف تحدد اللغة؟

الـ API يحدد اللغة تلقائياً بالترتيب التالي:

1. **لغة المستخدم من قاعدة البيانات** (إذا كان مسجل دخول)
2. **اللغة من الـ Session** (للويب)
3. **اللغة الافتراضية** (en)

---

## 🛠️ معالجة الأخطاء في التطبيق

### في JavaScript/TypeScript:

```javascript
async function getUserInfo(token) {
  try {
    const response = await fetch('/api/auth/user', {
      headers: {
        'Authorization': `Bearer ${token}`,
        'Accept': 'application/json'
      }
    });
    
    const data = await response.json();
    
    if (!response.ok) {
      // معالجة الأخطاء
      if (response.status === 401) {
        // المستخدم غير مصادق
        console.error(data.message); // "غير مصادق"
        console.error(data.error);   // "لم يتم التحقق من هويتك..."
        
        // إعادة التوجيه لصفحة تسجيل الدخول
        redirectToLogin();
      } else if (response.status === 403) {
        // ممنوع الوصول
        showAlert(data.message);
      }
      return;
    }
    
    // نجح الطلب
    console.log('User:', data);
    
  } catch (error) {
    console.error('Network error:', error);
  }
}
```

### في Flutter/Dart:

```dart
Future<User?> getUserInfo(String token) async {
  try {
    final response = await http.get(
      Uri.parse('$baseUrl/api/auth/user'),
      headers: {
        'Authorization': 'Bearer $token',
        'Accept': 'application/json',
      },
    );
    
    final data = jsonDecode(response.body);
    
    if (response.statusCode == 401) {
      // المستخدم غير مصادق
      print(data['message']); // "غير مصادق"
      print(data['error']);   // "لم يتم التحقق من هويتك..."
      
      // حذف Token وإعادة التوجيه للـ login
      await clearToken();
      navigateToLogin();
      return null;
    }
    
    if (response.statusCode == 200) {
      return User.fromJson(data);
    }
    
    // أخطاء أخرى
    showErrorDialog(data['message']);
    return null;
    
  } catch (e) {
    print('Error: $e');
    return null;
  }
}
```

---

## 📊 جدول HTTP Status Codes

| الكود | الاسم | المعنى | متى يحدث |
|------|-------|--------|----------|
| **200** | OK | نجح الطلب | عند نجاح أي عملية |
| **201** | Created | تم إنشاء المورد | بعد إنشاء مورد جديد |
| **400** | Bad Request | طلب خاطئ | بيانات غير صحيحة |
| **401** | Unauthorized | غير مصادق | لا يوجد token أو منتهي |
| **403** | Forbidden | ممنوع | لا توجد صلاحية |
| **404** | Not Found | غير موجود | المورد غير موجود |
| **422** | Unprocessable Entity | لا يمكن معالجته | فشل التحقق من البيانات |
| **500** | Server Error | خطأ في السيرفر | خطأ غير متوقع |

---

## ✅ قائمة التحقق للمطورين

عند استخدام API تأكد من:

- [ ] إرسال header `Accept: application/json` في كل request
- [ ] إرسال header `Authorization: Bearer TOKEN` للـ endpoints المحمية
- [ ] معالجة status code 401 بإعادة تسجيل الدخول
- [ ] معالجة status code 403 بعرض رسالة "ليس لديك صلاحية"
- [ ] معالجة status code 422 بعرض أخطاء التحقق للمستخدم
- [ ] عرض الرسائل بشكل واضح للمستخدم
- [ ] اختبار السيناريوهات المختلفة (token منتهي، بدون token، الخ)

---

## 🎯 الخلاصة

**قبل التحسين:**
```json
{
  "message": "Unauthenticated."
}
```
❌ غير واضح، إنجليزي فقط

**بعد التحسين:**
```json
{
  "success": false,
  "message": "غير مصادق",
  "error": "لم يتم التحقق من هويتك. يرجى تسجيل الدخول أولاً."
}
```
✅ واضح، مترجم، احترافي

---

**تم تحسين تجربة المطورين والمستخدمين! 🎉**
