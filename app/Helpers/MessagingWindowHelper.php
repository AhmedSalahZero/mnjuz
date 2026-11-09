<?php

namespace App\Helpers;

use App\Models\Contact;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;

class MessagingWindowHelper
{
    public const WINDOW_HOURS = 24;

    /**
     * @param  int|null  $windowHours  نافذة القناة؛ null يعني بلا نافذة
     *                                 (تيليجرام)، وإغفاله يُبقي سلوك واتساب.
     */
    public static function isOpen(?string $lastInboundAtUtc, ?int $windowHours = self::WINDOW_HOURS): bool
    {
        // بلا نافذة: القناة تسمح بالمراسلة متى شئنا (تيليجرام).
        if ($windowHours === null) {
            return true;
        }

        if ($lastInboundAtUtc === null || $lastInboundAtUtc === '') {
            return false;
        }

        try {
            $lastInbound = Carbon::parse($lastInboundAtUtc, 'UTC');
        } catch (\Throwable) {
            return false;
        }

        return $lastInbound->greaterThan(Carbon::now('UTC')->subHours($windowHours));
    }

    /**
     * نافذة القناة التي تنتمي إليها جهة الاتصال.
     *
     * جهات الاتصال القائمة كلّها channel = 'whatsapp' فتُرجع ٢٤ كما كان.
     */
    public static function windowHoursFor(Contact $contact): ?int
    {
        $channel = $contact->channel ?? \App\Services\Messaging\Channel::DEFAULT;

        if ($channel === \App\Services\Messaging\Channel::WHATSAPP) {
            return self::WINDOW_HOURS;
        }

        return app(\App\Services\Messaging\ChannelManager::class)
            ->capabilities($channel)
            ->windowHours;
    }

    public static function resolveLastInboundAtUtc(Contact $contact): ?Carbon
    {
        $raw = $contact->getAttributes()['last_inbound_chat_created_at'] ?? null;

        if ($raw) {
            return Carbon::parse($raw, 'UTC');
        }

        $latest = $contact->chats()
            ->where('type', 'inbound')
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->value('created_at');

        return $latest ? Carbon::parse($latest, 'UTC') : null;
    }

    public static function isMessagingWindowOpen(Contact $contact): bool
    {
        return self::isOpen(
            self::resolveLastInboundAtUtc($contact)?->toDateTimeString(),
            self::windowHoursFor($contact)
        );
    }

    /**
     * @return array{
     *     last_inbound_chat_created_at: ?string,
     *     last_inbound_chat_created_at_iso: ?string,
     *     last_inbound_chat: ?array{created_at: string, created_at_iso: string},
     *     is_messaging_window_open: bool
     * }
     */
    public static function payloadForContact(Contact $contact): array
    {
        $lastInboundUtc = self::resolveLastInboundAtUtc($contact);
        $iso = $lastInboundUtc?->toIso8601String();

        return [
            'last_inbound_chat_created_at' => $lastInboundUtc?->toDateTimeString(),
            'last_inbound_chat_created_at_iso' => $iso,
            'last_inbound_chat' => $iso
                ? [
                    'created_at' => $iso,
                    'created_at_iso' => $iso,
                ]
                : null,
            'is_messaging_window_open' => self::isOpen(
                $lastInboundUtc?->toDateTimeString(),
                self::windowHoursFor($contact)
            ),
            // المفاتيح أعلاه كما كانت حرفياً — التطبيق المنشور يقرأها.
            // هذان إضافيان: يتجاهلهما القديم، ويكفيان الأحدث ليعرف لماذا
            // النافذة مغلقة أو مفتوحة دون أن يفترض ٢٤ ساعة.
            'messaging_window_hours' => self::windowHoursFor($contact),
            'channel' => $contact->channel ?? \App\Services\Messaging\Channel::DEFAULT,
        ];
    }

    public static function closedWindowJsonResponse(?Contact $contact = null): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => self::closedWindowMessage($contact),
        ], 422);
    }

    /**
     * نصّ «النافذة مغلقة» بحسب القناة.
     *
     * بلا جهة اتصال يبقى نصّ واتساب حرفياً كما كان — وهو ما تقرأه كل
     * المسارات القائمة ويُترجمه ملفّ اللغة اليوم.
     *
     * ومع جهة اتصال على قناة أخرى نُغيّر النصّ: ذكر «واتساب» و«رسالة قالب»
     * أمام موظّف يراسل على تيليجرام يُرسله يبحث عن قوالب لا وجود لها.
     */
    public static function closedWindowMessage(?Contact $contact = null): string
    {
        $channel = $contact?->channel ?? \App\Services\Messaging\Channel::DEFAULT;

        if ($channel === \App\Services\Messaging\Channel::WHATSAPP) {
            return __(
                'Whatsapp does not allow sending messages 24 hours after they last messaged you. However, you can send them a template message'
            );
        }

        return __(
            ':channel does not allow sending messages :hours hours after the customer last messaged you. Wait for them to write again.',
            [
                'channel' => \App\Services\Messaging\Channel::label($channel),
                'hours' => self::windowHoursFor($contact),
            ]
        );
    }

    public static function closedWindowApiJsonResponse(?Contact $contact = null): JsonResponse
    {
        return response()->json([
            'statusCode' => 422,
            'success' => false,
            'message' => self::closedWindowMessage($contact),
        ], 422);
    }
}
