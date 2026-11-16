<?php

namespace Tests\Feature\Campaigns;

use App\Http\Controllers\EmailUnsubscribeController;
use App\Jobs\ProcessCampaignMessagesJob;
use App\Jobs\ProcessSingleCampaignLogJob;
use App\Jobs\RetryCampaignLogJob;
use App\Models\Campaign;
use App\Models\CampaignLog;
use App\Models\ChannelAccount;
use App\Models\Chat;
use App\Models\Contact;
use App\Models\EmailUnsubscribe;
use App\Models\User;
use App\Services\CampaignAudienceService;
use App\Services\CampaignResendService;
use App\Services\Email\MerchantSmtpTransportFactory;
use App\Services\Messaging\Channel;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeSmtpTransportFactory;

/**
 * حملات البريد عبر SMTP التاجر، من النموذج إلى الخادم.
 *
 * الشخص عنوانه: رسالة واحدة لكل عنوان مهما تعدّدت صفوفه. إلغاء الاشتراك
 * من البريد لا يُخرجه من حملات واتساب. الرسالة نصّ مهرَّب بنسخة HTML ونسخة
 * نصّية، ورابط الإلغاء في التذييل والترويسة. ولا صفّ في chats.
 */
class EmailCampaignTest extends CampaignTestCase
{
    private FakeSmtpTransportFactory $smtp;
    private ChannelAccount $mailbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->smtp = new FakeSmtpTransportFactory();
        $this->app->instance(MerchantSmtpTransportFactory::class, $this->smtp);

