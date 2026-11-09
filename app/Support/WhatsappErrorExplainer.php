<?php

namespace App\Support;

/**
 * سبب فشل رسالة واتساب بلغة صاحب الحساب.
 *
 * نظيرة resources/js/Composables/whatsappErrors.js: الويب يشرح الكود في
 * المتصفّح، وتطبيق الجوال يستلم الشرح جاهزاً من الخادم — فالقائمة نفسها
 * والمفاتيح نفسها (موجودة في lang/*.json). بلا هذا يرى الموظّف «failed»
 * وحدها ويظنّ الخلل عندنا، والسبب مكتوب في السجلّ (131049 مثلاً).
 */
final class WhatsappErrorExplainer
{
    /** @var array<int, string> */
    public const EXPLANATIONS = [
        131042 => 'WhatsApp stopped sending for this account because of a billing problem. Check the payment method on the Meta Business account.',
        131049 => 'WhatsApp did not deliver this marketing message to keep the recipient from receiving too many. Try again later, or wait for the customer to message you first.',
        131026 => 'The message could not be delivered: the number may not be on WhatsApp, or it cannot receive messages right now.',
        131048 => 'WhatsApp limited sending because the account quality rating dropped. Reduce marketing sends and wait for the rating to recover.',
        130472 => 'WhatsApp is running an experiment on this recipient and did not deliver marketing messages to them.',
        131053 => 'WhatsApp rejected the attached file. Check the file format and size.',
        131050 => 'The customer chose to stop receiving marketing messages from this account.',
        131031 => 'The WhatsApp business account is restricted or locked. Check the account status on Meta Business.',
        131047 => 'More than 24 hours passed since the customer last messaged, so only an approved template can be sent.',
        131000 => 'WhatsApp could not send the message for an unspecified reason. Try again.',
        130403 => 'WhatsApp temporarily blocked sending because of too many requests. Try again later.',
    ];

    /**
     * أوّل خطأ في حمولة سجلّ، أياً كان شكلها: سجلّ حالة (errors[])، أو ردّ
     * Graph API عند الرفض (data.error أو error).
     *
     * @return array{code: ?int, title: string, message: string, details: string}|null
     */
    public static function first(mixed $payload): ?array
    {
        if (is_string($payload)) {
            $payload = json_decode($payload, true);
        }

        if (!is_array($payload)) {
            return null;
        }

        $candidate = !empty($payload['errors']) && is_array($payload['errors'])
            ? reset($payload['errors'])
            : ($payload['data']['error'] ?? $payload['error'] ?? null);

        if (!is_array($candidate)) {
            return null;
        }

        $code = $candidate['code'] ?? null;

        return [
            'code' => is_numeric($code) ? (int) $code : null,
            'title' => (string) ($candidate['title'] ?? $candidate['error_user_title'] ?? ''),
            'message' => (string) ($candidate['message'] ?? $candidate['error_user_msg'] ?? ''),
            'details' => (string) ($candidate['error_data']['details'] ?? ''),
        ];
    }

    /**
     * الشرح المترجَم إن عرفنا الكود، وإلا نصّ واتساب كما هو.
     *
     * @return array{code: ?int, reason: string, raw: string}|null
     */
    public static function explain(mixed $payload): ?array
    {
        $error = self::first($payload);

        if ($error === null) {
            return null;
        }

        $raw = implode(' — ', array_values(array_unique(array_filter(
            [$error['title'], $error['message'], $error['details']],
            fn ($part) => trim($part) !== ''
        ))));

        $known = $error['code'] !== null ? (self::EXPLANATIONS[$error['code']] ?? null) : null;

        return [
            'code' => $error['code'],
            'reason' => $known !== null ? __($known) : ($raw !== '' ? $raw : __('Unknown error')),
            'raw' => $raw,
        ];
    }
}
