<?php

namespace Modules\FlowBuilder\Support;

/**
 * عنوان يستدعيه الخادم نيابةً عن المستخدم (إجراء webhook).
 *
 * كان أيّ عنوان يُستدعى من عامل الطابور: 127.0.0.1، شبكة الخوادم الداخلية،
 * و169.254.169.254 (بيانات اعتماد السحابة). الآن http/https فقط، وكل عناوين
 * المضيف عامة، ويُثبَّت العنوان الذي فُحص في الاتصال نفسه فلا يتبدّل بين
 * الفحص والطلب (DNS rebinding).
 */
final class OutboundUrl
{
    /**
     * @return array{host: string, port: int, ip: string}|null null إن لم يكن العنوان مسموحاً.
     */
    public static function resolvePublic(string $url): ?array
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = $parts['host'] ?? '';

        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return null;
        }

        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        $literal = trim($host, '[]');

        $ips = filter_var($literal, FILTER_VALIDATE_IP) ? [$literal] : self::lookup($host);

        if ($ips === []) {
            return null;
        }

        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return null;
            }
        }

        return ['host' => $host, 'port' => $port, 'ip' => $ips[0]];
    }

    /** @return string[] */
    private static function lookup(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];
        $ips = [];

        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;

            if ($ip !== null) {
                $ips[] = $ip;
            }
        }

        return $ips;
    }
}
