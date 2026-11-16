<?php

namespace App\Services\Email;

use App\Models\ChannelAccount;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * ناقل SMTP لحساب بريد التاجر.
 *
 * يتّصل بالعنوان الذي فحصه الحارس لا بالاسم (فلا يتبدّل بين الفحص والاتصال)،
 * ويتحقّق من الشهادة باسم الخادم الأصلي. غير final: الاختبارات تستبدله.
 */
class MerchantSmtpTransportFactory
{
    public function __construct(private readonly SmtpHostGuard $guard)
    {
    }

    /**
     * @param  array{host: string, port: int|string, encryption?: string, username: string, password: string}  $smtp
     *
     * @throws SmtpHostNotAllowed
     */
    public function make(array $smtp): TransportInterface
    {
        $host = trim((string) ($smtp['host'] ?? ''));
        $port = (int) ($smtp['port'] ?? 0);

        if (!SmtpHostGuard::isAllowedPort($port)) {
            throw new SmtpHostNotAllowed(__('Use the SMTP submission port 465, 587 or 2525.'));
        }

        $address = $this->guard->resolvePublicAddress($host);

        // 465 = TLS من أوّل الاتصال، وغيره يُرقّى بـ STARTTLS (والناقل يرفض
        // الإكمال بلا تشفير).
        $transport = new SafeEsmtpTransport($address, $port, ($smtp['encryption'] ?? null) === 'ssl' || $port === 465);
        $transport->setUsername((string) ($smtp['username'] ?? ''));
        $transport->setPassword((string) ($smtp['password'] ?? ''));
        $transport->setLocalDomain(parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost');

        $stream = $transport->getStream();

        if ($stream instanceof SocketStream) {
            $stream->setTimeout((float) config('messaging.email.timeout', 10));
            $stream->setStreamOptions(['ssl' => [
                'peer_name' => $host,
                'SNI_server_name' => $host,
                'verify_peer' => true,
                'verify_peer_name' => true,
            ]]);
        }

        return $transport;
    }

    /** بيانات الاتصال المحفوظة لحساب البريد (credentials مشفّرة). */
    public function forAccount(ChannelAccount $account): TransportInterface
    {
        return $this->make([
            'host' => (string) $account->credential('host'),
            'port' => (int) $account->credential('port'),
            'encryption' => (string) $account->credential('encryption'),
            'username' => (string) $account->credential('username'),
            'password' => (string) $account->credential('password'),
        ]);
    }

    /**
     * يتّصل ويسجّل الدخول ثم يغلق. يرمي إن رُفض الدخول أو لم يكن ممكناً.
     *
     * @throws SmtpHostNotAllowed|TransportException
     */
    public function verify(array $smtp): void
    {
        $transport = $this->make($smtp);

        try {
            $transport->start();

            if ($transport instanceof SafeEsmtpTransport && !$transport->isAuthenticated()) {
                // الخادم لم يعلن AUTH: كان سيرسل بلا هويّة.
                throw new TransportException('The SMTP server did not accept the login.', 535);
            }
        } finally {
            $transport->stop();
        }
    }
}
