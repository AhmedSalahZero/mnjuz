<?php

namespace Tests\Feature\MobileApi;

use App\Services\ContactPlaceholderService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * متغيّرات الرسائل من التطبيق — نافذة «اختر متغيّر» في الويب.
 *
 * التطبيق كان لا يجد لها مصدراً: نصف القائمة حقول مخصّصة تُعرَّف في كل منشأة
 * على حدة، فلا يصحّ أن يحفظها في كوده.
 */
class MobilePlaceholderTest extends MobileApiTestCase
{
    private function field(string $name, ?int $organizationId = null, ?int $position = null): int
    {
        return DB::table('contact_fields')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $organizationId ?? $this->organization->id,
            'name' => $name,
            'type' => 'text',
            'required' => 0,
            'position' => $position,
        ]);
    }

    /** @return array<int, string> */
    private function values(array $items): array
    {
        return array_column($items, 'value');
    }

    // ------------------------------------------------- الوصول

    public function test_it_requires_authentication(): void
    {
        app('auth')->forgetGuards();

        $this->getJson('/api/v1/settings/placeholders')->assertStatus(401);
    }

    public function test_an_owner_gets_the_list(): void
    {
        $this->getJson('/api/v1/settings/placeholders')
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    /** القراءة للجميع: المتغيّرات تُعرض في شاشات يستعملها الموظّف كذلك. */
    public function test_a_manager_and_an_agent_can_read_them(): void
    {
        foreach (['manager', 'agent'] as $role) {
            $this->actAs($this->member($role));

            $this->getJson('/api/v1/settings/placeholders')->assertOk();
        }
    }

    // ------------------------------------------------- المحتوى

    public function test_it_returns_the_built_in_variables(): void
    {
        $values = $this->values($this->getJson('/api/v1/settings/placeholders')->json('data'));

        foreach (['{first_name}', '{last_name}', '{full_name}', '{email}', '{phone}', '{group}',
                  '{organization_name}', '{full_address}', '{street}', '{city}', '{state}',
                  '{zip_code}', '{country}'] as $token) {
            $this->assertContains($token, $values, $token . ' مفقود');
        }
    }

    public function test_every_built_in_has_a_url_encoded_twin(): void
    {
        $values = $this->values($this->getJson('/api/v1/settings/placeholders')->json('data'));

        foreach (['first_name', 'last_name', 'full_name', 'email', 'phone', 'group',
                  'organization_name', 'full_address', 'street', 'city', 'state',
                  'zip_code', 'country'] as $field) {
            $this->assertContains('{url:' . $field . '}', $values, $field . ' بلا نسخة URL');
        }
    }

    public function test_every_item_has_a_value_and_a_label(): void
    {
        foreach ($this->getJson('/api/v1/settings/placeholders')->json('data') as $item) {
            $this->assertArrayHasKey('value', $item);
            $this->assertArrayHasKey('label', $item);
            $this->assertMatchesRegularExpression('/^\{(url:)?.+\}$/u', $item['value']);
            $this->assertNotSame('', trim((string) $item['label']));
        }
    }

    // ------------------------------------------------- الحقول المخصّصة

    public function test_a_custom_field_appears_with_both_forms(): void
    {
        $this->field('Order ID');

        $items = $this->getJson('/api/v1/settings/placeholders')->json('data');
        $values = $this->values($items);

        $this->assertContains('{order_id}', $values);
        $this->assertContains('{url:order_id}', $values);

        $plain = collect($items)->firstWhere('value', '{order_id}');
        $encoded = collect($items)->firstWhere('value', '{url:order_id}');

        $this->assertSame('Order ID', $plain['label'], 'التسمية تبقى كما كتبها العميل');
        $this->assertSame('Order ID (URL encoded)', $encoded['label']);
    }

    public function test_an_arabic_custom_field_keeps_its_letters(): void
    {
        $this->field('رقم الطلب');

        $values = $this->values($this->getJson('/api/v1/settings/placeholders')->json('data'));

        $this->assertContains('{رقم_الطلب}', $values);
        $this->assertContains('{url:رقم_الطلب}', $values);
    }

    /** اسمٌ بمسافات طرفية كان يُنتج رمزاً بشرطة سفلية زائدة لا تُطابق شيئاً. */
    public function test_surrounding_spaces_are_trimmed(): void
    {
        $this->field('  Order ID  ');

        $this->assertContains('{order_id}', $this->values($this->getJson('/api/v1/settings/placeholders')->json('data')));
    }

    /** حقول منشأة أخرى لا تظهر. */
    public function test_custom_fields_of_another_organization_are_hidden(): void
    {
        $this->field('Secret Field', $this->organization->id + 999);

        $values = $this->values($this->getJson('/api/v1/settings/placeholders')->json('data'));

        $this->assertNotContains('{secret_field}', $values);
        $this->assertNotContains('{url:secret_field}', $values);
    }

    public function test_a_deleted_custom_field_is_hidden(): void
    {
        $id = $this->field('Old Field');
        DB::table('contact_fields')->where('id', $id)->update(['deleted_at' => now()]);

        $values = $this->values($this->getJson('/api/v1/settings/placeholders')->json('data'));

        $this->assertNotContains('{old_field}', $values);
    }

    /** الترتيب: الثابتة، ثمّ المخصّصة، ثمّ نسخ URL للمخصّصة. */
    public function test_custom_fields_come_after_the_built_ins(): void
    {
        $this->field('Order ID');

        $values = $this->values($this->getJson('/api/v1/settings/placeholders')->json('data'));

        $this->assertLessThan(array_search('{order_id}', $values, true), array_search('{country}', $values, true));
        $this->assertLessThan(array_search('{url:order_id}', $values, true), array_search('{order_id}', $values, true));
    }

    /** وترتيب المخصّصة يتبع position كما رتّبها العميل. */
    public function test_custom_fields_follow_their_configured_order(): void
    {
        $this->field('Second', null, 2);
        $this->field('First', null, 1);

        $values = $this->values($this->getJson('/api/v1/settings/placeholders')->json('data'));

        $this->assertLessThan(array_search('{second}', $values, true), array_search('{first}', $values, true));
    }

    public function test_an_organization_without_custom_fields_gets_the_built_ins_only(): void
    {
        $items = $this->getJson('/api/v1/settings/placeholders')->json('data');

        $this->assertCount(count(config('formats.placeholders')), $items);
    }

    /** النقطة لا تخترع شيئاً: ما تُرجعه هو ما تراه الويب حرفاً بحرف. */
    public function test_the_endpoint_returns_exactly_what_the_web_builds(): void
    {
        $this->field('Order ID');

        $this->assertSame(
            ContactPlaceholderService::optionsForOrganization($this->organization->id),
            $this->getJson('/api/v1/settings/placeholders')->json('data')
        );
    }
}
