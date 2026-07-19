<?php

namespace App\Helpers;

use App\Models\Contact;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;

class MessagingWindowHelper
{
    public const WINDOW_HOURS = 24;

    public static function isOpen(?string $lastInboundAtUtc): bool
    {
        if ($lastInboundAtUtc === null || $lastInboundAtUtc === '') {
            return false;
        }

        try {
            $lastInbound = Carbon::parse($lastInboundAtUtc, 'UTC');
        } catch (\Throwable) {
            return false;
        }

        return $lastInbound->greaterThan(Carbon::now('UTC')->subHours(self::WINDOW_HOURS));
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
        return self::isOpen(self::resolveLastInboundAtUtc($contact)?->toDateTimeString());
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
            'is_messaging_window_open' => self::isOpen($lastInboundUtc?->toDateTimeString()),
        ];
    }

    public static function closedWindowJsonResponse(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => __(
                'Whatsapp does not allow sending messages 24 hours after they last messaged you. However, you can send them a template message'
            ),
        ], 422);
    }

    public static function closedWindowApiJsonResponse(): JsonResponse
    {
        return response()->json([
            'statusCode' => 422,
            'success' => false,
            'message' => __(
                'Whatsapp does not allow sending messages 24 hours after they last messaged you. However, you can send them a template message'
            ),
        ], 422);
    }
}
