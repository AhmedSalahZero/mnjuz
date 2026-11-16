<?php

namespace App\Services\Email;

use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * تصنيف رفض خادم SMTP: ما يُعاد إرساله، وما لا فائدة من إعادته.
 *
 * النصّ الخام لا يُسجَّل كاملاً: getDebug() في Symfony يحمل حوار SMTP كلّه،
 * ومنه تبادل AUTH (اسم المستخدم وكلمة المرور بـ base64).
 */
final class SmtpFailure
{
    public const AUTH_FAILED = 'email_auth_failed';
    public const REJECTED = 'email_rejected';
    public const FAILED = 'email_failed';

    /** @return array{code: string, message: string, permanent: bool} */
    public static function classify(TransportExceptionInterface $e): array
    {
        $raw = (string) $e->getMessage();
        $code = (int) $e->getCode();
        $reason = self::reason($raw);

        if (in_array($code, [530, 534, 535, 538], true)
            || str_contains($raw, 'did not accept the login')
            || preg_match('/authenticat|password|credentials/i', $raw)) {
            return [
                'code' => self::AUTH_FAILED,
                'message' => __('The SMTP server rejected the username or password.'),
                'permanent' => false,
            ];
        }

        // عنوان المستلم مرفوض نهائياً (5.1.x أو «user unknown»): لا إعادة.
        if (preg_match('/\b5\.1\.\d\b/', $raw)
            || (in_array($code, [550, 551, 553], true) && preg_match('/user unknown|no such user|does not exist|unknown recipient|mailbox unavailable|not found/i', $raw))) {
            return [
                'code' => self::REJECTED,
                'message' => __('The recipient address was rejected: :reason', ['reason' => $reason]),
                'permanent' => true,
            ];
        }

        return [
            'code' => self::FAILED,
            'message' => __('The email could not be sent: :reason', ['reason' => $reason]),
            'permanent' => false,
        ];
    }

    /** سبب مختصر بلا أسرار: سطر واحد، 200 حرف. */
    public static function reason(string $raw): string
    {
        $line = trim(strtok($raw, "\n") ?: $raw);

        return mb_substr(preg_replace('/\s+/', ' ', $line), 0, 200);
    }
}
