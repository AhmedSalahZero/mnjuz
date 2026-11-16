<?php

namespace App\Services\Messaging;

use App\Models\ChannelAccount;

/**
 * نتيجة عمليّة على إعدادات قناة (ربط، فصل، حفظ، اختبار).
 *
 * الخدمة لا تعرف من يسألها: الويب يحوّلها إلى رسالة flash ويعود إلى الصفحة،
 * والتطبيق إلى ردّ JSON بحالة HTTP. الرسالة نفسها في الحالتين.
 *
 * code يُميّز سبب الرفض لمن يحتاج حالة HTTP مختلفة لكلٍّ منها (التطبيق)؛
 * الويب لا يقرؤه.
 */
final class ChannelSettingsResult
{
    public const SUCCESS = 'success';
    public const WARNING = 'warning';
    public const ERROR = 'error';

    /** بيانات الحساب مرفوضة لدى المنصّة. */
    public const INVALID_CREDENTIALS = 'invalid_credentials';
    /** تعذّر الوصول إلى المنصّة أصلاً. */
    public const UNREACHABLE = 'unreachable';
    /** المنصّة قبلت البيانات ولم تُرجع معرّف الحساب. */
    public const MISSING_EXTERNAL_ID = 'missing_external_id';
    /** الحساب مربوط بمنشأة أخرى. */
    public const TAKEN = 'taken';
    /** حُفظ الحساب ولم يُسجَّل الـ webhook. */
    public const WEBHOOK_FAILED = 'webhook_failed';
    public const NOT_FOUND = 'not_found';
    /** يُدار من صفحته (المحادثة المباشرة). */
    public const MANAGED_ELSEWHERE = 'managed_elsewhere';
    public const NOT_CONNECTED = 'not_connected';
    public const SMTP_FAILED = 'smtp_failed';

    private function __construct(
        public readonly string $type,
        public readonly string $message,
        public readonly ?string $code = null,
        public readonly ?ChannelAccount $account = null,
    ) {
    }

    public static function success(string $message, ?ChannelAccount $account = null): self
    {
        return new self(self::SUCCESS, $message, null, $account);
    }

    public static function warning(string $message, string $code, ?ChannelAccount $account = null): self
    {
        return new self(self::WARNING, $message, $code, $account);
    }

    public static function error(string $message, string $code): self
    {
        return new self(self::ERROR, $message, $code);
    }

    public function isError(): bool
    {
        return $this->type === self::ERROR;
    }

    /** @return array{type: string, message: string} شكل status الذي تقرؤه صفحات الويب. */
    public function toFlash(): array
    {
        return ['type' => $this->type, 'message' => $this->message];
    }
}
