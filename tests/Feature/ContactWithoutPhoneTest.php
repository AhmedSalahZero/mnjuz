<?php

namespace Tests\Feature;

use App\Jobs\ProcessIncomingMessageJob;
use App\Jobs\SendMediaJob;
use App\Models\Chat;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Setting;
use App\Models\User;
use App\Services\PhoneService;
use App\Services\WhatsappService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

/**
 * جهة اتصال بلا رقم: من أين جاءت، وماذا تفعل حين نحاول مراسلتها.
 *
 * في سجلّ الإنتاج (منشأة 120): «WhatsApp media send rejected … The parameter
 * to is required». الطلب وصل Meta وفيه to=null، لأن contacts.phone كان NULL.
 *
 * وسبب الـ NULL أن webhook الوارد كان يحفظ ناتج getE164Format كما هو، وهي
 * تُرجع null كلّما رفضت libphonenumber صيغة wa_id — رقم اختبار من Meta، أو
 * مدى أرقام أحدث من بيانات المكتبة. فتُنشأ جهة اتصال بلا رقم: تستقبل ولا
 * يصلها ردّ. والفهرس الفريد لا يعدّ NULL تكراراً، فكان كل المرفوضين في
 * المنشأة يجتمعون في جهة اتصال واحدة.
 */
