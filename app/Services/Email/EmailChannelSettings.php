<?php

namespace App\Services\Email;

use App\Models\ChannelAccount;
use App\Services\Messaging\Channel;
use App\Services\Messaging\ChannelSettingsResult;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * بريد التاجر لحملات البريد: قراءة الإعداد وحفظه واختباره.
 *
 * منقول من EmailChannelController كما هو ليمرّ الويب والتطبيق من طريق
 * واحد. التحقّق من الحقول يبقى في StoreEmailChannel؛ هنا ما بعده.
 *
 * لا يُحفظ حساب قبل أن يقبل الخادم تسجيل الدخول — حساب محفوظ ببيانات
 * خاطئة يبدو مربوطاً وتفشل كل حملاته.
 */
class EmailChannelSettings
{
    public function __construct(private readonly MerchantSmtpTransportFactory $smtp)
    {
    }

    public function account(int $organizationId): ?ChannelAccount
    {
        return ChannelAccount::query()
            ->where('organization_id', $organizationId)
            ->where('channel', Channel::EMAIL)
            ->first();
    }

    /**
     * ما تعرضه صفحة البريد. قائمة صريحة: كلمة المرور لا تغادر الخادم.
     *
     * @return array{account: ?array, smtp: ?array, ports: array<int, int>}
     */
    public function view(?ChannelAccount $account): array
    {
        return [
            'account' => $account ? [
                'uuid' => (string) $account->uuid,
                'status' => $account->status,
                'is_active' => $account->isActive(),
                'needs_reauthorization' => $account->needsReauthorization(),
                'last_error' => $account->last_error,
            ] : null,
            'smtp' => $account ? [
                'host' => $account->credential('host'),
                'port' => (int) $account->credential('port'),
                'encryption' => $account->credential('encryption'),
                'username' => $account->credential('username'),
                'has_password' => filled($account->credential('password')),
                'from_address' => $account->username,
                'from_name' => $account->name,
                'reply_to' => $account->setting('reply_to'),
            ] : null,
            'ports' => SmtpHostGuard::ALLOWED_PORTS,
        ];
    }

    /**
     * حفظ الإعداد بعد أن يقبل الخادم تسجيل الدخول.
     *
     * كلمة المرور الفارغة تُبقي المحفوظة — إلا إن تغيّر الخادم أو المنفذ أو
     * اسم المستخدم: جلسة مخترَقة لا تستطيع توجيه كلمة المرور المحفوظة إلى
     * خادم تختاره.
     *
     * @param  array<string, mixed>  $validated  ما مرّ بقواعد StoreEmailChannel.
     *
     * @throws ValidationException على password أو host، كما يعرضها النموذج.
     */
    public function save(int $organizationId, array $validated, ?int $userId): ChannelSettingsResult
    {
        $existing = $this->account($organizationId);

        $smtp = [
            'host' => strtolower(trim($validated['host'])),
            'port' => (int) $validated['port'],
            'encryption' => $validated['encryption'],
            'username' => trim($validated['username']),
            'password' => (string) ($validated['password'] ?? ''),
        ];

        if ($smtp['password'] === '') {
            $sameServer = $existing
                && $existing->credential('host') === $smtp['host']
                && (int) $existing->credential('port') === $smtp['port']
                && $existing->credential('username') === $smtp['username'];

            if (!$sameServer || !filled($existing->credential('password'))) {
                throw ValidationException::withMessages([
                    'password' => __('Enter the password — the server, port or username changed.'),
                ]);
            }

            $smtp['password'] = (string) $existing->credential('password');
        }

        $this->verify($organizationId, $smtp);

        $account = ChannelAccount::updateOrCreate(
            [
                'organization_id' => $organizationId,
                'channel' => Channel::EMAIL,
                'external_id' => 'org:' . $organizationId,
            ],
            [
                'name' => trim($validated['from_name']),
                'username' => strtolower(trim($validated['from_address'])),
                'credentials' => $smtp,
                'settings' => ['reply_to' => $validated['reply_to'] ?? null],
                'status' => ChannelAccount::STATUS_ACTIVE,
                'last_error' => null,
                'created_by' => $userId,
            ]
        );

        return ChannelSettingsResult::success(
            __('Email was connected. Your email campaigns will be sent from :address.', [
                'address' => strtolower(trim($validated['from_address'])),
            ]),
            $account
        );
    }

    /** رسالة تجربة إلى بريد المستخدم نفسه — تكشف ما لا يكشفه تسجيل الدخول (رفض المرسِل). */
    public function sendTest(int $organizationId, string $to): ChannelSettingsResult
    {
        $account = $this->account($organizationId);

        if (!$account || !$account->isActive()) {
            return ChannelSettingsResult::error(__('Connect your email first.'), ChannelSettingsResult::NOT_CONNECTED);
        }

        try {
            $transport = $this->smtp->forAccount($account);
            $email = (new Email())
                ->from(new Address((string) $account->username, (string) $account->name))
                ->to($to)
                ->subject(__('Test email from :name', ['name' => (string) $account->name]))
                ->text(__('Your email channel is connected. Campaign emails will be sent like this one.'));

            try {
                $transport->send($email);
            } finally {
                if (method_exists($transport, 'stop')) {
                    $transport->stop();
                }
            }
        } catch (SmtpHostNotAllowed $e) {
            return ChannelSettingsResult::error($e->getMessage(), ChannelSettingsResult::SMTP_FAILED);
        } catch (TransportExceptionInterface $e) {
            $failure = SmtpFailure::classify($e);
            $this->logFailure($organizationId, 'test', $failure);

            return ChannelSettingsResult::error($failure['message'], ChannelSettingsResult::SMTP_FAILED);
        }

        return ChannelSettingsResult::success(__('A test email was sent to :email.', ['email' => $to]));
    }

    /** @throws ValidationException */
    private function verify(int $organizationId, array $smtp): void
    {
        try {
            $this->smtp->verify($smtp);
        } catch (SmtpHostNotAllowed $e) {
            throw ValidationException::withMessages(['host' => $e->getMessage()]);
        } catch (TransportExceptionInterface $e) {
            $failure = SmtpFailure::classify($e);
            $this->logFailure($organizationId, 'verify', $failure);

            throw ValidationException::withMessages([
                $failure['code'] === SmtpFailure::AUTH_FAILED ? 'password' : 'host' => $failure['code'] === SmtpFailure::AUTH_FAILED
                    ? $failure['message']
                    : __('Could not connect to :host on port :port.', ['host' => $smtp['host'], 'port' => $smtp['port']]) . ' ' . SmtpFailure::reason($e->getMessage()),
            ]);
        }
    }

    /** بلا getDebug(): يحمل حوار SMTP ومنه تسجيل الدخول. */
    private function logFailure(int $organizationId, string $step, array $failure): void
    {
        Log::warning('Merchant SMTP ' . $step . ' failed', [
            'organization_id' => $organizationId,
            'code' => $failure['code'],
        ]);
    }
}
