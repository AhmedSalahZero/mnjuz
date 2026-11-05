<?php

namespace App\Services\ChatImport;

use App\Services\PhoneService;
use DateTimeImmutable;
use DateTimeZone;
use Propaganistas\LaravelPhone\PhoneNumber;

/**
 * صفّ من الملف → ParsedRow أو قائمة أخطائه.
 *
 * يُجمع كل خطأ في الصفّ لا أوّله فقط: من يُصلح ملفاً من مئتي ألف صفّ لا
 * يريد أن يرفعه مرّة لكل عمود.
 *
 * والرقم لا يُخمَّن مفتاح دولته أبداً إلا إن اختار المستخدم دولة صراحةً
 * (كما في PhoneService::toE164): افتراض السعودية لرقم بلا مفتاح قد يضع
 * محادثة عميلٍ في جهة اتصال شخص آخر.
 */
final class RowParser
{
    /** @var array<string, string> */
    private const DIRECTIONS = [
        'in' => 'inbound', 'inbound' => 'inbound', 'incoming' => 'inbound', 'received' => 'inbound', 'receive' => 'inbound',
        'وارد' => 'inbound', 'واردة' => 'inbound',
        'out' => 'outbound', 'outbound' => 'outbound', 'outgoing' => 'outbound', 'sent' => 'outbound', 'send' => 'outbound',
        'صادر' => 'outbound', 'صادرة' => 'outbound',
    ];

    /** أنواع الوسائط التي تكتبها المنصّات نصّاً بين قوسين: «[image] تعليق». */
    private const MEDIA_TYPES = ['image', 'video', 'audio', 'voice', 'document', 'file', 'sticker', 'gif',
        'contacts', 'location', 'unsupported', 'system'];

