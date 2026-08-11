<?php

namespace Tests\Unit;

use App\Models\Contact;
use App\Observers\ContactObserver;
use App\Services\PhoneService;
use PHPUnit\Framework\TestCase;

/**
 * القيد الفريد على (organization_id, phone) يقارن النصّ لا الرقم، فصيغ الرقم
 * الواحد المختلفة كانت تمرّ جميعاً — 221 حالة تكرار في الإنتاج. هذه الاختبارات
 * تحرس التطبيع الذي يجعل القيد فعّالاً.
 */
class ContactPhoneNormalizationTest extends TestCase
{
    private function saving(string|null $phone): Contact
    {
        $contact = new Contact(['phone' => $phone]);
        (new ContactObserver())->saving($contact);

        return $contact;
    }

    /** الخاصية الجوهرية: كل صيغ الرقم الواحد تنتهي إلى نصّ واحد. */
    public function test_every_form_of_the_same_number_converges_to_one_string(): void
    {
        $forms = [
            '+966537675751',
            '966537675751',
            '00966537675751',
            '+966 53 767 5751',
            '966-53-767-5751',
            '966 537 675 751',
        ];

        foreach ($forms as $form) {
            $this->assertSame(
                '+966537675751',
                $this->saving($form)->phone,
                'لم تتطبّع الصيغة: ' . $form
            );
        }
    }

    public function test_a_number_that_cannot_be_parsed_is_left_exactly_as_entered(): void
    {
        // رقم محلي مجرّد: افتراض مفتاح دولة له قد يوجّه الرسالة إلى بلد آخر.
        $this->assertSame('0537675751', $this->saving('0537675751')->phone);
        $this->assertSame('abc', $this->saving('abc')->phone);
        $this->assertSame('+9665376757510000', $this->saving('+9665376757510000')->phone);
    }

    public function test_empty_phone_clears_the_display_format(): void
    {
        $contact = $this->saving(null);

        $this->assertNull($contact->formatted_phone);
    }

    /** الصفوف القائمة لا تُمسّ عند حفظٍ لا يخصّ الهاتف. */
    public function test_an_existing_row_is_untouched_when_saved_for_another_reason(): void
    {
        $contact = new Contact();
        $contact->setRawAttributes([
            'id' => 1,
            'phone' => '+537675751',
            'organization_id' => 10,
            'is_blocked' => 0,
        ]);
        $contact->syncOriginal();

        $contact->is_blocked = 1;
        (new ContactObserver())->saving($contact);

        $this->assertFalse($contact->isDirty('phone'));
        $this->assertSame('+537675751', $contact->phone);
    }

    public function test_editing_the_phone_normalises_it(): void
    {
        $contact = new Contact();
        $contact->setRawAttributes(['id' => 1, 'phone' => '+966501111111', 'organization_id' => 10]);
        $contact->syncOriginal();

        $contact->phone = '966 50 222 2222';
        (new ContactObserver())->saving($contact);

        $this->assertSame('+966502222222', $contact->phone);
    }

    public function test_a_foreign_number_keeps_its_own_country_code(): void
    {
        $this->assertSame('+971559232275', $this->saving('971559232275')->phone);
        $this->assertSame('+20554881779', $this->saving('20554881779')->phone);
    }

    public function test_to_e164_returns_null_rather_than_guessing(): void
    {
        $this->assertNull(PhoneService::toE164('0537675751'));
        $this->assertNull(PhoneService::toE164(''));
        $this->assertNull(PhoneService::toE164(null));
    }

    public function test_display_format_follows_the_normalised_number(): void
    {
        $this->assertSame('+966 53 767 5751', $this->saving('966537675751')->formatted_phone);
    }
}
