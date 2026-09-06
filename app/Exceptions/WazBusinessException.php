<?php

namespace App\Exceptions;

use Exception;

/**
 * فشل في الربط مع منصة واز أعمال. يوقف التسجيل قبل إنشاء أي بيانات محلية.
 */
class WazBusinessException extends Exception
{
    /**
     * هل سبب الفشل تعذّر الوصول (مهلة/شبكة) لا رفضاً منطقياً؟
     *
     * الفرق جوهري: المنصة بطيئة أحياناً فتُنفّذ الطلب ثم تتأخر الاستجابة عن
     * المهلة. عندها يكون السجل قد أُنشئ فعلاً، فيصحّ البحث عنه بدل اعتبار
     * العملية فاشلة وإعادة إنشائه لاحقاً مكرّراً.
     */
    public bool $connectionFailed = false;

    /**
     * هل رفضت المنصة الدفعة لأن الفاتورة سُدّدت أصلاً؟
     *
     * تردّ «المبلغ لا يمكن أن يتجاوز رصيد الفاتورة» حين لا يبقى عليها شيء —
     * وهذا ليس عطلاً يستحقّ إعادة المحاولة: الغاية محقَّقة، الفاتورة مدفوعة.
     * إعادة المحاولة خمس مرّات على شيءٍ تمّ تملأ سجلّ الأخطاء بلا فائدة.
     */
    public function invoiceAlreadySettled(): bool
    {
        return str_contains(strtolower($this->getMessage()), 'cannot exceed the invoice remaining balance');
    }

    public static function connection(string $message, ?\Throwable $previous = null): self
    {
        $e = new self($message, 0, $previous);
        $e->connectionFailed = true;

        return $e;
    }
}
