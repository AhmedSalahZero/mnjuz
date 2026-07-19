# دليل المصادقة على الموبايل (Mobile Authentication Guide)

## 📱 نظرة عامة

تم تعديل نظام المصادقة لدعم التطبيقات المحمولة باستخدام **Laravel Sanctum** لإصدار API tokens.

---

## 🔐 كيفية العمل

### 1. **تسجيل الدخول (Login)**

#### Request:
```http
POST /api/login
Content-Type: application/json

{
    "email": "user@example.com",
    "password": "password123",
    "device_name": "iPhone 14 Pro" // اختياري
}
```

#### Response (نجاح):
```json
{
    "success": true,
    "message": "Login successful",
    "data": {
        "user": {
            "id": 1,
            "first_name": "John",
            "last_name": "Doe",
            "email": "user@example.com",
            "role": "user",
            "language": "en",
            "avatar": null
        },
        "token": "1|abcdef123456...",
        "token_type": "Bearer",
        "organizations": [
            {
                "id": 1,
                "name": "John's organization",
                "role": "owner"
            }
        ],
        "current_organization_id": 1
    }
}
```

#### Response (خطأ):
```json
{
    "success": false,
    "message": "Your credentials are incorrect!"
}
```

---

### 2. **Two-Factor Authentication (TFA)**

إذا كان المستخدم مفعّل TFA:

#### Response:
```json
{
    "success": false,
    "requires_tfa": true,
    "message": "Two-factor authentication required",
    "tfa_token": "encrypted_token_here"
}
```

#### التحقق من TFA:
```http
POST /api/tfa/verify
Content-Type: application/json

{
    "tfa_token": "encrypted_token_from_login",
    "tfa_code": "123456"
}
```

---

### 3. **استخدام Token في الطلبات**

بعد الحصول على token، استخدمه في header:

```http
GET /api/user
Authorization: Bearer 1|abcdef123456...
```

---

### 4. **الحصول على بيانات المستخدم**

```http
GET /api/user
Authorization: Bearer {token}
```

#### Response:
```json
{
    "success": true,
    "data": {
        "user": {
            "id": 1,
            "first_name": "John",
            "last_name": "Doe",
            "email": "user@example.com",
            "role": "user",
            "language": "en",
            "avatar": null
        },
        "organizations": [...]
    }
}
```

---

### 5. **تسجيل الخروج (Logout)**

```http
POST /api/logout
Authorization: Bearer {token}
```

#### Response:
```json
{
    "success": true,
    "message": "Logged out successfully"
}
```

---

## 🔄 الفرق بين Web و Mobile

| الميزة | Web | Mobile |
|--------|-----|--------|
| **Authentication** | Session-based | Token-based (Sanctum) |
| **Response** | Redirects | JSON |
| **Storage** | Cookies/Session | Token في Header |
| **TFA** | Session storage | Encrypted token |

---

## 📝 ملاحظات مهمة

1. **Token Expiration**: Tokens لا تنتهي صلاحيتها افتراضياً (يمكن تعديلها في `config/sanctum.php`)

2. **Security**: 
   - احفظ Token بشكل آمن في التطبيق
   - استخدم HTTPS دائماً
   - لا ترسل Token في URLs

3. **Device Name**: يمكنك تمرير `device_name` لتتبع الأجهزة المختلفة

4. **Multiple Tokens**: يمكن للمستخدم الحصول على عدة tokens لأجهزة مختلفة

---

## 🧪 أمثلة الكود

### React Native / Expo:
```javascript
// Login
const login = async (email, password) => {
  const response = await fetch('https://your-api.com/api/login', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({
      email,
      password,
      device_name: 'My iPhone'
    })
  });
  
  const data = await response.json();
  
  if (data.success) {
    // Save token
    await AsyncStorage.setItem('auth_token', data.data.token);
    return data.data;
  }
  
  throw new Error(data.message);
};

// Authenticated Request
const getProfile = async () => {
  const token = await AsyncStorage.getItem('auth_token');
  
  const response = await fetch('https://your-api.com/api/user', {
    headers: {
      'Authorization': `Bearer ${token}`
    }
  });
  
  return response.json();
};
```

### Flutter:
```dart
// Login
Future<Map<String, dynamic>> login(String email, String password) async {
  final response = await http.post(
    Uri.parse('https://your-api.com/api/login'),
    headers: {'Content-Type': 'application/json'},
    body: jsonEncode({
      'email': email,
      'password': password,
      'device_name': 'My Android Phone'
    }),
  );
  
  final data = jsonDecode(response.body);
  
  if (data['success']) {
    // Save token
    await storage.write(key: 'auth_token', value: data['data']['token']);
    return data['data'];
  }
  
  throw Exception(data['message']);
}
```

---

## ✅ التحقق من التثبيت

1. تأكد من أن `Laravel Sanctum` مثبت
2. تأكد من أن `HasApiTokens` trait موجود في `User` model
3. جرب تسجيل الدخول عبر API endpoint
