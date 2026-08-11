<?php

namespace App\Observers;

use App\Models\Contact;
use App\Services\PhoneService;

class ContactObserver
{
    public function saving(Contact $contact): void
    {
        if (!$contact->isDirty('phone')) {
            return;
        }

        $phone = $contact->phone;
        if ($phone === null || $phone === '') {
            $contact->formatted_phone = null;

            return;
        }

        // تطبيع الرقم إلى E.164 قبل الحفظ.
        //
        // القيد الفريد (organization_id, phone_active_key) يقارن النصّ لا الرقم،
        // فـ «+966537675751» و«966537675751» و«+966 53 767 5751» ثلاثة نصوص عنده
        // ويقبلها جميعاً لنفس المشترك. وقياسُنا على الإنتاج وجد 221 حالة تكرار
        // من هذا الباب. التطبيع هنا يوحّد الصيغة فيصير القيد فعّالاً حقاً.
        //
        // ولماذا هنا لا في الكنترولر: هذه نقطة العبور الوحيدة لكل كتابة —
        // الويب والتطبيق ومفتاح الـ API والاستيراد والويب هوك. بعض المسارات
        // تُطبّع أصلاً (ContactService::findOrCreateByPhone) وبعضها يكتفي
        // بإلصاق «+» بلا تحقّق (StoreContactRequest، ContactsImport)، فالحارس
        // الموثوق واحد لا موزّع.
        //
        // isDirty أعلاه يقصر الأثر على الأرقام الجديدة أو المعدَّلة، فالصفوف
        // القائمة لا تُمسّ عند أي حفظ آخر.
        $normalized = PhoneService::toE164($phone);

        // رقم لا يُحلَّل دولياً — محلي مجرّد أو ناقص — يبقى كما أدخله صاحبه.
        // افتراض مفتاح دولة له قد يوجّه رسالة عميل إلى بلد آخر، والإبقاء عليه
        // كما هو أسلم من تغييره بظنّ.
        if ($normalized !== null) {
            $contact->phone = $normalized;
            $phone = $normalized;
        }

        $contact->formatted_phone = PhoneService::formatForDisplay($phone);
    }
}
