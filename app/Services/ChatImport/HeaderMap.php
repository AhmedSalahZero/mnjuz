<?php

namespace App\Services\ChatImport;

/**
 * أعمدة الملف → حقول الاستيراد.
 *
 * المنصّات تُصدّر بأسماء مختلفة (mobile، message، timestamp…)، ومن يُعدّ
 * الملف بيده يكتب بالعربية. نقبل الأسماء الشائعة بلا حساسيّة لحالة الأحرف
 * ولا للمسافات، ونرفض العمود المكرّر — لا نعرف أيّهما المقصود.
 */
final class HeaderMap
{
    public const REQUIRED = ['phone', 'sent_at', 'direction', 'text'];

    public const OPTIONAL = ['name'];

    /** @var array<string, array<int, string>> */
    private const ALIASES = [
        'phone' => ['phone', 'phone_number', 'phonenumber', 'mobile', 'mobile_number', 'number', 'msisdn', 'wa_id', 'whatsapp', 'whatsapp_number',
            'رقم', 'الرقم', 'رقم_الجوال', 'الجوال', 'رقم_الهاتف', 'الهاتف', 'الموبايل'],
        'name' => ['name', 'contact_name', 'full_name', 'customer_name', 'profile_name', 'الاسم', 'اسم_العميل', 'اسم_جهة_الاتصال'],
        'sent_at' => ['sent_at', 'date', 'datetime', 'date_time', 'timestamp', 'time', 'created_at', 'sent_time', 'message_date',
            'التاريخ', 'الوقت', 'تاريخ_الإرسال', 'تاريخ_الارسال', 'وقت_الإرسال'],
        // لا «type»: منصّات كثيرة تضع فيه نوع الرسالة (text/image) لا اتجاهها.
        'direction' => ['direction', 'dir', 'message_direction', 'الاتجاه'],
        'text' => ['text', 'message', 'body', 'content', 'message_text', 'الرسالة', 'النص', 'المحتوى', 'نص_الرسالة'],
    ];

    /** @var array<string, int> */
    private array $indexes = [];

    /** @var array<int, string> */
    private array $errors = [];

    /** @var array<int, string> */
    private array $ignored = [];

    /** @param array<int, string|null> $header */
    public function __construct(array $header)
    {
        $lookup = [];
        foreach (self::ALIASES as $field => $aliases) {
            foreach ($aliases as $alias) {
                $lookup[self::normalize($alias)] = $field;
            }
        }

        foreach ($header as $index => $title) {
            $title = (string) $title;
            $field = $lookup[self::normalize($title)] ?? null;

            if ($field === null) {
                if (trim($title) !== '') {
                    $this->ignored[] = trim($title);
                }
                continue;
            }

            if (isset($this->indexes[$field])) {
                $this->errors[] = __('The column ":column" appears more than once.', ['column' => trim($title)]);
                continue;
            }

            $this->indexes[$field] = $index;
        }

        foreach (self::REQUIRED as $field) {
            if (!isset($this->indexes[$field])) {
                $this->errors[] = __('The required column ":column" is missing.', ['column' => $field]);
            }
        }
    }

    public static function normalize(string $title): string
    {
        $title = mb_strtolower(trim(str_replace("\xEF\xBB\xBF", '', $title)));

        return trim((string) preg_replace('/[\s\-\.]+/u', '_', $title), '_');
    }

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    /** @return array<int, string> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** @return array<int, string> */
    public function ignored(): array
    {
        return $this->ignored;
    }

    public function index(string $field): ?int
    {
        return $this->indexes[$field] ?? null;
    }

    /** عدد الأعمدة الأدنى الذي يجب أن يبلغه السجلّ ليحمل كل الحقول المطلوبة. */
    public function requiredWidth(): int
    {
        return $this->indexes === [] ? 0 : max($this->indexes) + 1;
    }

    /**
     * قيم الحقول من سجلّ واحد.
     *
     * @param array<int, string|null> $record
     * @return array{phone: ?string, name: ?string, sent_at: ?string, direction: ?string, text: ?string}
     */
    public function extract(array $record): array
    {
        $values = [];
        foreach (array_merge(self::REQUIRED, self::OPTIONAL) as $field) {
            $index = $this->indexes[$field] ?? null;
            $values[$field] = $index !== null && array_key_exists($index, $record) ? $record[$index] : null;
        }

        return $values;
    }
}
