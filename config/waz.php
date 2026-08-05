<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Waz Business (business.waz.com.sa)
    |--------------------------------------------------------------------------
    |
    | عند التسجيل في منجز شات نُنشئ للعميل Company ثم Contact في منصة واز
    | أعمال. المصادقة عبر ترويسة authtoken.
    |
    | التسجيل ذرّي: إذا فشل الربط لا يُنشأ الحساب محلياً إطلاقاً.
    |
    */

    /*
    | مؤقتاً نربط ببيئة التجربة demo. النظام هناك مثبّت تحت /business لا في
    | جذر النطاق، فالمسار جزء من العنوان. للتحويل إلى الإنتاج غيّر
    | WAZ_BUSINESS_URL في .env إلى https://business.waz.com.sa (بلا /business).
    | الافتراضي demo عمداً: لو نُشر بلا ضبط المتغيّر فالأسوأ إنشاء سجل تجريبي،
    | لا عميل حقيقي في نظام الفوترة.
    */
    'base_url' => env('WAZ_BUSINESS_URL', 'https://demo.waz.com.sa/business'),

    'token' => env('WAZ_BUSINESS_API_TOKEN'),

    /*
    | مهلة كل نداء (ثوانٍ). التسجيل يحجب المستخدم أثناءها، لكن 15 ثانية أثبتت
    | قِصَرها: المنصة تُنفّذ الطلب ثم تتأخر استجابتها فيبدو ناجحاً وفاشلاً معاً.
    | 30 ثانية أوسع، ومع ذلك يبقى البحث بعد انقطاع الاتصال هو الضمانة الفعلية.
    */
    'timeout' => (int) env('WAZ_BUSINESS_TIMEOUT', 30),

    /*
    | ثوابت الطلب — موثّقة في مجموعة Postman ولا تُشتق من إدخال المستخدم.
    */
    'defaults' => [
        /** المجموعة يجب أن تكون "منجز شات". */
        'group_id' => '1',

        /*
         | معرّف العملة. التوثيق يقول 1=USD و2=EUR و3=SAR، لكن القائمة تُعرَّف
         | داخل كل نسخة من المنصة: على demo لا وجود لـ3 إطلاقاً و1 هو USD.
         | لذلك يُضبط من .env لكل بيئة بدل أن يكون ثابتاً في الكود.
         */
        'currency' => env('WAZ_BUSINESS_CURRENCY', '3'),

        /** مصدر العميل — custom_fields[customers][7]. */
        'source' => 'Mnjz Chat',

        /** حقل الرقم الضريبي المخصّص — custom_fields[customers][1]. */
        'vat_custom_field' => '1',

        'source_custom_field' => '7',

        /** منصب جهة الاتصال — ثابت حسب التوثيق. */
        'contact_title' => 'المسؤول',

        /*
         | جهة الاتصال الأساسية. التوثيق يذكر "on" لكن المنصة تخزّنها 0 معها،
         | و 1 هي القيمة التي تُفعّلها فعلاً (مُثبَت بالتجربة على demo).
         */
        'contact_is_primary' => '1',

        /** صلاحيات جهة الاتصال — ثابتة حسب التوثيق. */
        'contact_permissions' => ['1', '2', '3', '4', '5'],

        /**
         * أنواع الإشعارات التي تُرسَل لنفس بريد جهة الاتصال.
         * كلها مشتقّة من حقل البريد الواحد في نموذج التسجيل.
         */
        'contact_email_fields' => [
            'invoice_emails',
            'credit_note_emails',
            'project_emails',
            'ticket_emails',
            'task_emails',
            'contract_emails',
            'estimate_emails',
        ],
    ],

    /*
    | لغة العميل في واز مشتقّة من لغة الواجهة وقت التسجيل.
    */
    'languages' => [
        'ar' => 'arabic',
        'en' => 'english',
    ],

    'default_language' => 'english',

    /*
    |--------------------------------------------------------------------------
    | الفواتير
    |--------------------------------------------------------------------------
    |
    | تنبيه من التوثيق: رسوم التأسيس والاشتراك في الباقة يجب أن تكونا فاتورتين
    | منفصلتين، لا بندين في فاتورة واحدة.
    */
    'invoices' => [
        /*
         | طريقة الدفع. التوثيق يثبّتها على 2، لكنها كالعملة تُعرَّف داخل كل
         | نسخة: على demo الوضع النشط الوحيد هو 1 و2 يرد "does not exist or is
         | inactive". فتُضبط من .env لكل بيئة.
         */
        'allowed_payment_mode' => env('WAZ_BUSINESS_PAYMENT_MODE', '2'),

        /** اسم الضريبة ونسبتها كما تقبلها المنصة حرفياً. */
        'tax_name' => 'VAT |15.00',

        'tax_rate' => 15.0,

        /** وحدة العرض للخدمة. */
        'unit' => 'Account',

        /** أيام الاستحقاق بعد تاريخ الفاتورة (يجب أن يكون بعدها بيوم). */
        'due_days' => 1,

        /*
         | حدود رقم الفاتورة العشوائي. التوثيق يذكر 10 أرقام، لكن عمود الرقم في
         | المنصة int موقّع (حدّه 2147483647) — فالرقم ذو العشر خانات يُحشر عند
         | الحدّ الأقصى وتتكرّر الأرقام رغم اشتراط تفرّدها. 9 خانات تسع دائماً.
         */
        'number_min' => 100000000,
        'number_max' => 999999999,

        'admin_note' => 'This invoice was created by Mnjz Chat API',

        /*
         | عنوان الفوترة الأخير حين لا نعرف عنوان المنشأة لا عندنا ولا في
         | المنصة. المنصة تشترط billing_street غير فارغ وترفض الفاتورة بدونه،
         | وفقدان الفاتورة أسوأ من عنوان ناقص يُصحَّح لاحقاً.
         */
        'fallback_billing_street' => env('WAZ_BUSINESS_FALLBACK_STREET', 'Saudi Arabia'),

        'tags' => 'Mnjz Chat, Service fee',

        /** أوصاف الخدمات كما هي معتمدة في واز — لا تُترجم ولا تُغيَّر. */
        'descriptions' => [
            'setup' => 'Mnjz Chat "setup fees"',
            'start' => 'Mnjz Chat "Start plan"',
            'pro' => 'Mnjz Chat "Pro plan"',
            'business' => 'Mnjz Chat "Business plan"',
            'business_pro' => 'Mnjz Chat "Business pro plan"',
        ],

        'long_descriptions' => [
            'setup' => 'The setup fee for creating a Mnjz Chat platform account is a one-time payment.',
            'plan' => 'Monthly subscription plan for WhatsApp API service through Mnjz Chat platform.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | التذاكر
    |--------------------------------------------------------------------------
    */
    'tickets' => [
        'departments' => [
            'support' => '1',
            'accounting' => '2',
            'sales' => '3',
        ],
        'priorities' => [
            'low' => '1',
            'medium' => '2',
            'high' => '3',
        ],
        'default_department' => '1',
        'default_priority' => '1',
        'service' => '1',
        'tags' => 'Mnjz Chat',
        /** حقل اسم الشركة المخصّص في التذكرة. */
        'company_custom_field' => '6',
        /** رابط عرض التذكرة للعميل: {base}/forms/tickets/{ticketkey} */
        'view_path' => '/forms/tickets/',
    ],

    /*
    |--------------------------------------------------------------------------
    | الاجتماعات
    |--------------------------------------------------------------------------
    |
    | اللون يصنّف سبب الاجتماع لفريق الدعم — القيم من توثيق Postman.
    */
    'meetings' => [
        'colors' => [
            'platform_issue' => '#fb3b3b',
            'how_to_use' => '#84C529',
            'campaigns' => '#fb8c00',
            'auto_reply_chatbot' => '#0288d1',
            'contacts_import' => '#8e24aa',
        ],
        'default_color' => '#84C529',
        /*
         | تنسيق التاريخ. توثيق Postman يذكر d/m/Y h:i A لكن المنصة لا تفهمه
         | وتخزّن 1970-01-01 بصمت. و d/m/Y H:i أسوأ: تُقرأ شهراً/يوماً فينقلب
         | 9 أغسطس إلى 8 سبتمبر دون أي خطأ. ISO وحده غير ملتبس.
         */
        'datetime_format' => 'Y-m-d H:i:s',
        'reminder_before_type' => 'Minutes',
        'reminder_before' => '30',
    ],

    /** رابط عرض الفاتورة للعميل: {base}/invoice/{id}/{hash} */
    'invoice_view_path' => '/invoice/',

];
