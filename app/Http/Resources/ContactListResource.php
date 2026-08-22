<?php

namespace App\Http\Resources;

use App\Models\Contact;
use App\Support\JsonText;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * مورد خفيف لقائمة المحادثات (الـ sidebar) فقط.
 * يُستخدم بدل ContactResource لتقليل حجم الـ response مع آلاف الـ contacts.
 * الحقول مأخوذة من: ChatTable.vue (ما يُعرض في كل صف).
 */
class ContactListResource extends JsonResource
{
    public const PREVIEW_MAX_LENGTH = 80;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'full_name' => $this->full_name,
            'avatar' => $this->avatar ?? null,
            'latest_chat_created_at' => $this->latest_chat_created_at,
            'is_blocked' => $this->is_blocked,
            'ticket_status' => $this->ticket_status ?? null,
            'ticket_assigned_to' => $this->ticket_assigned_to ?? null,
            'ticket_assigned_seen' => isset($this->ticket_assigned_seen) ? (bool) $this->ticket_assigned_seen : true,
            'unread_messages' => $this->unread_messages_count ?? 0,
            'last_chat' => $this->whenLoaded('lastChat', function () {
                $chat = $this->lastChat;
                return [
                    'metadata' => self::metadataPreview($chat->metadata),
                    'deleted_at' => $chat->deleted_at,
                    'created_at' => $chat->created_at,
                ];
            }),
            'contact_categories' => $this->whenLoaded('contactCategories', function () {
                return $this->contactCategories->map(fn ($c) => [
                    'id' => $c->id,
                    'uuid' => $c->uuid,
                    'name' => $c->name,
                    'background_color' => $c->background_color ?? '#22c55e',
                    'text_color' => $c->text_color ?? '#ffffff',
                ])->values()->all();
            }, []),
        ];
    }

    /**
     * إرجاع metadata مختصرة للمعاينة فقط (نفس البنية التي يتوقعها الـ Vue مع نص قصير).
     * الـ Vue تستخدم JSON.parse(metadata) ثم تقرأ type و الحقل المعروض؛ نُرجع نص JSON مختصر.
     */
    public static function metadataPreview(?string $metadataJson): string
    {
        if ($metadataJson === null || $metadataJson === '') {
            return '{}';
        }
        $decoded = json_decode($metadataJson, true);
        if (! is_array($decoded)) {
            return $metadataJson;
        }
        $type = $decoded['type'] ?? 'text';
        $out = ['type' => $type];
        if ($type === 'text' && isset($decoded['text']['body'])) {
            $body = $decoded['text']['body'];
            $out['text'] = ['body' => mb_strlen($body) > self::PREVIEW_MAX_LENGTH
                ? mb_substr($body, 0, self::PREVIEW_MAX_LENGTH) . '…'
                : $body];
        } elseif ($type === 'button' && isset($decoded['button']['text'])) {
            $out['button'] = ['text' => $decoded['button']['text']];
        } elseif ($type === 'interactive' && isset($decoded['interactive'])) {
            $i = $decoded['interactive'];
            if (! empty($i['button_reply']['title'])) {
                $out['interactive'] = ['button_reply' => ['title' => mb_strlen($i['button_reply']['title']) > self::PREVIEW_MAX_LENGTH ? mb_substr($i['button_reply']['title'], 0, self::PREVIEW_MAX_LENGTH) . '…' : $i['button_reply']['title']]];
            } elseif (! empty($i['list_reply']['title'])) {
                $title = $i['list_reply']['title'];
                $out['interactive'] = [
                    'list_reply' => [
                        'title' => mb_strlen($title) > self::PREVIEW_MAX_LENGTH ? mb_substr($title, 0, self::PREVIEW_MAX_LENGTH) . '…' : $title,
                        'description' => $i['list_reply']['description'] ?? '',
                    ],
                ];
            } else {
                $out['interactive'] = $i;
            }
        } elseif ($type === 'document') {
            // المستند وحده يحتاج تفصيلاً: المعاينة تعرض صيغته. الوارد يحفظ
            // filename والصادر يحفظ mime_type، فنُبقي الاثنين — إسقاطهما كان
            // يجعل كل مستند يظهر «Unknown ملف» مهما كان نوعه.
            $out = ['type' => $type];
            $document = $decoded['document'] ?? [];
            if (! empty($document['mime_type'])) {
                $out['document']['mime_type'] = $document['mime_type'];
            }
            if (! empty($document['filename'])) {
                $out['document']['filename'] = mb_substr((string) $document['filename'], 0, self::PREVIEW_MAX_LENGTH);
            }
        } elseif (in_array($type, ['image', 'video', 'audio', 'sticker', 'location'], true)) {
            $out = ['type' => $type];
        } elseif ($type === 'contacts' && isset($decoded['contacts'])) {
            $out['contacts'] = $decoded['contacts'];
        } else {
            $out = $decoded;
        }
        return JsonText::encode($out);
    }
}
