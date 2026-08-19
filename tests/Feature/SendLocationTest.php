<?php

namespace Tests\Feature;

use App\Models\Chat;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Setting;
use App\Models\Team;
use App\Models\User;
use App\Services\ChatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * إرسال موقع النشاط التجاري إلى العميل.
 *
 * الحُرّاس هنا هي ما يُختبر: كلٌّ منها يمنع رسالةً تصل العميل خاطئة أو لا تصل
 * أصلاً. النداء الشبكي إلى Meta خارج نطاق هذه الاختبارات — شكل حمولته مثبّت
 * في LocationMessageTest، وكل ما دونه يتوقّف قبل بلوغ الشبكة.
 */
class SendLocationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Organization $organization;
    private Contact $contact;

    /** إحداثيات حقيقية لحيّ الروضة بجدة — تُستخدم في كل الاختبارات. */
    private const LAT = 21.485811;
    private const LNG = 39.192505;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['role' => 'user']);
        $this->organization = Organization::factory()->create([
            'name' => 'Ladyes',
            'created_by' => $this->user->id,
        ]);

        Team::factory()->create([
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'role' => 'owner',
            'created_by' => $this->user->id,
        ]);

        $this->contact = Contact::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'first_name' => 'Ahmed',
            'last_name' => 'Salah',
            'phone' => '+201025894984',
            'created_by' => $this->user->id,
        ]);

        // باني WhatsappService يقرأ مفاتيح Pusher من جدول الإعدادات بلا قيمة
        // بديلة، فيسقط على قاعدة اختبارات نظيفة قبل بلوغ أي منطق يخصّنا.
        foreach (['pusher_app_key', 'pusher_app_secret', 'pusher_app_id', 'pusher_app_cluster'] as $key) {
            Setting::create(['key' => $key, 'value' => 'test']);
        }

        $this->actingAs($this->user);
    }

    private function service(): ChatService
    {
        return new ChatService($this->organization->id);
    }

    /** نافذة الـ24 ساعة تُفتح برسالة واردة حديثة لا بشيء آخر. */
    private function openMessagingWindow(): void
    {
        Chat::create([
            'organization_id' => $this->organization->id,
            'contact_id' => $this->contact->id,
            'type' => 'inbound',
            'metadata' => json_encode(['type' => 'text', 'text' => ['body' => 'مرحباً']]),
            'status' => 'delivered',
            'created_at' => now()->subMinutes(5),
        ]);
    }

    private function setOrganizationAddress(array $address): void
    {
        $this->organization->address = json_encode($address);
        $this->organization->save();
    }

    private function validLocation(): array
    {
        return [
            'latitude' => self::LAT,
            'longitude' => self::LNG,
            'name' => 'Ladyes',
            'address' => 'الروضة، جدة',
        ];
    }

    // ---------------------------------------------------------------- الحُرّاس

    public function test_unknown_contact_is_rejected_with_404(): void
    {
        $response = $this->service()->sendLocation((string) Str::uuid(), $this->validLocation());

        $this->assertSame(404, $response->getStatusCode());
        $this->assertFalse($response->getData(true)['success']);
    }

    /**
     * جهة اتصال بنفس الـuuid لكن في منشأة أخرى يجب ألّا تُرى إطلاقاً — وإلا
     * أرسل موظّف منشأةٍ موقعاً لعميل منشأة أخرى.
     */
    public function test_contact_of_another_organization_is_not_reachable(): void
    {
        $otherOrganization = Organization::factory()->create(['created_by' => $this->user->id]);
        $foreignContact = Contact::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $otherOrganization->id,
            'first_name' => 'Foreign',
            'phone' => '+201111111111',
            'created_by' => $this->user->id,
        ]);

        $response = $this->service()->sendLocation($foreignContact->uuid, $this->validLocation());

        $this->assertSame(404, $response->getStatusCode());
    }

    /**
     * لا رسالة واردة أصلاً = نافذة مغلقة. Meta ترفض الرسالة الحرّة خارجها،
     * فنمنعها قبل استهلاك الطلب ونشرح السبب للموظّف.
     */
    public function test_closed_messaging_window_blocks_the_send(): void
    {
        $response = $this->service()->sendLocation($this->contact->uuid, $this->validLocation());

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('24 hours', $response->getData(true)['message']);
    }

    public function test_inbound_message_older_than_24_hours_keeps_the_window_closed(): void
    {
        Chat::create([
            'organization_id' => $this->organization->id,
            'contact_id' => $this->contact->id,
            'type' => 'inbound',
            'metadata' => json_encode(['type' => 'text']),
            'status' => 'delivered',
            'created_at' => now()->subHours(25),
        ]);

        $response = $this->service()->sendLocation($this->contact->uuid, $this->validLocation());

        $this->assertSame(422, $response->getStatusCode());
    }

    /**
     * @dataProvider invalidCoordinates
     */
    public function test_invalid_coordinates_are_rejected_before_reaching_meta(array $location): void
    {
        $this->openMessagingWindow();

        $response = $this->service()->sendLocation($this->contact->uuid, $location);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('A valid location is required.', $response->getData(true)['message']);
    }

    public static function invalidCoordinates(): array
    {
        return [
            'فارغة' => [[]],
            'خط عرض فقط' => [['latitude' => self::LAT]],
            'خط طول فقط' => [['longitude' => self::LNG]],
            'جزيرة العدم' => [['latitude' => 0, 'longitude' => 0]],
            'خارج المدى' => [['latitude' => 120, 'longitude' => 39.1]],
            'غير رقمية' => [['latitude' => 'here', 'longitude' => 'there']],
            'null' => [['latitude' => null, 'longitude' => null]],
        ];
    }

    /**
     * الترتيب مقصود: النافذة تُفحص قبل الإحداثيات. رسالة «النافذة مغلقة» أنفع
     * للموظّف من «الموقع غير صالح» حين يكون كلاهما صحيحاً.
     */
    public function test_window_check_precedes_coordinate_check(): void
    {
        $response = $this->service()->sendLocation($this->contact->uuid, ['latitude' => 0, 'longitude' => 0]);

        $this->assertStringContainsString('24 hours', $response->getData(true)['message']);
    }

    // ------------------------------------------- موقع النشاط المحفوظ

    public function test_organization_without_coordinates_has_no_location(): void
    {
        $this->setOrganizationAddress([
            'street' => 'الروضة',
            'city' => 'جدة',
            'country' => 'Saudi Arabia',
        ]);

        $this->assertNull($this->service()->getOrganizationLocation());
    }

    public function test_organization_with_no_address_at_all_has_no_location(): void
    {
        $this->assertNull($this->service()->getOrganizationLocation());
    }

    public function test_zero_coordinates_are_treated_as_unset_not_as_null_island(): void
    {
        $this->setOrganizationAddress(['latitude' => 0, 'longitude' => 0, 'city' => 'جدة']);

        $this->assertNull($this->service()->getOrganizationLocation());
    }

    public function test_saved_coordinates_resolve_into_a_sendable_location(): void
    {
        $this->setOrganizationAddress([
            'street' => 'الروضة',
            'city' => 'جدة',
            'state' => 'Makkah',
            'zip' => '23444',
            'country' => 'Saudi Arabia',
            'latitude' => self::LAT,
            'longitude' => self::LNG,
        ]);

        $location = $this->service()->getOrganizationLocation();

        $this->assertSame(self::LAT, $location['latitude']);
        $this->assertSame(self::LNG, $location['longitude']);
        $this->assertSame('Ladyes', $location['name']);
        $this->assertSame('الروضة، جدة، Makkah، 23444، Saudi Arabia', $location['address']);
    }

    /**
     * الحقول غير المملوءة تُسقَط: الفواصل المتتالية حول الفراغات تظهر للعميل
     * في بطاقة الموقع نفسها.
     */
    public function test_blank_address_parts_are_dropped_from_the_label(): void
    {
        $this->setOrganizationAddress([
            'street' => 'الروضة',
            'city' => '',
            'state' => '   ',
            'zip' => null,
            'country' => 'Saudi Arabia',
            'latitude' => self::LAT,
            'longitude' => self::LNG,
        ]);

        $this->assertSame('الروضة، Saudi Arabia', $this->service()->getOrganizationLocation()['address']);
    }

    public function test_coordinates_stored_as_strings_still_resolve(): void
    {
        $this->setOrganizationAddress([
            'city' => 'جدة',
            'latitude' => '21.485811',
            'longitude' => '39.192505',
        ]);

        $location = $this->service()->getOrganizationLocation();

        $this->assertIsFloat($location['latitude']);
        $this->assertSame(self::LAT, $location['latitude']);
    }

    // ------------------------------------------------ التحقق في الإعدادات

    /**
     * @dataProvider addressValidationCases
     */
    public function test_organization_address_coordinate_rules(array $input, bool $shouldPass, string $why): void
    {
        $rules = [
            'latitude' => 'nullable|required_with:longitude|numeric|between:-90,90',
            'longitude' => 'nullable|required_with:latitude|numeric|between:-180,180',
        ];

        $this->assertSame($shouldPass, Validator::make($input, $rules)->passes(), $why);
    }

    public static function addressValidationCases(): array
    {
        return [
            'لا إحداثيات إطلاقاً' => [[], true, 'الموقع اختياري كلّياً'],
            'كلاهما null' => [['latitude' => null, 'longitude' => null], true, 'مسح الموقع مسموح'],
            'نقطة كاملة' => [['latitude' => 21.48, 'longitude' => 39.19], true, 'الحالة الطبيعية'],
            'خط عرض بلا خط طول' => [['latitude' => 21.48], false, 'نصف نقطة لا تُرسَل'],
            'خط طول بلا خط عرض' => [['longitude' => 39.19], false, 'نصف نقطة لا تُرسَل'],
            'خط عرض خارج المدى' => [['latitude' => 91, 'longitude' => 39.19], false, 'أكبر من 90'],
            'خط طول خارج المدى' => [['latitude' => 21.48, 'longitude' => 181], false, 'أكبر من 180'],
            'قيمة نصّية' => [['latitude' => 'here', 'longitude' => 'there'], false, 'ليست رقماً'],
        ];
    }

    // ------------------------------------------------------ تسجيل المسارات

    public function test_web_and_mobile_routes_are_registered(): void
    {
        $routes = collect(Route::getRoutes())->map(fn ($route) => $route->methods()[0] . ' ' . $route->uri());

        $this->assertTrue($routes->contains('POST chat/{uuid}/send-location'), 'مسار الويب');
        $this->assertTrue($routes->contains('POST api/v1/send-location'), 'مسار تطبيق الجوال');
        $this->assertTrue($routes->contains('GET api/v1/organization-location'), 'جلب الموقع المحفوظ للتطبيق');
    }
}
