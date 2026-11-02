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
