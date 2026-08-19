<?php

namespace App\Services\Chat;

use App\Models\Contact;
use App\Support\ChatStatus;
use App\Support\JsonText;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Builds the minimal chat payload broadcast over Pusher / used by mobile API
 * responses. Centralises the size-trimming rules so both the realtime event
 * and the helper used by API list endpoints stay consistent.
 *
 * Why this exists:
 *  - Pusher caps each message at 10KB; we must guarantee the encoded payload
 *    stays below that limit, otherwise the broadcast is dropped silently.
 *  - The broadcast and the REST API share the same JSON shape, so the
 *    "minimal value" must be produced by a single source of truth.
 *  - Heavy lookups (Contact row, unread count) belong to a service we can
 *    test in isolation, not to an event constructor that runs in the request
 *    thread.
 */
class ChatBroadcastPayloadBuilder
{
    /** Hard limit imposed by Pusher on a single broadcast payload. */
    public const PUSHER_MAX_PAYLOAD_BYTES = 10240;

    /**
     * Whitelisted keys we keep from each chat-log "metadata" blob. The
     * Flutter / Vue chat bubble only renders these.
     */
    private const LOG_METADATA_KEYS = ['status', 'errors', 'id'];

    private const MAX_MEDIA_PATH_BYTES = 200;
    private const MAX_MEDIA_NAME_BYTES = 80;
    private const MAX_FULL_NAME_BYTES = 120;
    private const MAX_LOGS_NORMAL = 6;
    private const MAX_LOGS_UNDER_PRESSURE = 2;

    /**
     * Normalise the wrapper we get from dispatchers (which historically use
     * either `[[type=chat, value=...]]` or `[type=chat, value=...]`) and
     * apply {@see buildMinimalValue()} to the inner chat row.
     */
    public function buildWrappedChat($chat, int $organizationId, bool $isNewContact): array
    {
        if (is_array($chat) && isset($chat[0])) {
            $item = $chat[0];
            $item = is_array($item) ? $item : (array) $item;
            if (($item['type'] ?? null) === 'chat' && array_key_exists('value', $item)) {
                $item['value'] = $this->buildMinimalValue($item['value'], $organizationId, $isNewContact);
            }
            return [$item];
        }

        if (is_array($chat) && array_key_exists('value', $chat)) {
            $chat['value'] = $this->buildMinimalValue($chat['value'], $organizationId, $isNewContact);
            return $chat;
        }

        return is_array($chat) ? $chat : [];
    }

    /**
     * Build the minimal representation of a single chat row. Contact data is
     * fetched ONCE per chat and is restricted to the broadcasting
     * organization to prevent cross-tenant leaks.
     */
    public function buildMinimalValue($value, int $organizationId, bool $isNewContact): array
    {
        $arr = $value instanceof \Illuminate\Database\Eloquent\Model
            ? $value->toArray()
            : (array) $value;

        // قيمة فارغة تعني أن الكيان لم يُعثر عليه — ChatLog::relatedEntities
        // تُرجع null حين تشير entity_id إلى رسالة غير موجودة، وخمسة مواقع بثّ
        // تمرّرها بلا احتياط.
        //
        // البناء من مصفوفة فارغة كان يُنتج «رسالة» كل حقولها null: بلا معرّف
        // ولا تاريخ ولا محتوى. والتطبيق يفعل createdAt! على الواردة فينهار.
        // فنُرجع [] ليمتنع البثّ رأساً (انظر hasUsableChat) — تحديثٌ يفوت
        // تستدركه المزامنة التالية، أمّا حمولة كاذبة فتُخزَّن عند العميل
        // وتنهار عليها الواجهة.
        if ($arr === [] || ($arr['id'] ?? null) === null) {
            return [];
        }

        $user = null;
        if (!empty($arr['user']) && is_array($arr['user'])) {
            $user = array_intersect_key($arr['user'], array_flip(['first_name', 'last_name']));
        }

        $media = null;
        if (!empty($arr['media']) && is_array($arr['media'])) {
            $media = [
                'type' => $arr['media']['type'] ?? null,
                'size' => $arr['media']['size'] ?? null,
                'path' => $this->truncateToBytes($arr['media']['path'] ?? '', self::MAX_MEDIA_PATH_BYTES),
                'name' => $this->truncateToBytes($arr['media']['name'] ?? '', self::MAX_MEDIA_NAME_BYTES),
            ];
        }

        $logs = $this->minimalLogs($arr['logs'] ?? [], self::MAX_LOGS_NORMAL);

        $contactId = $arr['contact_id'] ?? null;
        $contactInfo = $this->loadContactInfo($contactId, $organizationId);

        $metadata = $arr['metadata'] ?? null;
        $metadata = is_string($metadata) ? json_decode($metadata, true) : $metadata;
        if (is_array($metadata)) {
            $metadata = JsonText::encode($metadata);
        }

        return [
            'id'                      => $arr['id'] ?? null,
            'uuid'                    => $arr['uuid'] ?? null,
            'contact_uuid'            => $contactInfo['uuid'],
            'contact_id'              => $contactId,
            'is_new_contact'          => $isNewContact,
            'phone'                   => $contactInfo['phone'],
            'formatted_phone_number'  => $contactInfo['formatted_phone_number'],
            'organization_id'         => $contactInfo['organization_id'] ?? $organizationId,
            'latest_chat_created_at'  => $contactInfo['latest_chat_created_at'],
            'is_blocked'              => $contactInfo['is_blocked'],
            'is_favorite'             => $contactInfo['is_favorite'],
            'contact_full_name'       => $contactInfo['full_name'],
            'unread_messages_count'   => $contactInfo['unread_messages_count'],
            'created_at'              => $arr['created_at'] ?? null,
            'deleted_at'              => $arr['deleted_at'] ?? null,
            'metadata'                => $metadata,
            'type'                    => $arr['type'] ?? 'outbound',
            'wam_id'                  => $arr['wam_id'] ?? null,
            // نفس ترجمة الـAPI: التطبيق يرفض أي حالة خارج قائمته.
            'status'                  => ChatStatus::forApi($arr['status'] ?? null),
            'media'                   => $media,
            'logs'                    => $logs,
            'user'                    => $user,
            // التفاعل يصل بثّاً لحظياً كبقية الرسائل، وبلا هذا الحقل يظهر
            // بلا اقتباس حتى تُعاد الصفحة.
            'reaction_context'        => $arr['reaction_context'] ?? null,
        ];
    }

