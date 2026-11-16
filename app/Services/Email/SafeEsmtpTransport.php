<?php

namespace App\Services\Email;

use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;

/**
 * ناقل SMTP لحساب التاجر بشرطين لا يفرضهما Symfony:
 *
 *  ١. لا كلمة مرور قبل التشفير: Symfony يرقّي بـ STARTTLS فقط إن عرضه
 *     الخادم، وإلّا يرسل AUTH نصّاً مكشوفاً. هنا يُرفض AUTH و MAIL FROM ما
 *     لم يكن الاتصال مشفّراً.
 *  ٢. لا إرسال بلا تسجيل دخول ناجح: Symfony يتخطّى AUTH إن لم يعلنه
 *     الخادم ويرسل بلا هويّة — فيصير خادمنا مرحّلاً لبريد بأيّ مرسِل عبر أيّ
 *     خادم يقبل ذلك. هنا يُرفض MAIL FROM ما لم يُقبل AUTH (235).
 */
class SafeEsmtpTransport extends EsmtpTransport
{
    private bool $tlsNegotiated = false;
    private bool $authenticated = false;

    public function start(): void
    {
        $this->tlsNegotiated = false;
        $this->authenticated = false;

        parent::start();
    }

    public function executeCommand(string $command, array $codes): string
    {
        $refusal = self::refusal($command, $this->isEncrypted(), $this->authenticated);

        if ($refusal !== null) {
            throw new TransportException($refusal);
        }

        $response = parent::executeCommand($command, $codes);

        if (str_starts_with(strtoupper($command), 'STARTTLS')) {
            // الترقية تتمّ بعد هذا الردّ مباشرة؛ فشلها يرمي قبل أيّ أمر تالٍ.
            $this->tlsNegotiated = true;
        }

        if (in_array(235, $codes, true) && str_starts_with($response, '235')) {
            $this->authenticated = true;
        }

        return $response;
    }

    public function isAuthenticated(): bool
    {
        return $this->authenticated;
    }

    private function isEncrypted(): bool
    {
        $stream = $this->getStream();

        return $this->tlsNegotiated || ($stream instanceof SocketStream && $stream->isTLS());
    }

    /**
     * سبب رفض الأمر، أو null. قاعدة خالصة تُختبر وحدها.
     */
    public static function refusal(string $command, bool $encrypted, bool $authenticated): ?string
    {
        $verb = strtoupper(ltrim($command));

        if (str_starts_with($verb, 'AUTH ') && !$encrypted) {
            return 'The SMTP server did not offer encryption; the password was not sent.';
        }

        if (str_starts_with($verb, 'MAIL FROM')) {
            if (!$encrypted) {
                return 'The SMTP server did not offer encryption.';
            }

            if (!$authenticated) {
                return 'The SMTP server did not accept the login.';
            }
        }

        return null;
    }
}
