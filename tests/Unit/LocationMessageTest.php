<?php

namespace Tests\Unit;

use App\Services\WhatsappService;
use PHPUnit\Framework\TestCase;

/**
 * رسالة الموقع الصادرة من النشاط التجاري إلى العميل.
 *
 * شكل الحمولة هو العقد مع Meta، وأي انحراف فيه يُرفض الطلب برسالة غامضة،
 * فنثبّته هنا حرفاً بحرف مقابل الوثيقة — كما فُعل مع «طلب الموقع».
 */
class LocationMessageTest extends TestCase
{
    public function test_payload_matches_the_documented_shape_exactly(): void
    {
        $payload = WhatsappService::buildLocationPayload([
            'latitude' => 21.485811,
            'longitude' => 39.192505,
            'name' => 'Ladyes',
            'address' => 'الروضة، جدة',
        ]);

        $this->assertSame([
            'type' => 'location',
            'location' => [
                'latitude' => 21.485811,
                'longitude' => 39.192505,
                'name' => 'Ladyes',
                'address' => 'الروضة، جدة',
            ],
        ], $payload);
    }

    /**
     * الطلب الكامل كما يخرج على السلك: الغلاف الذي يبنيه sendMessage مدموجاً
     * بالحمولة. هذا ما تراه Meta، ومطابقته للوثيقة هي المعيار.
     */
    public function test_the_complete_request_matches_the_documented_example(): void
    {
        $envelope = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => '+201025894984',
        ];

        $full = array_merge($envelope, WhatsappService::buildLocationPayload([
            'latitude' => 37.44216251868683,
            'longitude' => -122.16153582049394,
            'name' => 'Philz Coffee',
            'address' => '101 Forest Ave, Palo Alto, CA 94301',
        ]));

        $this->assertSame([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => '+201025894984',
            'type' => 'location',
            'location' => [
                'latitude' => 37.44216252,
                'longitude' => -122.16153582,
                'name' => 'Philz Coffee',
                'address' => '101 Forest Ave, Palo Alto, CA 94301',
            ],
        ], $full);
    }

    /**
     * Meta ترفض السلاسل النصّية في الإحداثيات. الواجهات ترسل الأرقام نصّاً
     * أحياناً (حقل input قيمته دائماً نصّ)، فالتحويل مسؤولية الباني لا المستدعي.
     */
    public function test_string_coordinates_are_cast_to_floats(): void
    {
        $payload = WhatsappService::buildLocationPayload([
            'latitude' => '21.485811',
            'longitude' => '39.192505',
        ]);

        $this->assertIsFloat($payload['location']['latitude']);
        $this->assertIsFloat($payload['location']['longitude']);
        $this->assertSame(21.485811, $payload['location']['latitude']);
    }

    /**
     * الاسم والعنوان اختياريان عند Meta، وتمريرهما فارغين يُظهر بطاقةً ببطن
     * فارغ تحت الخريطة — فنُسقطهما بدل إرسال سلسلة فارغة.
     */
    public function test_blank_name_and_address_are_omitted_entirely(): void
    {
        $payload = WhatsappService::buildLocationPayload([
            'latitude' => 21.485811,
            'longitude' => 39.192505,
            'name' => '   ',
            'address' => '',
        ]);

        $this->assertSame(['latitude', 'longitude'], array_keys($payload['location']));
    }

    public function test_missing_name_and_address_keys_are_tolerated(): void
    {
        $payload = WhatsappService::buildLocationPayload([
            'latitude' => 21.485811,
            'longitude' => 39.192505,
        ]);

        $this->assertArrayNotHasKey('name', $payload['location']);
        $this->assertArrayNotHasKey('address', $payload['location']);
    }

    /**
     * القصّ بالمحارف لا بالبايتات: الحرف العربي بايتان، فالقصّ الخام يشطره
     * ويُنتج نصّاً تالفاً يظهر للعميل رموزاً مشوّهة.
     */
    public function test_long_arabic_name_is_truncated_by_characters_not_bytes(): void
    {
        $name = str_repeat('م', WhatsappService::LOCATION_NAME_MAX + 50);

        $payload = WhatsappService::buildLocationPayload([
            'latitude' => 21.485811,
            'longitude' => 39.192505,
            'name' => $name,
        ]);

        $truncated = $payload['location']['name'];
        $this->assertSame(WhatsappService::LOCATION_NAME_MAX, mb_strlen($truncated));
        $this->assertTrue(mb_check_encoding($truncated, 'UTF-8'));
    }

    public function test_long_address_is_truncated_to_the_documented_limit(): void
    {
        $payload = WhatsappService::buildLocationPayload([
            'latitude' => 21.485811,
            'longitude' => 39.192505,
            'address' => str_repeat('a', WhatsappService::LOCATION_ADDRESS_MAX + 10),
        ]);

        $this->assertSame(
            WhatsappService::LOCATION_ADDRESS_MAX,
            mb_strlen($payload['location']['address'])
        );
    }

    /**
     * @dataProvider unusableLocations
     */
    public function test_unusable_locations_are_rejected(array $location, string $why): void
    {
        $this->assertFalse(WhatsappService::isUsableLocation($location), $why);
    }

    public static function unusableLocations(): array
    {
        return [
            'إحداثيات مفقودة' => [[], 'مصفوفة فارغة'],
            'خط العرض فقط' => [['latitude' => 21.4], 'نصف نقطة'],
            'خط الطول فقط' => [['longitude' => 39.1], 'نصف نقطة'],
            'قيم نصّية غير رقمية' => [['latitude' => 'abc', 'longitude' => 'def'], 'ليست أرقاماً'],
            'قيم فارغة' => [['latitude' => '', 'longitude' => ''], 'حقول لم تُملأ'],
            'قيم null' => [['latitude' => null, 'longitude' => null], 'حقول لم تُملأ'],
            'خط عرض خارج المدى' => [['latitude' => 91, 'longitude' => 0.1], 'أكبر من 90'],
            'خط عرض سالب خارج المدى' => [['latitude' => -91, 'longitude' => 0.1], 'أصغر من -90'],
            'خط طول خارج المدى' => [['latitude' => 21, 'longitude' => 181], 'أكبر من 180'],
            'جزيرة العدم' => [['latitude' => 0, 'longitude' => 0], 'حقل لم يُملأ لا نقطة مقصودة'],
            'جزيرة العدم نصّاً' => [['latitude' => '0', 'longitude' => '0.0'], 'حقل لم يُملأ لا نقطة مقصودة'],
        ];
    }

    /**
     * @dataProvider usableLocations
     */
    public function test_usable_locations_are_accepted(array $location): void
    {
        $this->assertTrue(WhatsappService::isUsableLocation($location));
    }

    public static function usableLocations(): array
    {
        return [
            'جدة' => [['latitude' => 21.485811, 'longitude' => 39.192505]],
            'نصّاً' => [['latitude' => '21.485811', 'longitude' => '39.192505']],
            'حدود المدى' => [['latitude' => 90, 'longitude' => 180]],
            'خط عرض صفر وخط طول غير صفر' => [['latitude' => 0, 'longitude' => 39.1]],
            'سالب' => [['latitude' => -33.86, 'longitude' => -151.2]],
        ];
    }

    public function test_non_array_input_is_rejected(): void
    {
        $this->assertFalse(WhatsappService::isUsableLocation(null));
        $this->assertFalse(WhatsappService::isUsableLocation('21.4,39.1'));
    }
}
