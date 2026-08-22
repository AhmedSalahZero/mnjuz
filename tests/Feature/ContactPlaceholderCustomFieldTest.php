<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Organization;
use App\Models\User;
use App\Services\ContactPlaceholderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * الحقول المخصّصة كمتغيّرات داخل نصّ الرسالة.
 *
 * الخدمة تدعم الحقول المخصّصة منذ البداية — تُقرأ من metadata وتُستبدل مثل
 * {first_name}. لكن النمط كان /\{(\w+)\}/ بلا المُعدِّل u، و\w بدونه حروفٌ
 * لاتينية فقط. فحقلٌ اسمه «عدد الطلبات» لا يُطابَق أبداً: يكتب المستخدم
 * {عدد_الطلبات} فيصل العميل الرمز نفسه، بلا خطأ يشير إلى السبب.
 *
 * أي أن الميزة كانت موجودة وتعمل للإنجليزية وحدها — وهو أسوأ من غيابها.
 */
class ContactPlaceholderCustomFieldTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;
    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = User::factory()->create(['role' => 'user']);
        $this->organization = Organization::factory()->create([
            'created_by' => $owner->id,
            'name' => 'ليديز',
        ]);

        $this->contact = Contact::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'first_name' => 'أحمد',
            'last_name' => 'صلاح',
            'phone' => '+201025894984',
            'created_by' => $owner->id,
            'metadata' => json_encode([
                'عدد الطلبات' => '7',
                'الرقم القومي' => '1111111',
                'order count' => '9',
            ]),
        ]);
    }

    private function render(string $message): string
    {
        return ContactPlaceholderService::replace(
            (int) $this->organization->id,
            $this->contact->uuid,
            $message
        );
    }

    // ------------------------------------------------ الحقل العربي

    public function test_an_arabic_custom_field_is_substituted(): void
    {
        $this->assertSame('طلباتك: 7', $this->render('طلباتك: {عدد_الطلبات}'));
    }

    public function test_more_than_one_arabic_field_in_one_message(): void
    {
        $this->assertSame(
            '7 — 1111111',
            $this->render('{عدد_الطلبات} — {الرقم_القومي}')
        );
    }

    /** ممزوجاً بالحقول المدمجة. */
    public function test_it_mixes_with_built_in_fields(): void
    {
        $this->assertSame(
            'أهلاً أحمد، طلباتك 7 لدى ليديز',
            $this->render('أهلاً {first_name}، طلباتك {عدد_الطلبات} لدى {organization_name}')
        );
    }

    /** وفي الروابط: القيمة تُرمَّز. */
    public function test_an_arabic_field_works_inside_a_url_token(): void
    {
        $this->assertSame(
            'https://mnjz.sa/o/7',
            $this->render('https://mnjz.sa/o/{url:عدد_الطلبات}')
        );
    }

    // -------------------------------------------------- ما لا يتغيّر

    public function test_latin_custom_fields_still_work(): void
    {
        $this->assertSame('9', $this->render('{order_count}'));
    }

    public function test_built_in_fields_still_work(): void
    {
        $this->assertSame('أحمد صلاح +201025894984', $this->render('{first_name} {last_name} {phone}'));
    }

    /** متغيّر لا وجود له يبقى كما هو — لا يُمحى فيصير النصّ ناقصاً بصمت. */
    public function test_an_unknown_placeholder_is_left_untouched(): void
    {
        $this->assertSame('{لا_يوجد}', $this->render('{لا_يوجد}'));
        $this->assertSame('{nope}', $this->render('{nope}'));
    }

    /** ولا يبتلع نصّاً بين قوسين ليس متغيّراً. */
    public function test_plain_braces_are_not_treated_as_variables(): void
    {
        $this->assertSame('{عدد الطلبات}', $this->render('{عدد الطلبات}'));
    }
}
