<?php

namespace Tests\Feature;

use App\Helpers\Email;
use App\Jobs\SendUserVerificationEmailJob;
use App\Mail\CustomEmail;
use App\Mail\ResetLinkedDevicesMail;
use App\Models\EmailTemplate;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * سجلّ الإنتاج (2026-11-12):
 *   Expected response code "250" but got code "451",
 *   with message "451 4.4.2 Timeout waiting for data from client."
 *
 * SES يُغلق اتصال SMTP الخامل في الـ worker، فتقرأ الرسالة التالية ردّه
 * المعلّق وتفشل. المُرسِل يتّصل من جديد في المحاولة التالية، فالعلاج إعادة
 * المحاولة؛ وبلا $tries كانت الرسالة تضيع إن كان الـ worker بلا --tries.
 */
class QueuedMailRetryTest extends TestCase
{
    use RefreshDatabase;

    public function test_custom_email_is_retried_with_backoff(): void
    {
        $job = new SendQueuedMailable(new CustomEmail('Subject', 'Body'));

        $this->assertSame(3, $job->tries);
        $this->assertSame([10, 60], $job->backoff());
    }

    public function test_reset_linked_devices_mail_is_retried_with_backoff(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $job = new SendQueuedMailable(new ResetLinkedDevicesMail($user, 'https://example.test/reset', 'https://example.test/logo.png', 'Mnjz'));

        $this->assertSame(3, $job->tries);
        $this->assertSame([10, 60], $job->backoff());
    }

    public function test_the_verification_code_job_is_retried_with_backoff(): void
    {
        $job = new SendUserVerificationEmailJob(1, '123456', 10);

        $this->assertSame(3, $job->tries);
        $this->assertSame([10, 60], $job->backoff);
    }

    /** المسار القائم كما هو: البريد يُوضع في الطابور، لا يُرسَل فوراً. */
    public function test_the_email_helper_still_queues_custom_email(): void
    {
        Mail::fake();

        $user = User::factory()->create(['role' => 'user', 'first_name' => 'Sara']);
        Setting::create(['key' => 'smtp_email_active', 'value' => '1']);
        EmailTemplate::create([
            'name' => 'Registration',
            'subject' => 'Welcome {{FirstName}}',
            'body' => '<p>Hello {{FirstName}}</p>',
            'updated_by' => $user->id,
        ]);

        Email::send('Registration', $user);

        Mail::assertQueued(CustomEmail::class, fn (CustomEmail $mail) => $mail->hasTo($user->email) && $mail->tries === 3);
        Mail::assertNothingSent();
    }
}
