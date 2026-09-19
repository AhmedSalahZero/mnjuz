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
 * الحقل الفارغ داخل نصّ الرسالة.
 *
 * العطل: replace() كانت تشترط isset، وهي false للقيمة null. فحقلٌ لم يملأه
 * العميل يُبقي رمزه ظاهراً — تصل الرسالة إلى العميل وفيها «{email}» حرفياً.
 * وهو يمسّ كل رسالة آلية: رسالة خارج أوقات العمل، والردود الجاهزة.
 *
 * ومعه عطل من الفصيلة نفسها: {full_address} كان يوصل أجزاء العنوان بفواصل
 * بلا شرط، فعميلٌ بلا عنوان تصله «, , , , ».
 *
 * والمقصود من array_key_exists هو المعرفة بوجود الحقل لا بقيمته: الرمز
 * المجهول — الذي لا يقابله حقل أصلاً — يبقى كما هو كما كان.
 */
class ContactPlaceholderEmptyValueTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => 'user']);
        $this->organization = Organization::factory()->create([
            'created_by' => $this->owner->id,
            'name' => 'منجز',
        ]);
    }

    private function contact(array $attributes = []): Contact
    {
        return Contact::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'first_name' => 'أحمد',
            'phone' => '+2010' . random_int(10000000, 99999999),
            'created_by' => $this->owner->id,
        ], $attributes));
    }

    private function render(string $message, ?Contact $contact = null): string
    {
        return ContactPlaceholderService::replace(
            $this->organization->id,
            ($contact ?? $this->contact())->uuid,
            $message
        );
    }

    // ------------------------------------------------- الحقول الفارغة

    /** العطل نفسه. */
    public function test_a_null_field_no_longer_leaves_its_token(): void
    {
        $this->assertSame('', $this->render('{email}'));
    }

    public function test_a_null_last_name_disappears(): void
    {
        $this->assertSame('أهلاً أحمد', trim($this->render('أهلاً {first_name} {last_name}')));
    }

    public function test_an_empty_string_field_disappears(): void
    {
        $contact = $this->contact(['email' => '', 'last_name' => '']);

        $this->assertSame('', $this->render('{email}{last_name}', $contact));
    }

    public function test_the_url_form_of_a_null_field_disappears(): void
    {
        $this->assertSame('https://x.test?e=', $this->render('https://x.test?e={url:email}'));
    }

    public function test_a_contact_without_any_name_renders_nothing(): void
    {
        $contact = $this->contact(['first_name' => null]);

        $this->assertSame('', trim($this->render('{full_name}', $contact)));
    }

    public function test_null_address_parts_disappear(): void
    {
        $this->assertSame('', $this->render('{street}{city}{state}{zip_code}{country}'));
    }

    // ------------------------------------------------- العنوان الكامل

    /** «, , , , » كانت تصل العميل بدل فراغ. */
    public function test_an_empty_address_is_not_a_row_of_commas(): void
    {
        $rendered = $this->render('{full_address}');

        $this->assertSame('', $rendered);
        $this->assertStringNotContainsString(',', $rendered);
    }

    public function test_a_partial_address_has_no_dangling_commas(): void
    {
        $contact = $this->contact([
            'address' => json_encode(['city' => 'الرياض', 'country' => 'السعودية']),
        ]);

        $this->assertSame('الرياض, السعودية', $this->render('{full_address}', $contact));
    }

    public function test_a_full_address_is_unchanged(): void
    {
        $contact = $this->contact([
            'address' => json_encode([
                'street' => 'شارع الملك',
                'city' => 'الرياض',
                'state' => 'الرياض',
                'zip' => '11564',
                'country' => 'السعودية',
            ]),
        ]);

        $this->assertSame(
            'شارع الملك, الرياض, الرياض, 11564, السعودية',
            $this->render('{full_address}', $contact)
        );
    }

    /** ومسافات وحدها ليست جزءاً من العنوان. */
    public function test_whitespace_only_address_parts_are_dropped(): void
    {
        $contact = $this->contact([
            'address' => json_encode(['street' => '   ', 'city' => 'جدة', 'country' => '']),
        ]);

        $this->assertSame('جدة', $this->render('{full_address}', $contact));
    }

    // ------------------------------------------------- الرمز المجهول يبقى

    /** هذا هو الفرق بين «الحقل فارغ» و«لا حقل بهذا الاسم». */
    public function test_an_unknown_token_is_still_left_untouched(): void
    {
        $this->assertSame('{grouping}', $this->render('{grouping}'));
        $this->assertSame('{unknown_field}', $this->render('{unknown_field}'));
        $this->assertSame('{url:unknown_field}', $this->render('{url:unknown_field}'));
    }

    public function test_text_that_is_not_a_token_is_untouched(): void
    {
        $this->assertSame('{ }', $this->render('{ }'));
        $this->assertSame('{a b}', $this->render('{a b}'));
        $this->assertSame('مبلغ 500 {ريال سعودي}', $this->render('مبلغ 500 {ريال سعودي}'));
    }

    // ------------------------------------------------- الحقول المخصّصة

    private function withMetadata(array $metadata): Contact
    {
        return $this->contact(['metadata' => json_encode($metadata)]);
    }

    public function test_a_null_custom_field_disappears(): void
    {
        $contact = $this->withMetadata(['رقم الطلب' => null]);

        $this->assertSame('طلبك: ', $this->render('طلبك: {رقم_الطلب}', $contact));
    }

    /** صفرٌ قيمةٌ لا فراغ — والفرق يظهر مع أي فحص يعتمد الصدق لا الوجود. */
    public function test_a_zero_value_survives(): void
    {
        $contact = $this->withMetadata(['orders' => 0, 'balance' => '0']);

        $this->assertSame('0|0', $this->render('{orders}|{balance}', $contact));
    }

    public function test_boolean_values_render_as_one_and_zero(): void
    {
        $contact = $this->withMetadata(['vip' => true, 'blocked' => false]);

        $this->assertSame('1|0', $this->render('{vip}|{blocked}', $contact));
    }

    public function test_an_array_value_is_joined(): void
    {
        $contact = $this->withMetadata(['tags' => ['ذهبي', 'جملة']]);

        $this->assertSame('ذهبي, جملة', $this->render('{tags}', $contact));
    }

    public function test_an_empty_array_value_disappears(): void
    {
        $contact = $this->withMetadata(['tags' => []]);

        $this->assertSame('', $this->render('{tags}', $contact));
    }

    public function test_a_filled_custom_field_still_works(): void
    {
        $contact = $this->withMetadata(['رقم الطلب' => '551']);

        $this->assertSame('طلبك: 551', $this->render('طلبك: {رقم_الطلب}', $contact));
        $this->assertSame('551', $this->render('{url:رقم_الطلب}', $contact));
    }

    // ------------------------------------------------- ما كان يعمل

    public function test_a_fully_filled_contact_is_unchanged(): void
    {
        $contact = $this->contact([
            'first_name' => 'أحمد',
            'last_name' => 'صلاح',
            'email' => 'a@b.test',
            'phone' => '+201025894984',
        ]);

        $this->assertSame(
            'أحمد صلاح | أحمد | a@b.test | +201025894984 | منجز',
            $this->render('{full_name} | {first_name} | {email} | {phone} | {organization_name}', $contact)
        );
    }

    public function test_url_encoding_still_applies(): void
    {
        $contact = $this->contact(['first_name' => 'أحمد صلاح']);

        $this->assertSame(rawurlencode('أحمد صلاح'), $this->render('{url:first_name}', $contact));
    }

    // ------------------------------------------------- الرسالة كاملةً

    /** رسالة كلّها متغيّرات فارغة تصير فراغاً — والمُرسِل يمتنع عندها. */
    public function test_a_message_of_only_empty_variables_becomes_empty(): void
    {
        $this->assertSame('', trim($this->render('{email}{last_name}{full_address}')));
    }

    /** وكلا المُرسِلَين يمتنع عن إرسال الفراغ — وإلا صار العطل إرسالاً فارغاً. */
    public function test_both_senders_refuse_an_empty_body(): void
    {
        $job = file_get_contents(base_path('app/Jobs/ProcessIncomingMessageJob.php'));
        $service = file_get_contents(base_path('app/Services/AutoReplyService.php'));

        $this->assertMatchesRegularExpression(
            "/ContactPlaceholderService::replace\([^;]+;\s*\n\s*if \(\\\$body === ''\) \{\s*\n\s*return;/",
            $job,
            'رسالة خارج الدوام قد تصير فارغة بعد الاستبدال'
        );
        $this->assertSame(
            2,
            substr_count($service, "if (\$message === '') {"),
            'كلا نوعَي الردّ الجاهز يحتاج الحارس'
        );
    }

    /** ولا يبقى رمز واحد ظاهراً لعميلٍ لا بيانات له إطلاقاً. */
    public function test_no_token_survives_for_a_contact_with_no_data(): void
    {
        $contact = $this->contact(['first_name' => null]);

        foreach (ContactPlaceholderService::optionsForOrganization($this->organization->id) as $option) {
            $this->assertStringNotContainsString(
                $option['value'],
                $this->render($option['value'], $contact),
                $option['value'] . ' يصل العميل كما هو'
            );
        }
    }
}