    private const DIGITS_FROM = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩', '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];

    private const DIGITS_TO = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

    /** واتساب لم يكن قبل هذا — تاريخ أقدم خطأ في التصدير لا رسالة حقيقية. */
    private const EARLIEST = '2009-01-01 00:00:00';

    /** هامش للساعات غير المتزامنة بين المنصّة القديمة وخادمنا. */
    private const FUTURE_TOLERANCE_SECONDS = 600;

    private const NAME_MAX = 128;

    private DateTimeZone $zone;

    private int $earliest;

    private int $latest;

    /** @var array<string, array{0: ?string, 1: ?string}> */
    private array $phoneCache = [];

    /** @var array<string, DateTimeZone> */
    private array $zones = [];

    public function __construct(
        string $timezone,
        private readonly ?string $defaultCountry = null,
        private readonly int $maxText = 65535,
        ?DateTimeImmutable $now = null,
    ) {
        $this->zone = self::zone($timezone);
        $this->earliest = (new DateTimeImmutable(self::EARLIEST, new DateTimeZone('UTC')))->getTimestamp();
        $this->latest = ($now ?? new DateTimeImmutable('now'))->getTimestamp() + self::FUTURE_TOLERANCE_SECONDS;
    }

    /**
     * @param array{phone: ?string, name: ?string, sent_at: ?string, direction: ?string, text: ?string} $values
     * @return ParsedRow|array<int, array{row: int, column: string, value: string, message: string}>
     */
    public function parse(array $values, int $row): ParsedRow|array
    {
        $errors = [];
        $error = function (string $column, ?string $value, string $message) use (&$errors, $row) {
            $errors[] = [
                'row' => $row,
                'column' => $column,
                'value' => mb_substr((string) $value, 0, 100),
                'message' => $message,
            ];
        };

        foreach ($values as $column => $value) {
            if ($value !== null && !mb_check_encoding($value, 'UTF-8')) {
                $error($column, '', __('The value is not valid UTF-8 text.'));

                return $errors;
            }
        }

        [$phone, $phoneError] = $this->parsePhone((string) ($values['phone'] ?? ''));
        if ($phoneError !== null) {
            $error('phone', $values['phone'], $phoneError);
        }

        [$sentAt, $dateError] = $this->parseDate((string) ($values['sent_at'] ?? ''));
        if ($dateError !== null) {
            $error('sent_at', $values['sent_at'], $dateError);
        }

        $directionRaw = trim((string) ($values['direction'] ?? ''));
        $direction = self::DIRECTIONS[mb_strtolower($directionRaw)] ?? null;
        if ($directionRaw === '') {
            $error('direction', $directionRaw, __('Direction is required.'));
        } elseif ($direction === null) {
            $error('direction', $directionRaw, __('Invalid direction. Use "in" for messages from the customer or "out" for messages you sent.'));
        }

        $text = trim(str_replace(["\r\n", "\r"], "\n", (string) ($values['text'] ?? '')));
        if ($text === '') {
            $error('text', '', __('Message text is required.'));
        } elseif (mb_strlen($text) > $this->maxText) {
            $error('text', $text, __('Message text is longer than :max characters.', ['max' => $this->maxText]));
        }

        if ($errors !== []) {
            return $errors;
        }

        return new ParsedRow(
            row: $row,
            phone: $phone,
            name: self::cleanName($values['name'] ?? null),
            sentAtUtc: $sentAt,
            direction: $direction,
            text: $text,
            originalType: self::originalType($text),
        );
    }

    /** @return array{0: ?string, 1: ?string} [E.164, رسالة الخطأ] */
    private function parsePhone(string $raw): array
    {
        $raw = trim(str_replace(self::DIGITS_FROM, self::DIGITS_TO, $raw));

        return $this->phoneCache[$raw] ??= $this->resolvePhone($raw);
    }

    /** @return array{0: ?string, 1: ?string} */
    private function resolvePhone(string $raw): array
    {
        if ($raw === '') {
            return [null, __('Phone number is required.')];
        }

        // Excel يحوّل الأرقام الطويلة إلى 9.66E+11 ويضيع ما بعد الخانة الثالثة.
        if (preg_match('/^\+?\d+([.,]\d+)?e\+?\d+$/i', $raw)) {
            return [null, __('Excel changed the phone number to scientific notation (like 9.66E+11). Format the column as text and export again.')];
        }

        if (preg_match('/[^\d\s\-\+\(\)\.]/u', $raw)) {
            return [null, __('The phone number contains invalid characters.')];
        }

        // بالصيغة التي يحفظ بها الـ webhook رقم المرسل نفسه (fromWhatsappId):
        // «+» ثم الأرقام. هي نتيجة PhoneService::toE164 ذاتها لأي رقم صالح،
        // بلا محاولته الأولى للرقم كما هو — وهي تفشل حتماً لرقم بلا «+»، أي
        // لكل رقم في تصدير منصّات واتساب، وتكلّف ثلث زمن الفحص.
        $digits = (string) preg_replace('/\D/', '', $raw);
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        $e164 = $digits === '' ? null : PhoneService::getE164Format('+' . $digits);

        if ($e164 === null && $this->defaultCountry) {
            try {
                $local = new PhoneNumber(preg_replace('/\D/', '', $raw), $this->defaultCountry);
                if ($local->isValid()) {
                    $e164 = $local->formatE164();
                }
            } catch (\Throwable) {
                $e164 = null;
            }
        }

        if ($e164 === null) {
            return [null, __('Invalid phone number. Include the country code, e.g. 966501234567.')];
        }

        return [$e164, null];
    }

    /** @return array{0: ?string, 1: ?string} [UTC Y-m-d H:i:s، رسالة الخطأ] */
    private function parseDate(string $raw): array
    {
        $raw = trim($raw);

        if ($raw === '') {
            return [null, __('Date is required.')];
        }

        $timestamp = $this->timestamp($raw);

        if ($timestamp === null) {
            return [null, __('Invalid date. Use a format like 2026-05-23 19:29:38 or 2026-05-23T19:29:38+03:00.')];
        }

        if ($timestamp < $this->earliest) {
            return [null, __('The date is too old.')];
        }

        if ($timestamp > $this->latest) {
            return [null, __('The date is in the future.')];
        }

        return [gmdate('Y-m-d H:i:s', $timestamp), null];
    }

    private function timestamp(string $raw): ?int
    {
        if (preg_match('/^\d{10}$/', $raw)) {
            return (int) $raw;
        }

        if (preg_match('/^\d{13}$/', $raw)) {
            return intdiv((int) $raw, 1000);
        }

        // السنة أوّلاً وحدها: 05/06/2026 يحتمل مايو ويونيو، فلا نقبله.
        $pattern = '/^(\d{4})[-\/](\d{1,2})[-\/](\d{1,2})[T ](\d{1,2}):(\d{2})(?::(\d{2})(?:[.,]\d+)?)?\s*(Z|[+-]\d{2}(?::?\d{2})?)?$/i';

        if (!preg_match($pattern, $raw, $m)) {
            return null;
        }

        [$year, $month, $day, $hour, $minute] = [(int) $m[1], (int) $m[2], (int) $m[3], (int) $m[4], (int) $m[5]];
        $second = isset($m[6]) && $m[6] !== '' ? (int) $m[6] : 0;

        if (!checkdate($month, $day, $year) || $hour > 23 || $minute > 59 || $second > 59) {
            return null;
        }

        $zone = isset($m[7]) && $m[7] !== '' ? $this->offsetZone(strtoupper($m[7])) : $this->zone;

        $date = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $day, $hour, $minute, $second),
            $zone
        );

        return $date === false ? null : $date->getTimestamp();
    }

    /** المنطقة لإزاحة «+03:00» — واحدة لكل إزاحة لا لكل صفّ: إنشاؤها مكلف ويتكرّر مئات الآلاف من المرّات. */
    private function offsetZone(string $offset): DateTimeZone
    {
        if (isset($this->zones[$offset])) {
            return $this->zones[$offset];
        }

        if ($offset === 'Z') {
            return $this->zones[$offset] = new DateTimeZone('UTC');
        }

        $digits = str_pad(str_replace(':', '', substr($offset, 1)), 4, '0');

        return $this->zones[$offset] = new DateTimeZone($offset[0] . substr($digits, 0, 2) . ':' . substr($digits, 2, 2));
    }

    private static function cleanName(?string $name): ?string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', (string) $name));

        // «.» و«-» ليست أسماء: المنصّات تضعها حين لا اسم للعميل.
        if ($name === '' || !preg_match('/[\p{L}\p{N}]/u', $name)) {
            return null;
        }

        return mb_substr($name, 0, self::NAME_MAX);
    }

    private static function originalType(string $text): ?string
    {
        if (!preg_match('/^\[([a-z_ ]{2,32})\]/i', $text, $m)) {
            return null;
        }

        $type = strtolower(trim($m[1]));

        return in_array($type, self::MEDIA_TYPES, true) ? $type : null;
    }

    private static function zone(string $timezone): DateTimeZone
    {
        try {
            return new DateTimeZone($timezone !== '' ? $timezone : 'UTC');
        } catch (\Throwable) {
            return new DateTimeZone('UTC');
        }
    }
}
