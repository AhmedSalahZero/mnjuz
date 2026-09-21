<?php

namespace Tests\Feature\Cycle;

use App\Helpers\MessagingWindowHelper;
use App\Jobs\SendMediaJob;
use App\Models\Chat;
use App\Models\Contact;
use App\Services\WhatsappService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * الطرف الآخر من الدورة: ما يخرج منّا.
 *
 * كل إرسال يمرّ بحرّاس قبل أن يبلغ Meta — نافذة الأربع والعشرين ساعة، ونصّ
 * غير فارغ، ورقم صالح، وقالب غير محذوف. وأيّ ثغرة فيها تُنتج طلباً ترفضه
 * Meta برمز غامض يراه الموظّف «فشل» بلا سبب.
 *
 * لا نُتِمّ الإرسال هنا — يحتاج شبكة — بل نتحقّق أن الحرّاس يردّون قبلها،
 * وأن الردّ يحمل سبباً مفهوماً.
 */
class OutboundSendCycleTest extends CycleTestCase
{
    private function service(): WhatsappService
    {
        return new WhatsappService('token', 'v21.0', 'app', 'phone-id', 'waba', $this->organization->id);
    }

    /** جهة اتصال نافذتها مفتوحة: آخر وارد قبل ساعة. */
    private function reachable(array $attributes = []): Contact
    {
        $contact = $this->contact($attributes);

        Chat::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'organization_id' => $this->organization->id,
            'contact_id' => $contact->id,
            'wam_id' => 'wamid.' . \Illuminate\Support\Str::random(20),
            'type' => 'inbound',
            'status' => 'delivered',
            'metadata' => json_encode(['type' => 'text', 'text' => ['body' => 'مرحباً']]),
            'created_at' => now()->subHour(),
        ]);

        return $contact->fresh();
    }

    // ------------------------------------------------- نافذة الأربع والعشرين

    public function test_the_window_opens_with_an_inbound_message(): void
    {
        $this->assertTrue(MessagingWindowHelper::isMessagingWindowOpen($this->reachable()));
    }

    public function test_the_window_is_closed_without_any_inbound_message(): void
    {
        $this->assertFalse(MessagingWindowHelper::isMessagingWindowOpen($this->contact()));
    }

    /** @dataProvider windowAges */
    public function test_the_window_follows_the_age_of_the_last_inbound(int $hoursAgo, bool $open): void
    {
        $contact = $this->contact();

        Chat::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'organization_id' => $this->organization->id,
            'contact_id' => $contact->id,
            'wam_id' => 'wamid.' . \Illuminate\Support\Str::random(20),
            'type' => 'inbound',
            'status' => 'delivered',
            'metadata' => json_encode(['type' => 'text']),
            'created_at' => now()->subHours($hoursAgo),
        ]);

        $this->assertSame($open, MessagingWindowHelper::isMessagingWindowOpen($contact->fresh()));
    }

    public static function windowAges(): array
    {
        return [
            'قبل ساعة' => [1, true],
            'قبل ٢٣ ساعة' => [23, true],
            'قبل ٢٥ ساعة' => [25, false],
            'قبل أسبوع' => [168, false],
        ];
    }

    /** ورسالة صادرة لا تفتح النافذة — العميل هو من يفتحها. */
    public function test_an_outbound_message_does_not_open_the_window(): void
    {
        $contact = $this->contact();
        $this->chat($contact, ['type' => 'outbound', 'created_at' => now()]);

        $this->assertFalse(MessagingWindowHelper::isMessagingWindowOpen($contact->fresh()));
    }

    /** والرسالة الواردة المحذوفة تُحتسب كما هي — الحذف لا يُغلق النافذة. */
    public function test_the_payload_reports_the_window_state(): void
    {
        $payload = MessagingWindowHelper::payloadForContact($this->reachable());

        $this->assertTrue($payload['is_messaging_window_open']);
        $this->assertNotNull($payload['last_inbound_chat_created_at']);
        $this->assertNotNull($payload['last_inbound_chat_created_at_iso']);
    }

    public function test_the_payload_of_an_unreachable_contact_is_empty(): void
    {
        $payload = MessagingWindowHelper::payloadForContact($this->contact());

        $this->assertFalse($payload['is_messaging_window_open']);
        $this->assertNull($payload['last_inbound_chat_created_at']);
        $this->assertNull($payload['last_inbound_chat']);
    }

    // ------------------------------------------------- حرّاس الإرسال

    /**
     * رسالة فارغة لا تُرسَل — Meta ترفضها برمز غامض.
     *
     * والتوكيد على نصّ الردّ لا على فشله وحده: بلا الحارس يخرج الطلب إلى
     * الشبكة ويفشل هناك، فيمرّ اختبار «فشل الإرسال» وهو لم يختبر شيئاً.
     */
    public function test_an_empty_text_is_refused_before_the_network(): void
    {
        $contact = $this->reachable();

        $response = $this->service()->executeSendMessage($contact->uuid, '   ');

        $this->assertFalse($response->success);
        $this->assertSame(
            __('Message cannot be empty.'),
            $response->message ?? null,
            'الردّ لم يأتِ من الحارس — أي أن الطلب خرج إلى الشبكة'
        );
        $this->assertSame(0, Chat::where('type', 'outbound')->count());
    }

    /** وجهة اتصال بلا رقم كذلك. */
    public function test_a_contact_without_a_phone_is_refused(): void
    {
        $contact = $this->reachable(['phone' => null]);

        $response = $this->service()->executeSendMessage($contact->uuid, 'مرحباً');

        $this->assertFalse($response->success);
        $this->assertSame(__('This contact has no phone number.'), $response->message ?? null);
    }

    /** وجهة اتصال من منشأة أخرى لا تُراسَل. */
    public function test_a_contact_of_another_organization_is_refused(): void
    {
        $other = $this->organization->replicate();
        $other->identifier = (string) \Illuminate\Support\Str::uuid();
        $other->save();

        $contact = Contact::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'organization_id' => $other->id,
            'first_name' => 'غريب',
            'phone' => '+966502486099',
            'created_by' => $this->owner->id,
        ]);

        $this->assertFalse($this->service()->executeSendMessage($contact->uuid, 'مرحباً')->success);
    }

    /** وموقع بلا إحداثيات صالحة. */
    public function test_a_location_without_coordinates_is_refused(): void
    {
        $contact = $this->reachable();

        $response = $this->service()->executeSendMessage(
            $contact->uuid,
            'موقعنا',
            null,
            WhatsappService::TYPE_LOCATION,
            [], [], null, null, null, null,
            ['latitude' => null, 'longitude' => null]
        );

        $this->assertFalse($response->success);
    }

    // ------------------------------------------------- إرسال الوسائط

    /** الوسائط خارج النافذة لا تُرسَل، والملف المؤقّت يُنظَّف. */
    public function test_media_outside_the_window_is_not_sent(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('tmp/photo.png', 'bytes');

        $contact = $this->contact();

        (new SendMediaJob(
            $this->organization->id, $contact->uuid, 'image', 'photo.png',
            'tmp/photo.png', $this->owner->id, null, null
        ))->handle();

        $this->assertSame(0, Chat::where('type', 'outbound')->count());
        Storage::disk('local')->assertMissing('tmp/photo.png');
    }

    /** وجهة اتصال بلا رقم: يُكتب السبب ولا تُعاد المحاولة. */
    public function test_media_for_a_contact_without_a_phone_stops_with_a_reason(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('tmp/photo.png', 'bytes');
        Log::spy();

        $contact = $this->reachable(['phone' => null]);

        (new SendMediaJob(
            $this->organization->id, $contact->uuid, 'image', 'photo.png',
            'tmp/photo.png', $this->owner->id, null, null
        ))->handle();

        Log::shouldHaveReceived('error')
            ->withArgs(fn ($message) => str_contains((string) $message, 'no phone number'))
            ->atLeast()->once();
    }

    /** وملف مؤقّت مفقود لا يُسقط الوظيفة. */
    public function test_a_missing_temp_file_is_survived(): void
    {
        Storage::fake('local');

        (new SendMediaJob(
            $this->organization->id, $this->reachable()->uuid, 'image', 'photo.png',
            'tmp/غير-موجود.png', $this->owner->id, null, null
        ))->handle();

        $this->assertSame(0, Chat::where('type', 'outbound')->count());
    }

    // ------------------------------------------------- وظيفة الرسالة النصّية

    /**
     * وظيفة الإرسال النصّي — يستعملها إشعار خارج الدوام وغيره.
     *
     * منشأة غير موجودة تُنهيها بهدوء بدل أن تُفشل الوظيفة وتُعيدها.
     */
    public function test_the_text_job_stops_quietly_for_a_missing_organization(): void
    {
        (new \App\Jobs\SendTextMessageJob(
            999999, (string) \Illuminate\Support\Str::uuid(), 'مرحباً'
        ))->handle();

        $this->assertSame(0, Chat::count());
    }

    /** وجهة اتصال بلا رقم لا تصل Meta. */
    public function test_the_text_job_refuses_a_contact_without_a_phone(): void
    {
        $contact = $this->reachable(['phone' => null]);

        (new \App\Jobs\SendTextMessageJob(
            $this->organization->id, $contact->uuid, 'مرحباً'
        ))->handle();

        $this->assertSame(0, Chat::where('type', 'outbound')->count());
    }

    /** ونصّ فارغ كذلك. */
    public function test_the_text_job_refuses_an_empty_message(): void
    {
        $contact = $this->reachable();

        (new \App\Jobs\SendTextMessageJob(
            $this->organization->id, $contact->uuid, '   '
        ))->handle();

        $this->assertSame(0, Chat::where('type', 'outbound')->count());
    }

    /** وجهة اتصال محذوفة لا تُراسَل. */
    public function test_a_deleted_contact_is_not_messaged(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('tmp/photo.png', 'bytes');

        $contact = $this->reachable();
        Contact::where('id', $contact->id)->update(['deleted_at' => now()]);

        (new SendMediaJob(
            $this->organization->id, $contact->uuid, 'image', 'photo.png',
            'tmp/photo.png', $this->owner->id, null, null
        ))->handle();

        $this->assertSame(0, Chat::where('type', 'outbound')->count());
    }
}