        $this->mailbox = ChannelAccount::create([
            'organization_id' => $this->organization->id,
            'channel' => Channel::EMAIL,
            'external_id' => 'org:' . $this->organization->id,
            'name' => 'متجر المثال',
            'username' => 'sales@shop.example',
            'credentials' => ['host' => 'smtp.example.com', 'port' => 587, 'encryption' => 'tls', 'username' => 'sales@shop.example', 'password' => 'pw'],
            'settings' => ['reply_to' => 'support@shop.example'],
            'status' => ChannelAccount::STATUS_ACTIVE,
            'created_by' => $this->owner->id,
        ]);
    }

    private function emailForm(array $overrides = []): array
    {
        return array_merge([
            'name' => 'نشرة العيد',
            'channel' => 'email',
            'subject' => 'عرض العيد يا {first_name}',
            'message_text' => "أهلاً {first_name}\nخصم 20% اليوم.",
            'contacts' => 'all',
            'skip_schedule' => true,
        ], $overrides);
    }

    private function sendAll(int $ticks = 3): void
    {
        $this->schedulerMinute();
        for ($i = 0; $i < $ticks; $i++) {
            $this->dispatcherTick();
        }
    }

    /**
     * إنشاء السجلّات وحده: المُجدول يطلق دورة إرسال فوراً (والطابور هنا
     * متزامن)، والاختبار يريد أن يقرّر ما قبل كل دورة.
     */
    private function createLogsWithoutSending(): void
    {
        Bus::fake([ProcessCampaignMessagesJob::class, RetryCampaignLogJob::class]);
        $this->schedulerMinute();
    }

    /** @return list<string> */
    private function recipients(): array
    {
        return array_map(fn ($email) => $email->getTo()[0]->getAddress(), $this->smtp->sent());
    }

    public function test_an_email_campaign_reaches_each_address_once(): void
    {
        $first = $this->customer('+966500000002', 'نورة', ['email' => 'a@x.com']);
        // الشخص نفسه على تيليجرام بالعنوان نفسه (حالة ومسافة مختلفتان).
        $this->customer('', 'نورة', ['email' => ' A@x.com', 'channel' => 'telegram', 'channel_identifier' => '11', 'phone' => null]);
        $this->customer('+966500000003', 'سالم', ['email' => 'b@x.com']);
        $this->customer('+966500000004', 'بلا بريد');

        $this->createFromWeb($this->emailForm())->assertRedirect();
        $this->sendAll();

        $this->assertEqualsCanonicalizing(['a@x.com', 'b@x.com'], $this->recipients());

        $logs = CampaignLog::where('campaign_id', Campaign::latest('id')->first()->id)->get();
        $this->assertCount(2, $logs);
        // أقدم صفّ للعنوان هو الممثّل، ومنه المتغيّرات.
        $this->assertTrue($logs->pluck('contact_id')->contains($first->id));
        $this->assertSame(['success', 'success'], $logs->pluck('status')->all());
    }

    /** عنوانٌ كتبه زائر مجهول في المحادثة المباشرة لا يُرسَل إليه تسويق. */
    public function test_web_chat_typed_addresses_are_not_emailed(): void
    {
        $this->customer('', 'زائر', ['email' => 'visitor@x.com', 'channel' => 'webchat', 'channel_identifier' => 'v1', 'phone' => null]);
        $this->customer('+966500000002', 'نورة', ['email' => 'a@x.com']);

        $this->createFromWeb($this->emailForm())->assertRedirect();
        $this->sendAll();

        $this->assertSame(['a@x.com'], $this->recipients());
    }

    public function test_unsubscribing_from_email_keeps_the_person_in_whatsapp_campaigns(): void
    {
        $person = $this->customer('+966500000002', 'نورة', ['email' => 'a@x.com']);
        EmailUnsubscribe::record($this->organization->id, 'A@X.com');

        $this->assertFalse(CampaignAudienceService::query($this->organization->id, 0, Channel::EMAIL)->exists());
        $this->assertTrue(CampaignAudienceService::query($this->organization->id, 0, Channel::WHATSAPP)->whereKey($person->id)->exists());
        $this->assertNull($person->fresh()->marketing_opted_out_at);
    }

    public function test_the_message_has_subject_placeholders_html_text_and_unsubscribe_headers(): void
    {
        $this->customer('+966500000002', 'نورة', ['email' => 'a@x.com']);

        $this->createFromWeb($this->emailForm([
            'message_text' => "أهلاً {first_name}\n<script>alert(1)</script>",
        ]))->assertRedirect();
        $this->sendAll();

        $email = $this->smtp->sent()[0];

        $this->assertSame('عرض العيد يا نورة', $email->getSubject());
        $this->assertSame('sales@shop.example', $email->getFrom()[0]->getAddress());
        $this->assertSame('متجر المثال', $email->getFrom()[0]->getName());
        $this->assertSame('support@shop.example', $email->getReplyTo()[0]->getAddress());

        $html = (string) $email->getHtmlBody();
        $this->assertStringContainsString('أهلاً نورة<br>', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('أهلاً نورة', (string) $email->getTextBody());

        $headers = $email->getHeaders();
        $this->assertTrue($headers->has('List-Unsubscribe'));
        $this->assertSame('List-Unsubscribe=One-Click', $headers->get('List-Unsubscribe-Post')->getBodyAsString());
        $this->assertStringContainsString('/email/unsubscribe/', $headers->get('List-Unsubscribe')->getBodyAsString());
    }

    /** رابطٌ في اسم جهة اتصال يبقى نصّاً — لا رابطاً قابلاً للنقر من دومين التاجر. */
    public function test_a_url_in_a_contact_name_is_not_linked(): void
    {
        $this->customer('+966500000002', 'http://phish.example/login', ['email' => 'a@x.com']);

        $this->createFromWeb($this->emailForm(['message_text' => 'مرحباً {first_name}']))->assertRedirect();
        $this->sendAll();

        $html = (string) $this->smtp->sent()[0]->getHtmlBody();
        $this->assertStringContainsString('http://phish.example/login', $html);
        $this->assertStringNotContainsString('href="http://phish.example', $html);
    }

    public function test_a_header_injection_in_a_contact_name_stays_on_one_subject_line(): void
    {
        $this->customer('+966500000002', "نورة\r\nBcc: victim@evil.example", ['email' => 'a@x.com']);

        $this->createFromWeb($this->emailForm())->assertRedirect();
        $this->sendAll();

        $email = $this->smtp->sent()[0];
        $this->assertStringNotContainsString("\n", $email->getSubject());
        $this->assertSame([], $email->getBcc());
    }

    public function test_the_signed_unsubscribe_link_confirms_then_records(): void
    {
        $url = EmailUnsubscribeController::urlFor($this->organization, 'A@x.com');

        // GET لا يغيّر شيئاً: فاحصات الروابط تفتحه.
        $this->get($url)->assertOk();
        $this->assertFalse(EmailUnsubscribe::isUnsubscribed($this->organization->id, 'a@x.com'));

        $this->post($url)->assertOk();
        $this->assertTrue(EmailUnsubscribe::isUnsubscribed($this->organization->id, 'a@x.com'));
    }

    public function test_one_click_unsubscribe_works_without_session_or_csrf(): void
    {
        $url = EmailUnsubscribeController::urlFor($this->organization, 'a@x.com');

        $this->call('POST', $url, ['List-Unsubscribe' => 'One-Click'])->assertOk();

        $this->assertTrue(EmailUnsubscribe::isUnsubscribed($this->organization->id, 'a@x.com'));
    }

    /** مزوّد البريد يرسل من عناوين قليلة: الإلغاءات الصحيحة لا تُحدّ. */
    public function test_many_one_click_unsubscribes_from_one_ip_are_all_recorded(): void
    {
        for ($i = 1; $i <= 50; $i++) {
            $this->call('POST', EmailUnsubscribeController::urlFor($this->organization, "p{$i}@x.com"), ['List-Unsubscribe' => 'One-Click'], [], [], ['REMOTE_ADDR' => '209.85.220.41'])
                ->assertOk();
        }

        $this->assertSame(50, EmailUnsubscribe::where('organization_id', $this->organization->id)->count());
    }

    public function test_a_tampered_unsubscribe_link_changes_nothing(): void
    {
        $url = EmailUnsubscribeController::urlFor($this->organization, 'a@x.com');
        $tampered = str_replace('a%40x.com', 'b%40x.com', $url);

        $this->post($tampered)->assertForbidden();

        $this->assertSame(0, EmailUnsubscribe::count());
    }

    /** رابط منشأة أخرى لا يمسّ هذه، ولو كان العنوان نفسه. */
    public function test_an_unsubscribe_applies_to_its_own_organization_only(): void
    {
        $other = $this->makeOrganization(User::factory()->create(['role' => 'user']));

        $this->post(EmailUnsubscribeController::urlFor($other, 'a@x.com'))->assertOk();

        $this->assertTrue(EmailUnsubscribe::isUnsubscribed($other->id, 'a@x.com'));
        $this->assertFalse(EmailUnsubscribe::isUnsubscribed($this->organization->id, 'a@x.com'));
    }

    public function test_an_unsubscribe_mid_campaign_stops_the_remaining_sends(): void
    {
        $this->customer('+966500000002', 'نورة', ['email' => 'a@x.com']);
        $this->customer('+966500000003', 'سالم', ['email' => 'b@x.com']);
        config(['campaigns.channel_batch_sizes.email' => 1]);

        $this->createFromWeb($this->emailForm())->assertRedirect();
        $this->createLogsWithoutSending();
        $this->dispatcherTick();

        $sentFirst = $this->recipients()[0];
        $other = $sentFirst === 'a@x.com' ? 'b@x.com' : 'a@x.com';
        EmailUnsubscribe::record($this->organization->id, $other);

        $this->dispatcherTick();

        $this->assertSame([$sentFirst], $this->recipients());
        $log = CampaignLog::whereHas('contact', fn ($q) => $q->where('email', $other))->first();
        $this->assertSame('failed', $log->status);
        $this->assertStringContainsString('"code":"opted_out"', (string) $log->getRawOriginal('metadata'));
    }

    public function test_stats_count_sent_and_failed_without_any_chat_rows(): void
    {
        $this->customer('+966500000002', 'نورة', ['email' => 'a@x.com']);
        $this->customer('+966500000003', 'سالم', ['email' => 'b@x.com']);
        $this->smtp->sendOutcomes = [FakeSmtpTransportFactory::rejection('550 5.1.1 user unknown', 550)];
        $chatsBefore = Chat::count();

        $this->createFromWeb($this->emailForm())->assertRedirect();
        $this->sendAll();

        $campaign = Campaign::latest('id')->first();
        $counts = $campaign->getCounts();

        $this->assertSame($chatsBefore, Chat::count());
        $this->assertSame(2, (int) $counts->total_message_count);
        $this->assertSame(1, (int) $counts->total_sent_count);
        $this->assertSame(1, (int) $counts->total_failed_count);
        $this->assertSame(0, (int) $counts->total_delivered_count);
        $this->assertSame('completed', $campaign->fresh()->status);

        $this->actingAs($this->owner, 'user')
            ->withSession(['current_organization' => $this->organization->id])
            ->get('/campaigns/' . $campaign->uuid)
            ->assertOk();
    }

    public function test_a_temporary_failure_is_retried_and_a_bad_address_is_not(): void
    {
        Bus::fake([RetryCampaignLogJob::class]);
        $this->setRetrySettings(true, [1]);
        $this->customer('+966500000002', 'نورة', ['email' => 'temp@x.com']);
        $this->customer('+966500000003', 'سالم', ['email' => 'gone@x.com']);

        $this->createFromWeb($this->emailForm())->assertRedirect();
        $this->createLogsWithoutSending();

        // الترتيب بحسب السجلّ: نقرّر نتيجة كلٍّ بعنوانه.
        $this->smtp->sendOutcomes = [];
        $logs = CampaignLog::with('contact')->orderBy('id')->get();
        foreach ($logs as $log) {
            $this->smtp->sendOutcomes[] = $log->contact->email === 'temp@x.com'
                ? FakeSmtpTransportFactory::rejection('421 4.7.0 Try again later', 421)
                : FakeSmtpTransportFactory::rejection('550 5.1.1 The email account does not exist', 550);
        }

        $this->dispatcherTick();

        Bus::assertDispatchedTimes(RetryCampaignLogJob::class, 1);
        $campaign = Campaign::latest('id')->first();
        $this->assertSame(1, app(CampaignResendService::class)->resendableLogIds($campaign->id)->count());
    }

    public function test_a_rejected_login_pauses_the_account_instead_of_retrying_every_recipient(): void
    {
        $this->customer('+966500000002', 'نورة', ['email' => 'a@x.com']);
        $this->customer('+966500000003', 'سالم', ['email' => 'b@x.com']);
        $this->smtp->sendOutcomes = [FakeSmtpTransportFactory::rejection('535 5.7.8 Username and Password not accepted', 535)];

        $this->createFromWeb($this->emailForm())->assertRedirect();
        $this->sendAll();

        $this->assertSame(ChannelAccount::STATUS_REAUTHORIZATION_REQUIRED, $this->mailbox->fresh()->status);
        // الثاني فشل بلا اتصال بالخادم.
        $this->assertCount(1, $this->smtp->made);
        $this->assertSame([], $this->recipients());
    }

    public function test_email_failures_never_move_contacts_to_the_failed_group(): void
    {
        $person = $this->customer('+966500000002', 'نورة', ['email' => 'a@x.com']);
        $vip = $this->group([$person], 'كبار العملاء');
        $failed = $this->group([], 'فاشلون');
        $this->setRetrySettings(true, [1], (string) $failed->uuid);
        $this->smtp->sendOutcomes = [FakeSmtpTransportFactory::rejection('550 5.1.1 user unknown', 550)];

        $this->createFromWeb($this->emailForm())->assertRedirect();
        $this->sendAll();

        $log = CampaignLog::first();
        app(\App\Services\CampaignRetryService::class)->moveContactToFailedGroup($log);

        $this->assertTrue($person->fresh()->contactGroups->pluck('id')->contains($vip->id));
        $this->assertFalse($person->fresh()->contactGroups->pluck('id')->contains($failed->id));
    }

    /** البريد على طابور يأتي بعد الجميع، وبدفعته الأصغر. */
    public function test_email_gets_its_own_smaller_batch_on_the_low_queue(): void
    {
        foreach (range(1, 5) as $i) {
            $this->customer('+96650000001' . $i, 'ع' . $i, ['email' => "p{$i}@x.com"]);
        }

        $this->createFromWeb($this->emailForm())->assertRedirect();
        $this->schedulerMinute();

        Queue::fake();
        (new ProcessCampaignMessagesJob())->handle();

        Queue::assertPushedOn('low', ProcessSingleCampaignLogJob::class);
        Queue::assertPushed(ProcessSingleCampaignLogJob::class, 2);
    }

    /** سجلٌّ علق: ربما أُرسل — لا يُعاد تلقائياً. */
    public function test_a_stuck_email_log_is_not_resent_automatically(): void
    {
        Bus::fake([RetryCampaignLogJob::class]);
        $this->setRetrySettings(true, [1]);
        $this->customer('+966500000002', 'نورة', ['email' => 'a@x.com']);

        $this->createFromWeb($this->emailForm())->assertRedirect();
        $this->createLogsWithoutSending();

        $log = CampaignLog::first();
        CampaignLog::whereKey($log->id)->update(['status' => 'ongoing', 'updated_at' => now()->subMinutes(30)]);
        \Illuminate\Support\Facades\Cache::forget('campaigns:stuck_check');
        \Illuminate\Support\Facades\Cache::flush();

        (new ProcessCampaignMessagesJob())->handle();

        $this->assertSame('failed', $log->fresh()->status);
        $this->assertStringContainsString('email_unknown', (string) $log->fresh()->getRawOriginal('metadata'));
        Bus::assertNotDispatched(RetryCampaignLogJob::class);
        $this->assertSame([], $this->recipients());
    }

    public function test_an_email_campaign_needs_a_connected_account(): void
    {
        $this->mailbox->delete();
        $this->customer('+966500000002', 'نورة', ['email' => 'a@x.com']);

        $this->createFromWeb($this->emailForm())->assertStatus(422)->assertJsonValidationErrors(['channel']);
    }

    public function test_an_email_campaign_refuses_attachments_and_needs_a_subject(): void
    {
        $this->customer('+966500000002', 'نورة', ['email' => 'a@x.com']);

        $this->createFromWeb($this->emailForm(['subject' => '']))->assertStatus(422)->assertJsonValidationErrors(['subject']);
        $this->createFromWeb($this->emailForm([
            'message_media' => \Illuminate\Http\UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'),
        ]))->assertStatus(422)->assertJsonValidationErrors(['message_media']);
    }

    public function test_an_email_campaign_without_addresses_is_refused(): void
    {
        $this->customer('+966500000002', 'بلا بريد');

        $this->createFromWeb($this->emailForm())->assertStatus(422)->assertJsonValidationErrors(['contacts']);
    }

    public function test_the_export_lists_the_email_address(): void
    {
        $this->customer('+966500000002', 'نورة', ['email' => 'a@x.com']);
        $this->createFromWeb($this->emailForm())->assertRedirect();
        $this->sendAll();

        $response = $this->actingAs($this->owner, 'user')
            ->withSession(['current_organization' => $this->organization->id])
            ->get('/campaigns/export/' . Campaign::latest('id')->first()->uuid)
            ->assertOk();

        $csv = file_get_contents($response->baseResponse->getFile()->getPathname());
        $this->assertStringContainsString('Email', $csv);
        $this->assertStringContainsString('a@x.com', $csv);
    }
}
