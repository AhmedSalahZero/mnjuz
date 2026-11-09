<?php

namespace Tests\Feature\FlowBuilder;

use App\Models\Contact;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Modules\FlowBuilder\Services\ActionExecutionService;
use Symfony\Component\Mailer\Transport\NullTransport;

/**
 * ما يفعله كل إجراء بالبيانات فعلاً: أيّ SMTP يُرسل عبره، وأيّ عنوان يُستدعى.
 */
class FlowActionDataTest extends FlowBuilderTestCase
{
    private function emailConfig(string $host, string $user, string $password): array
    {
        return [
            'subject' => 'مرحبا',
            'body' => 'نص الرسالة',
            'smtp_host' => $host,
            'smtp_port' => 587,
            'smtp_username' => $user,
            'smtp_password' => $password,
            'smtp_encryption' => 'tls',
        ];
    }

    // ------------------------------------------------- إرسال بريد

    /**
     * Laravel يحتفظ بالـ mailer بعد أوّل استخدام، فتعديل config('mail.mailers.smtp')
     * بعده بلا أثر: كان بريد المنشأة الثانية يخرج عبر SMTP الأولى في العامل نفسه.
     */
    public function test_each_organization_sends_through_its_own_smtp_account(): void
    {
        $transports = [];
        Mail::extend('smtp', function (array $config) use (&$transports) {
            $transports[] = $config;

            return new NullTransport();
        });

        $platformHost = config('mail.mailers.smtp.host');

        $this->contact->update(['email' => 'a@client.test']);
        (new ActionExecutionService($this->organization->id))
            ->executeAction('send_email', $this->emailConfig('smtp.org-a.test', 'a@org-a.test', 'pass-a'), $this->contact);

        $stranger = User::factory()->create(['role' => 'user']);
        $other = $this->makeOrganization($stranger);
        $otherContact = Contact::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $other->id,
            'first_name' => 'B',
            'phone' => '+966500000002',
            'email' => 'b@client.test',
            'created_by' => $stranger->id,
        ]);
        (new ActionExecutionService($other->id))
            ->executeAction('send_email', $this->emailConfig('smtp.org-b.test', 'b@org-b.test', 'pass-b'), $otherContact);

        $this->assertCount(2, $transports);
        $this->assertSame(['smtp.org-a.test', 'a@org-a.test', 'pass-a'], [$transports[0]['host'], $transports[0]['username'], $transports[0]['password']]);
        $this->assertSame(['smtp.org-b.test', 'b@org-b.test', 'pass-b'], [$transports[1]['host'], $transports[1]['username'], $transports[1]['password']]);
        // بريد المنصّة نفسه لا يرث إعدادات أيّ منشأة.
        $this->assertSame($platformHost, config('mail.mailers.smtp.host'));
    }

    // ------------------------------------------------- تحديث جهة الاتصال

    /** الرسالة كانت تُصغَّر قبل الإجراءات: «John Smith» تُحفظ «john smith». */
    public function test_update_contact_keeps_what_the_customer_typed(): void
    {
        $this->makeFlow([
            $this->startNode('keywords', 'start'),
            $this->textNode('2', 'ما اسمك؟'),
            $this->actionNode('3', 'update_contact', ['target_field' => 'first_name']),
            $this->textNode('4', 'شكراً'),
        ], [['1', '2'], ['2', '3'], ['3', '4']]);

        $this->receive('start');
        $this->receive('John Smith');

        $this->assertSame('John Smith', $this->contact->fresh()->first_name);
        $this->assertSame(['ما اسمك؟', 'شكراً'], $this->sentBodies());
    }

    public function test_the_webhook_receives_the_original_text(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $this->makeFlow([
            $this->startNode('keywords', 'start'),
            $this->textNode('2', 'رقم الطلب؟'),
            $this->actionNode('3', 'webhook', ['url' => 'https://93.184.216.34/hook', 'method' => 'POST']),
        ], [['1', '2'], ['2', '3']]);

        $this->receive('start');
        $this->receive('AB12CD');

        Http::assertSent(fn ($request) => $request['message'] === 'AB12CD');
    }

    /** المحرّر كان يحفظ «metadata.City» فيُخزَّن تحت هذا المفتاح حرفياً. */
    public function test_a_custom_field_is_stored_under_its_own_name(): void
    {
        (new ActionExecutionService($this->organization->id))
            ->executeAction('update_contact', ['target_field' => 'metadata.المدينة'], $this->contact, 'جدة');
        (new ActionExecutionService($this->organization->id))
            ->executeAction('update_contact', ['target_field' => 'Branch'], $this->contact->fresh(), 'North');

        $metadata = json_decode($this->contact->fresh()->metadata, true);
        $this->assertSame(['المدينة' => 'جدة', 'Branch' => 'North'], $metadata);
    }

    /** «اتركه فارغاً للرسالة الافتراضية»: الفارغ كان لا يُرسل شيئاً. */
    public function test_an_empty_invalid_email_message_falls_back_to_the_default(): void
    {
        $result = (new ActionExecutionService($this->organization->id))->executeAction(
            'update_contact',
            ['target_field' => 'email', 'invalid_email_message' => ''],
            $this->contact,
            'not-an-email'
        );

        $this->assertFalse($result);
        $this->assertSame([__('Please provide a valid email address.')], $this->sentBodies());
        $this->assertNull($this->contact->fresh()->email);
    }

    // ------------------------------------------------- المتغيّرات والحدود

    public function test_placeholders_are_filled_like_canned_replies(): void
    {
        $this->contact->update(['metadata' => json_encode(['المدينة' => 'جدة'])]);
        $this->makeFlow([
            $this->startNode('keywords', 'start'),
            $this->textNode('2', 'رقمك {phone_number} يا {first_name} من {المدينة}{email}'),
        ], [['1', '2']]);

        $this->receive('start');

        $this->assertSame(['رقمك +966500000001 يا Maitha من جدة'], $this->sentBodies());
    }

    public function test_a_media_caption_gets_its_placeholders(): void
    {
        // sendMedia يقرأ حجم الملف بـ file_get_contents ونوعه بـ Http::head:
        // ملفٌّ محلي ونوعٌ مزيّف فلا يخرج الاختبار إلى الشبكة.
        Http::fake(['*' => Http::response('', 200, ['Content-Type' => 'image/jpeg'])]);
        $photo = tempnam(sys_get_temp_dir(), 'flow') . '.jpg';
        file_put_contents($photo, 'jpeg-bytes');

        $this->makeFlow([
            $this->startNode('keywords', 'start'),
            [
                'id' => '2',
                'type' => 'media',
                'position' => ['x' => 0, 'y' => 0],
                'data' => ['metadata' => ['fields' => [
                    'type' => 'media',
                    'mediaType' => 'image',
                    'caption' => 'أهلاً {first_name}',
                    'media' => [
                        'path' => $photo,
                        'location' => 'local',
                        'metadata' => json_encode(['name' => 'photo.jpg']),
                    ],
                ]]],
            ],
        ], [['1', '2']]);

        $this->receive('start');

        $this->assertSame('أهلاً Maitha', $this->sentMessages()[0]['image']['caption']);
        @unlink($photo);
    }

    /** حدّ واتساب لرسالة النصّ 4096؛ القصّ عند 1024 كان يحذف آخرها بصمت. */
    public function test_long_text_is_cut_at_whatsapps_limit_for_its_type(): void
    {
        $this->makeFlow([
            $this->startNode('keywords', 'text'),
            $this->textNode('2', str_repeat('ن', 5000)),
        ], [['1', '2']]);
        $this->makeFlow([
            $this->startNode('keywords', 'buttons'),
            $this->buttonsNode('2', str_repeat('ب', 1500), ['نعم']),
        ], [['1', '2']]);

        $this->receive('text');
        $this->receive('buttons');

        [$text, $interactive] = $this->sentBodies();
        $this->assertSame(4096, mb_strlen($text));
        $this->assertSame(1024, mb_strlen($interactive));
    }

    // ------------------------------------------------- webhook

    /** @return array<string, array{0: string}> */
    public static function internalUrls(): array
    {
        return [
            'loopback' => ['http://127.0.0.1/hook'],
            'cloud metadata' => ['http://169.254.169.254/latest/meta-data/'],
            'private network' => ['http://10.0.0.5:8080/admin'],
            'ipv6 loopback' => ['http://[::1]/hook'],
            'not http' => ['file:///etc/passwd'],
            'no host' => ['https:///path'],
        ];
    }

    /** @dataProvider internalUrls */
    public function test_a_webhook_cannot_reach_internal_addresses(string $url): void
    {
        Http::fake();

        $result = (new ActionExecutionService($this->organization->id))
            ->executeAction('webhook', ['url' => $url, 'method' => 'POST'], $this->contact, 'hi');

        $this->assertFalse($result);
        Http::assertNothingSent();
    }

    public function test_a_webhook_to_a_public_address_is_called(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $result = (new ActionExecutionService($this->organization->id))
            ->executeAction('webhook', ['url' => 'https://93.184.216.34/hook', 'method' => 'POST'], $this->contact, 'hi');

        $this->assertTrue($result);
        Http::assertSent(fn ($request) => $request->url() === 'https://93.184.216.34/hook'
            && $request['contact']['uuid'] === $this->contact->uuid);
    }
}
