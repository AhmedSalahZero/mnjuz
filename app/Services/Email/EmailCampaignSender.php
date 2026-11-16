<?php

namespace App\Services\Email;

use App\Models\Campaign;
use App\Models\ChannelAccount;
use App\Models\Contact;
use App\Models\EmailUnsubscribe;
use App\Services\Messaging\CampaignChannels;
use App\Services\Messaging\ChannelOutboundSender;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * إرسال رسالة حملة بريد عبر SMTP التاجر.
 *
 * يُرجع شكل ردّ WhatsappService نفسه ({success, data->error->code …}) لأن
 * مسار الحملة القائم (الحفظ، إعادة المحاولة، الأسباب) يقرؤه. لا صفّ في
 * chats: البريد ليس محادثة، والنتيجة تُحفظ على campaign_logs.
 */
class EmailCampaignSender
{
    public const NOT_CONNECTED = 'email_not_connected';
    public const NO_ADDRESS = 'no_email_address';
    public const INVALID_ADDRESS = 'invalid_email_address';
    public const OPTED_OUT = 'opted_out';
    public const UNKNOWN = 'email_unknown';

    /** فشلٌ لا تُفيد إعادته: لا إعادة محاولة ولا إعادة إرسال. */
    public const PERMANENT_FAILURES = [
        self::NO_ADDRESS,
        self::INVALID_ADDRESS,
        self::OPTED_OUT,
        SmtpFailure::REJECTED,
        self::UNKNOWN,
    ];

    public function __construct(
        private readonly MerchantSmtpTransportFactory $smtp,
        private readonly CampaignEmailBuilder $builder,
    ) {
    }

    public function send(Campaign $campaign, Contact $contact): object
    {
        // الحساب الفعّال الآن لا المحفوظ على الحملة: إعادة ربط البريد لا تُفشل
        // الحملات المجدولة.
        $account = CampaignChannels::emailAccount((int) $campaign->organization_id);

        if (!$account) {
            return ChannelOutboundSender::failure(__('Email is not connected. Connect your email (SMTP) from Settings → Channels.'), self::NOT_CONNECTED);
        }

        $to = trim((string) $contact->email);

        if ($to === '') {
            return ChannelOutboundSender::failure(__('This contact has no email address.'), self::NO_ADDRESS);
        }

        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return ChannelOutboundSender::failure(__('This email address is not valid.'), self::INVALID_ADDRESS);
        }

        // إلغاءٌ أثناء الحملة يوقف ما بقي منها لهذا العنوان.
        if (EmailUnsubscribe::isUnsubscribed((int) $campaign->organization_id, $to)) {
            return ChannelOutboundSender::failure(__('This address unsubscribed from your emails.'), self::OPTED_OUT);
        }

        $transport = null;

        try {
            $transport = $this->smtp->forAccount($account);
            $transport->send($this->builder->build($campaign, $contact, $account, $to));
        } catch (SmtpHostNotAllowed $e) {
            $this->pauseAccount($account, $e->getMessage());

            return ChannelOutboundSender::failure($e->getMessage(), self::NOT_CONNECTED);
        } catch (TransportExceptionInterface $e) {
            $failure = SmtpFailure::classify($e);

            // دخول مرفوض: نوقف الحساب بدل أن نطرق خادم التاجر بكلمة مرور خاطئة
            // لكل مستلم (فيُقفل بريده). السجلّات التالية تفشل فوراً بلا اتصال.
            if ($failure['code'] === SmtpFailure::AUTH_FAILED) {
                $this->pauseAccount($account, $failure['message']);
            }

            Log::info('Email campaign send failed', [
                'campaign_id' => $campaign->id,
                'code' => $failure['code'],
            ]);

            return ChannelOutboundSender::failure($failure['message'], $failure['code']);
        } finally {
            if ($transport !== null && method_exists($transport, 'stop')) {
                try {
                    $transport->stop();
                } catch (\Throwable) {
                    // الإغلاق لا يُفشل ما أُرسل.
                }
            }
        }

        return (object) [
            'success' => true,
            'data' => (object) ['email' => $to],
        ];
    }

    private function pauseAccount(ChannelAccount $account, string $reason): void
    {
        $account->forceFill([
            'status' => ChannelAccount::STATUS_REAUTHORIZATION_REQUIRED,
            'last_error' => mb_substr($reason, 0, 500),
        ])->save();
    }

    /** رمز الفشل المحفوظ في metadata السجلّ، أو null. */
    public static function failureCode(?string $metadata): ?string
    {
        $decoded = json_decode((string) $metadata, true);

        return is_array($decoded) ? ($decoded['data']['error']['code'] ?? null) : null;
    }

    public static function isPermanentFailure(?string $metadata): bool
    {
        return in_array(self::failureCode($metadata), self::PERMANENT_FAILURES, true);
    }
}