class ContactWithoutPhoneTest extends TestCase
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

    // ------------------------------------------- تحويل wa_id إلى رقم

    /**
     * الحالة التي كانت تُنتج NULL: رقم اختبار Meta ترفضه libphonenumber.
     */
    public function test_a_wa_id_rejected_by_libphonenumber_still_produces_a_number(): void
    {
        $this->assertNull(
            PhoneService::getE164Format('+15550001234'),
            'المكتبة ترفض هذا الرقم — وهذا هو منشأ العطل'
        );

        $this->assertSame('+15550001234', PhoneService::fromWhatsappId('15550001234'));
    }

    /** ورقم قصير أو غير مخصّص كذلك: يبقى كما سلّمه واتساب. */
    public function test_other_rejected_shapes_keep_their_digits(): void
    {
        $this->assertSame('+9665024', PhoneService::fromWhatsappId('9665024'));
        $this->assertSame('+999999999999', PhoneService::fromWhatsappId('999999999999'));
    }

    /** الرقم الصالح لا يتغيّر: لا صيغة ثانية تنشأ له فتتكرّر جهة اتصاله. */
    public function test_a_valid_wa_id_keeps_the_exact_e164_form(): void
    {
        foreach (['966502486051', '201234567890', '8613800138000', '5511987654321'] as $waId) {
            $this->assertSame(
                PhoneService::getE164Format('+' . $waId),
                PhoneService::fromWhatsappId($waId),
                'الصيغة يجب أن تطابق ما كان يُحفظ قبل الإصلاح: ' . $waId
            );
        }
    }

    /** الفواصل والمسافات والبادئة تُهمَل. */
    public function test_separators_are_ignored(): void
    {
        $this->assertSame('+966502486051', PhoneService::fromWhatsappId('+966 50-248 6051'));
    }

    /** ولا رقم فيه أصلاً = لا شيء نحفظه. */
    public function test_text_without_a_single_digit_has_no_number(): void
    {
        $this->assertNull(PhoneService::fromWhatsappId('abc'));
        $this->assertNull(PhoneService::fromWhatsappId('   '));
        $this->assertNull(PhoneService::fromWhatsappId(null));
    }

    // ------------------------------------------- webhook الوارد

    /** @return array{0: Contact, 1: bool}|null */
    private function resolveContact(string $from): ?array
    {
        $job = new ProcessIncomingMessageJob(
            ['id' => 'wamid.' . Str::random(16), 'type' => 'text', 'from' => $from],
            ['profile' => ['name' => 'عميل']],
            $this->organization->id
        );

        $method = new ReflectionMethod(ProcessIncomingMessageJob::class, 'getOrCreateContact');
        $method->setAccessible(true);

        return $method->invoke($job);
    }

    /** العطل نفسه: مُرسِل ترفضه المكتبة كان يُنشئ جهة اتصال بلا رقم. */
    public function test_a_rejected_sender_produces_a_contact_that_has_a_phone(): void
    {
        [$contact] = $this->resolveContact('15550001234');

        $this->assertNotNull($contact->phone, 'جهة الاتصال بلا رقم لا يمكن الردّ عليها');
        $this->assertSame('+15550001234', $contact->phone);
    }

    /**
     * والأثر الأوسع: كل المرفوضين كانوا يقعون في جهة اتصال واحدة.
     *
     * لأن firstOrCreate بـ phone = null تبحث بـ `phone is null`، فتجد أوّل
     * جهة اتصال بلا رقم وتُعيدها — فتختلط محادثات عملاء مختلفين في خيط واحد.
     */
    public function test_two_rejected_senders_do_not_collapse_into_one_contact(): void
    {
        [$first] = $this->resolveContact('15550001234');
        [$second] = $this->resolveContact('15550009876');

        $this->assertNotSame($first->id, $second->id, 'كل مُرسِل جهة اتصال مستقلّة');
        $this->assertSame('+15550001234', $first->phone);
        $this->assertSame('+15550009876', $second->phone);
    }

    /** والرقم الصالح يسلك كما كان تماماً: جهة اتصال واحدة لا تتكرّر. */
    public function test_a_valid_sender_behaves_exactly_as_before(): void
    {
        [$first, $isNew] = $this->resolveContact('966502486051');
        [$second] = $this->resolveContact('966502486051');

        $this->assertTrue($isNew);
        $this->assertSame('+966502486051', $first->phone);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Contact::where('organization_id', $this->organization->id)->count());
    }

    /** ورسالة بلا مُرسِل ما زالت تنصرف بهدوء. */
    public function test_a_payload_without_a_sender_is_still_skipped(): void
    {
        $job = new ProcessIncomingMessageJob(['id' => 'wamid.x', 'type' => 'text'], [], $this->organization->id);

        $method = new ReflectionMethod(ProcessIncomingMessageJob::class, 'getOrCreateContact');
        $method->setAccessible(true);

        $this->assertNull($method->invoke($job));
        $this->assertSame(0, Contact::where('organization_id', $this->organization->id)->count());
    }

    // ------------------------------------------- حارس الإرسال

    private function contact(?string $phone): Contact
    {
        return Contact::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'first_name' => 'عميل',
            'phone' => $phone,
            'created_by' => $this->owner->id,
        ]);
    }

    public function test_a_contact_without_a_usable_number_is_not_sendable(): void
    {
        $this->assertFalse(WhatsappService::isSendableNumber(null));
        $this->assertFalse(WhatsappService::isSendableNumber(new Contact(['phone' => null])));
        $this->assertFalse(WhatsappService::isSendableNumber(new Contact(['phone' => ''])));
        $this->assertFalse(WhatsappService::isSendableNumber(new Contact(['phone' => '   '])));
        $this->assertTrue(WhatsappService::isSendableNumber(new Contact(['phone' => '+966502486051'])));
    }

    private function service(): WhatsappService
    {
        return new WhatsappService('token', 'v21.0', 'app', 'phone-id', 'waba', $this->organization->id);
    }

    /**
     * الرسالة النصّية تُردّ قبل الشبكة.
     *
     * لو عبر الحارس لخرج طلب إلى graph.facebook.com — وهذا ما يجعل هذا
     * الاختبار يفشل عند إرجاع الإصلاح.
     */
    public function test_a_text_message_to_a_contact_without_a_phone_never_reaches_meta(): void
    {
        $contact = $this->contact(null);

        Log::spy();

        $response = $this->service()->executeSendMessage($contact->uuid, 'مرحبا');

        $this->assertFalse($response->success);
        $this->assertSame(__('This contact has no phone number.'), $response->message ?? null);
        $this->assertSame(0, Chat::where('organization_id', $this->organization->id)->count());

        Log::shouldHaveReceived('error')
            ->withArgs(fn ($message) => str_contains((string) $message, 'no phone number'))
            ->atLeast()->once();
    }

    /** والوسائط كذلك — ولا تُعاد المحاولة، فالرقم ناقص لا الشبكة. */
    public function test_a_media_job_for_a_contact_without_a_phone_stops_without_throwing(): void
    {
        Storage::fake('local');
        Setting::create(['key' => 'storage_system', 'value' => 'local']);

        $contact = $this->contact(null);
        Storage::disk('local')->put('tmp/IMG_8494.png', 'fake-image-bytes');

        Log::spy();

        $job = new SendMediaJob(
            $this->organization->id,
            $contact->uuid,
            'image',
            'IMG_8494.png',
            'tmp/IMG_8494.png',
            $this->owner->id,
            null,
            null
        );

        $job->handle();

        $this->assertSame(0, Chat::where('organization_id', $this->organization->id)->count());
        Storage::disk('local')->assertMissing('tmp/IMG_8494.png');

        Log::shouldHaveReceived('error')
            ->withArgs(fn ($message) => str_contains((string) $message, 'no phone number'))
            ->atLeast()->once();
    }

    // ------------------------------------------- حراسة على المصدر

    /** المسارات الثلاثة التي تُنشئ جهات اتصال من واتساب تستعمل المُحوّل. */
    public function test_every_inbound_path_converts_the_wa_id_instead_of_dropping_it(): void
    {
        foreach ([
            'app/Jobs/ProcessIncomingMessageJob.php',
            'app/Jobs/ProcessContactSyncJob.php',
            'app/Jobs/ProcessMessageEchoJob.php',
        ] as $path) {
            $source = file_get_contents(base_path($path));

            $this->assertStringContainsString('PhoneService::fromWhatsappId(', $source, $path);
            $this->assertStringNotContainsString(
                "PhoneService::getE164Format('+' . ltrim(",
                $source,
                $path . ': الصيغة القديمة تُرجع null فتُحفظ جهة اتصال بلا رقم'
            );
        }
    }
}
