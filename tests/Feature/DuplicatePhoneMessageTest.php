<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Organization;
use App\Models\User;
use App\Rules\UniquePhone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * رسالة «الرقم موجود مسبقاً».
 *
 * من الإنتاج: عميلة تحاول إضافة رقم فيُقال لها «موجود مسبقاً»، فتبحث عنه
 * ولا تجده، فتفتح شكوى. والرقم موجود فعلاً باسم لاتيني وهي تبحث بالاسم
 * العربي — وعندها ٢٨ ألف جهة اتصال.
 *
 * والقاعدة تعرف اسم الصفّ الذي وجدته ثم ترميه وتقول «موجود» فقط.
 */
class DuplicatePhoneMessageTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => 'user']);
        $this->organization = Organization::factory()->create(['created_by' => $this->owner->id]);
    }

    private function contact(array $attributes = []): Contact
    {
        return Contact::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'first_name' => 'Abeer',
            'last_name' => 'Albtla',
            'phone' => '+966556149709',
            'created_by' => 0,
        ], $attributes));
    }

    private function check(string $phone, ?string $uuid = null): array
    {
        $validator = Validator::make(
            ['phone' => $phone],
            ['phone' => [new UniquePhone($this->organization->id, $uuid)]]
        );

        return [
            'fails' => $validator->fails(),
            'message' => $validator->errors()->first('phone'),
        ];
    }

    // ------------------------------------------------- الرسالة

    /** العطل نفسه: الاسم كان يُعرَف ثم يُرمى. */
    public function test_the_message_names_the_existing_contact(): void
    {
        $this->contact();

        $result = $this->check('+966556149709');

        $this->assertTrue($result['fails']);
        $this->assertStringContainsString('Abeer Albtla', $result['message']);
    }

    /** ويقول أين يبحث — البحث بالاسم لا يجدها إن كان الاسم بلغة أخرى. */
    public function test_the_message_tells_where_to_look(): void
    {
        $this->contact();

        $this->assertStringContainsString('number', $this->check('+966556149709')['message']);
    }

    /** واسم من كلمة واحدة يظهر بلا مسافة زائدة. */
    public function test_a_single_word_name_has_no_stray_space(): void
    {
        $this->contact(['first_name' => 'عبير', 'last_name' => null]);

        $this->assertSame(
            __('This number is already saved under the contact ":name". Search for it by number in Contacts.', [
                'name' => 'عبير',
            ]),
            $this->check('+966556149709')['message'],
            'الاسم من كلمة واحدة يجب ألّا يجرّ مسافة الاسم الثاني الفارغ'
        );
    }

    /** وجهة اتصال بلا اسم تعود للرسالة العامّة بدل اسم فارغ بين قوسين. */
    public function test_a_nameless_contact_falls_back_to_the_plain_message(): void
    {
        $this->contact(['first_name' => null, 'last_name' => null]);

        $message = $this->check('+966556149709')['message'];

        $this->assertSame(__('This phone number already exists'), $message);
        $this->assertStringNotContainsString('«»', $message);
    }

    // ------------------------------------------------- صيغ الرقم

    /**
     * الصيغ المختلفة للرقم نفسه تُكتشف كلّها.
     *
     * فمن كتبه بصيغة أخرى لا يُنشئ نسخة ثانية.
     *
     * @dataProvider sameNumber
     */
    public function test_every_format_of_the_same_number_is_detected(string $typed): void
    {
        $this->contact();

        $this->assertTrue($this->check($typed)['fails'], $typed . ' لم يُكتشف');
    }

    public static function sameNumber(): array
    {
        return [
            'بالبادئة' => ['+966556149709'],
            'بلا بادئة' => ['966556149709'],
            'بمسافات' => ['+966 55 614 9709'],
            'بشرطات' => ['+966-55-614-9709'],
        ];
    }

    // ------------------------------------------------- ما يجب أن يمرّ

    public function test_a_new_number_passes(): void
    {
        $this->contact();

        $this->assertFalse($this->check('+966500000001')['fails']);
    }

    /** ورقم منشأة أخرى لا يمنع — العزل قائم. */
    public function test_a_number_in_another_organization_does_not_block(): void
    {
        $other = Organization::factory()->create(['created_by' => $this->owner->id]);
        Contact::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $other->id,
            'first_name' => 'غريب',
            'phone' => '+966556149709',
            'created_by' => 0,
        ]);

        $this->assertFalse($this->check('+966556149709')['fails']);
    }

    /** وجهة اتصال محذوفة لا تحجز الرقم. */
    public function test_a_deleted_contact_does_not_block(): void
    {
        $contact = $this->contact();
        Contact::where('id', $contact->id)->update(['deleted_at' => now()]);

        $this->assertFalse($this->check('+966556149709')['fails']);
    }

    /** وتعديل جهة الاتصال نفسها لا يعدّها تكراراً لنفسها. */
    public function test_editing_the_same_contact_is_not_a_duplicate(): void
    {
        $contact = $this->contact();

        $this->assertFalse($this->check('+966556149709', (string) $contact->uuid)['fails']);
    }

    // ------------------------------------------------- الرقم غير الصالح

    /**
     * رقم غير صالح كان يقول «موجود مسبقاً».
     *
     * فمن كتب رقماً خاطئاً يبحث عن تكرار لا وجود له — وهو ما أضاع وقت العميلة.
     */
    public function test_an_invalid_number_says_it_is_invalid_not_duplicate(): void
    {
        $result = $this->check('ليس رقماً');

        $this->assertTrue($result['fails']);
        $this->assertSame(__('This phone number is not valid.'), $result['message']);
        $this->assertStringNotContainsString('already', $result['message']);
    }

    public function test_an_empty_number_is_not_reported_as_duplicate(): void
    {
        $this->assertStringNotContainsString('already', $this->check('')['message']);
    }

    // ------------------------------------------------- الحالة بين الاستدعاءات

    /**
     * القاعدة تُستعمل مرّة واحدة لكل طلب، لكن الحالة تُصفَّر احتياطاً:
     * اسم من تحقّقٍ سابق لا يظهر في رسالة تحقّقٍ لاحق.
     */
    public function test_the_rule_does_not_leak_a_name_between_checks(): void
    {
        $this->contact();
        $rule = new UniquePhone($this->organization->id);

        $this->assertFalse($rule->passes('phone', '+966556149709'));
        $this->assertStringContainsString('Abeer Albtla', $rule->message());

        $this->assertFalse($rule->passes('phone', 'ليس رقماً'));
        $this->assertSame(__('This phone number is not valid.'), $rule->message());
    }
}
