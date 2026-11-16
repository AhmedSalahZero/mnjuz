<?php

namespace App\Services\Email;

use Symfony\Component\HttpFoundation\IpUtils;

/**
 * حارس عنوان خادم SMTP التاجر.
 *
 * التاجر يكتب العنوان والمنفذ، وعمّالنا يتّصلون به ويكتبون فيه أسطراً
 * يتحكّم في جزء منها (المرسِل، الموضوع، المتن). بلا حارس يصبح ذلك بوّابة
 * إلى شبكتنا الداخلية (Redis، MySQL، خدمة البيانات الوصفية للسحابة).
 *
 * فيُحَلّ العنوان هنا، ويُرفض إن كان أيّ من عناوينه خاصّاً أو محجوزاً،
 * ويُعاد العنوان المفحوص نفسه ليتّصل به الناقل — فلا يتغيّر بين الفحص
 * والاتصال (DNS rebinding).
 */
class SmtpHostGuard
{
    /** منافذ الإرسال المعتمدة. 25 ليس منها: هو منفذ الخوادم فيما بينها. */
    public const ALLOWED_PORTS = [465, 587, 2525];

    /** @var \Closure(string): list<string> */
    private \Closure $resolver;

    /** @param  (\Closure(string): list<string>)|null  $resolver  للاختبار. */
    public function __construct(?\Closure $resolver = null)
    {
        $this->resolver = $resolver ?? static function (string $host): array {
            $ipv4 = gethostbynamel($host) ?: [];
            $ipv6 = array_column(@dns_get_record($host, DNS_AAAA) ?: [], 'ipv6');

            return array_values(array_merge($ipv4, $ipv6));
        };
    }

    /**
     * العنوان الذي يُتّصل به: IPv4 إن وُجد، وإلّا IPv6 بين قوسين.
     *
     * @throws SmtpHostNotAllowed
     */
    public function resolvePublicAddress(string $host): string
    {
        $host = strtolower(trim($host, " \t\n\r\0\x0B[]"));

        if ($host === '') {
            throw new SmtpHostNotAllowed(__('This SMTP server address is not allowed.'));
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : ($this->resolver)($host);
        $addresses = array_values(array_filter($addresses, fn ($ip) => filter_var($ip, FILTER_VALIDATE_IP)));

        if ($addresses === []) {
            throw new SmtpHostNotAllowed(__('Could not find the SMTP server :host.', ['host' => $host]));
        }

        if (!config('messaging.email.allow_private_hosts', false)) {
            foreach ($addresses as $address) {
                if (self::isInternal($address)) {
                    throw new SmtpHostNotAllowed(__('This SMTP server address is not allowed.'));
                }
            }
        }

        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return $address;
            }
        }

        return '[' . $addresses[0] . ']';
    }

    public static function isAllowedPort(int $port): bool
    {
        return in_array($port, self::ALLOWED_PORTS, true);
    }

    /** خاصّ أو محجوز: شبكات داخلية، loopback، link-local (بيانات السحابة)، CGNAT… */
    public static function isInternal(string $address): bool
    {
        return IpUtils::isPrivateIp($address)
            || !filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }
}