    /**
     * هل الحمولة تحمل رسالة حقيقية؟ buildMinimalValue يُرجع [] حين يتعذّر
     * العثور على الكيان، وهذه هي العلامة التي يقرأها الحدث ليمتنع عن البثّ.
     *
     * @param  mixed  $chat  الغلاف كما يبنيه buildWrappedChat.
     */
    public function hasUsableChat($chat): bool
    {
        if (!is_array($chat)) {
            return false;
        }

        $value = $chat[0]['value'] ?? $chat['value'] ?? null;

        return is_array($value) && ($value['id'] ?? null) !== null;
    }

    /**
     * Encode the payload, and if it overflows Pusher's 10KB ceiling drop or
     * shrink optional fields step-by-step until it fits. As a last resort
     * the metadata blob is dropped entirely; if even that is not enough we
     * log a warning so the bug can be diagnosed (the broadcast itself will
     * still go out — Pusher will be the one rejecting it).
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $context  Diagnostic context for the log.
     * @return array<string, mixed>
     */
    public function fitToPusherLimit(array $payload, array $context = []): array
    {
        if ($this->fits($payload)) {
            return $payload;
        }

        if (!is_array($payload['chat'] ?? null)) {
            return $payload;
        }

        $value = &$this->valueRef($payload);
        if ($value === null) {
            return $payload;
        }

        $steps = [
            function (array &$v) {
                $v['contact_full_name'] = null;
            },
            function (array &$v) {
                $v['phone'] = null;
                $v['formatted_phone_number'] = null;
            },
            function (array &$v) {
                $v['logs'] = $this->minimalLogs($v['logs'] ?? [], self::MAX_LOGS_UNDER_PRESSURE);
            },
            function (array &$v) {
                $v['metadata'] = '{}';
            },
            function (array &$v) {
                $v['metadata'] = null;
                $v['logs'] = [];
                $v['media'] = null;
            },
        ];

        foreach ($steps as $step) {
            $step($value);
            if ($this->fits($payload)) {
                return $payload;
            }
        }

        Log::warning('NewChatEvent payload still exceeds Pusher limit after shrinking', array_merge([
            'final_size' => strlen((string) json_encode($payload)),
            'limit'      => self::PUSHER_MAX_PAYLOAD_BYTES,
        ], $context));

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function fits(array $payload): bool
    {
        $encoded = json_encode($payload);
        return $encoded !== false && strlen($encoded) <= self::PUSHER_MAX_PAYLOAD_BYTES;
    }

    /**
     * Return a reference to the chat-row value buried inside the payload so
     * shrink steps can mutate it in-place. Mirrors the wrapper-detection
     * logic used by {@see buildWrappedChat()}.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function &valueRef(array &$payload)
    {
        $null = null;
        if (!isset($payload['chat']) || !is_array($payload['chat'])) {
            return $null;
        }
        if (isset($payload['chat'][0]['value']) && is_array($payload['chat'][0]['value'])) {
            return $payload['chat'][0]['value'];
        }
        if (isset($payload['chat']['value']) && is_array($payload['chat']['value'])) {
            return $payload['chat']['value'];
        }
        return $null;
    }

    /**
     * Truncate a string so its byte length never exceeds the given cap while
     * remaining valid UTF-8. {@see mb_strcut} is used because it never cuts
     * a multi-byte character in half.
     */
    public function truncateToBytes(string $s, int $maxBytes): string
    {
        if (strlen($s) <= $maxBytes) {
            return $s;
        }
        return mb_strcut($s, 0, $maxBytes, 'UTF-8') ?: '';
    }

    /**
     * Reduce a list of chat logs to the bare minimum the UI uses (status,
     * errors, id). Anything else is dropped so the payload stays small.
     *
     * @param  mixed  $rawLogs
     * @return array<int, array{metadata: string}>
     */
    public function minimalLogs($rawLogs, int $maxEntries): array
    {
        if (empty($rawLogs) || !is_array($rawLogs)) {
            return [];
        }

        $rawLogs = array_slice($rawLogs, -$maxEntries, $maxEntries);

        $out = [];
        foreach ($rawLogs as $log) {
            $logArr = is_array($log) ? $log : (array) $log;
            $rawMetadata = $logArr['metadata'] ?? '{}';
            $decoded = is_string($rawMetadata) ? json_decode($rawMetadata, true) : $rawMetadata;
            if (!is_array($decoded)) {
                $decoded = [];
            }
            $minimal = array_intersect_key($decoded, array_flip(self::LOG_METADATA_KEYS));
            $minimal = ChatStatus::normalizeLogMetadata($minimal);
            $out[] = ['metadata' => JsonText::encode($minimal)];
        }

        return $out;
    }

    /**
     * Single Contact lookup constrained to the broadcasting organization,
     * plus the unread inbound message count. Returns a normalised array of
     * placeholders when the contact does not exist or belongs to a
     * different organization.
     *
     * @return array{
     *   uuid: ?string,
     *   phone: ?string,
     *   formatted_phone_number: ?string,
     *   full_name: ?string,
     *   organization_id: ?int,
     *   latest_chat_created_at: mixed,
     *   is_blocked: ?bool,
     *   is_favorite: ?bool,
     *   unread_messages_count: int,
     * }
     */
    private function loadContactInfo($contactId, int $organizationId): array
    {
        $blank = [
            'uuid'                   => null,
            'phone'                  => null,
            'formatted_phone_number' => null,
            'full_name'              => null,
            'organization_id'        => null,
            'latest_chat_created_at' => null,
            'is_blocked'             => null,
            'is_favorite'            => null,
            'unread_messages_count'  => 0,
        ];

        if (!$contactId) {
            return $blank;
        }

        $contact = Contact::query()
            ->where('id', $contactId)
            ->where('organization_id', $organizationId)
            ->first([
                'id', 'phone', 'first_name', 'last_name', 'organization_id',
                'latest_chat_created_at', 'is_blocked', 'is_favorite', 'uuid',
            ]);

        if (!$contact) {
            return $blank;
        }

        $unread = (int) DB::table('chats')
            ->where('contact_id', $contactId)
            ->where('organization_id', $organizationId)
            ->where('type', 'inbound')
            ->where('is_read', 0)
            ->whereNull('deleted_at')
            ->count();

        $fullName = $this->truncateToBytes(
            trim(($contact->first_name ?? '') . ' ' . ($contact->last_name ?? '')),
            self::MAX_FULL_NAME_BYTES
        );

        return [
            'uuid'                   => $contact->uuid,
            'phone'                  => $contact->phone,
            'formatted_phone_number' => $contact->formatted_phone_number,
            'full_name'              => $fullName !== '' ? $fullName : null,
            'organization_id'        => (int) $contact->organization_id,
            'latest_chat_created_at' => $contact->latest_chat_created_at,
            'is_blocked'             => $contact->is_blocked,
            'is_favorite'            => $contact->is_favorite,
            'unread_messages_count'  => $unread,
        ];
    }
}
