<?php

namespace Tests\Unit\Email;

use App\Services\Email\SafeEsmtpTransport;
use App\Services\Email\SmtpFailure;
use App\Services\Email\SmtpHostGuard;
use App\Services\Email\SmtpHostNotAllowed;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

/**
 * قواعد الأمان في ربط SMTP التاجر: العناوين، الأوامر قبل التشفير والدخول،
 * وتصنيف الرفض.
 */
class SmtpSafetyTest extends TestCase
{
    public function test_literal_internal_addresses_are_refused(): void
    {
        $guard = new SmtpHostGuard(fn () => []);

        foreach (['127.0.0.1', '10.1.2.3', '172.16.0.9', '192.168.0.1', '169.254.169.254', '100.64.0.1', '0.0.0.0', '::1', 'fc00::1', '::ffff:127.0.0.1'] as $ip) {
            try {
                $guard->resolvePublicAddress($ip);
                $this->fail("{$ip} was allowed");
            } catch (SmtpHostNotAllowed) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /** اسمٌ يُحَلّ إلى عنوان عامّ وآخر داخليّ يُرفض كلّه (rebinding). */
    public function test_a_host_resolving_to_any_internal_address_is_refused(): void
    {
        $guard = new SmtpHostGuard(fn () => ['52.96.1.1', '10.0.0.5']);

        $this->expectException(SmtpHostNotAllowed::class);
        $guard->resolvePublicAddress('smtp.mixed.example');
    }

    public function test_a_public_host_resolves_to_its_vetted_address(): void
    {
        $this->assertSame('52.96.1.1', (new SmtpHostGuard(fn () => ['52.96.1.1']))->resolvePublicAddress('smtp.office365.com'));
        $this->assertSame('[2603:1036::1]', (new SmtpHostGuard(fn () => ['2603:1036::1']))->resolvePublicAddress('smtp6.example'));
    }

    public function test_an_unresolvable_host_is_refused(): void
    {
        $this->expectException(SmtpHostNotAllowed::class);
        (new SmtpHostGuard(fn () => []))->resolvePublicAddress('no-such-host.invalid');
    }

    public function test_the_local_development_escape_hatch(): void
    {
        config(['messaging.email.allow_private_hosts' => true]);

        $this->assertSame('127.0.0.1', (new SmtpHostGuard(fn () => ['127.0.0.1']))->resolvePublicAddress('mailpit'));
    }

    public function test_only_submission_ports_are_allowed(): void
    {
        foreach ([465, 587, 2525] as $port) {
            $this->assertTrue(SmtpHostGuard::isAllowedPort($port));
        }
        foreach ([25, 80, 3306, 6379, 0] as $port) {
            $this->assertFalse(SmtpHostGuard::isAllowedPort($port));
        }
    }

    /** لا كلمة مرور قبل التشفير، ولا إرسال بلا دخول مقبول. */
    public function test_commands_are_refused_before_encryption_and_login(): void
    {
        $this->assertNotNull(SafeEsmtpTransport::refusal("AUTH PLAIN abc\r\n", false, false));
        $this->assertNull(SafeEsmtpTransport::refusal("AUTH PLAIN abc\r\n", true, false));

        $this->assertNotNull(SafeEsmtpTransport::refusal("MAIL FROM:<a@b.c>\r\n", false, true));
        $this->assertNotNull(SafeEsmtpTransport::refusal("MAIL FROM:<a@b.c>\r\n", true, false));
        $this->assertNull(SafeEsmtpTransport::refusal("MAIL FROM:<a@b.c>\r\n", true, true));

        $this->assertNull(SafeEsmtpTransport::refusal("EHLO mnjz.app\r\n", false, false));
        $this->assertNull(SafeEsmtpTransport::refusal("STARTTLS\r\n", false, false));
        $this->assertNull(SafeEsmtpTransport::refusal("RCPT TO:<a@b.c>\r\n", true, true));
    }

    public function test_failures_are_classified_for_retry(): void
    {
        $this->assertSame(SmtpFailure::AUTH_FAILED, SmtpFailure::classify(new TransportException('535 5.7.8 Username and Password not accepted', 535))['code']);
        $this->assertSame(SmtpFailure::REJECTED, SmtpFailure::classify(new TransportException('550 5.1.1 The email account that you tried to reach does not exist', 550))['code']);
        $this->assertTrue(SmtpFailure::classify(new TransportException('550 5.1.1 user unknown', 550))['permanent']);

        $quota = SmtpFailure::classify(new TransportException('452 4.5.3 Too many recipients', 452));
        $this->assertSame(SmtpFailure::FAILED, $quota['code']);
        $this->assertFalse($quota['permanent']);

        $timeout = SmtpFailure::classify(new TransportException('Connection to "1.2.3.4:587" timed out.', 0));
        $this->assertSame(SmtpFailure::FAILED, $timeout['code']);
    }

    public function test_the_reason_is_one_short_line(): void
    {
        $reason = SmtpFailure::reason("550 rejected\nAUTH PLAIN c2VjcmV0\n" . str_repeat('x', 500));

        $this->assertSame('550 rejected', $reason);
        $this->assertLessThanOrEqual(200, mb_strlen(SmtpFailure::reason(str_repeat('y', 500))));
    }
}
