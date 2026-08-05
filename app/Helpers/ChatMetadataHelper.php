<?php

namespace App\Helpers;

/**
 * يقلّص payload الويب هوك (الرسائل الواردة) قبل حفظها في chat.metadata.
 * نحفظ فقط المفاتيح المستخدمة في الواجهة (ChatBubble) والـ AutoReply،
 * ولا نحفظ مثلاً: url، id، sha256، mime_type داخل الميديا (لأن العرض يعتمد على content.media).
 */
class ChatMetadataHelper
{
    /**
     * إرجاع نسخة مصغرة من رسالة الويب هوك مناسبة للحفظ في chat.metadata.
     * لا يُحفظ: from, id, timestamp؛ ولا داخل الميديا: url, id, sha256, mime_type.
     */
    public static function minimalPayloadForStorage(array $message): array
    {
		// logger('minimalPayloadForStorage');
		// logger($message);
        $type = $message['type'] ?? null;
        if (!$type) {
            return $message;
        }

        $out = ['type' => $type];

        switch ($type) {
            case 'text':
                $out['text'] = isset($message['text']) ? array_intersect_key($message['text'], array_flip(['body'])) : [];
                if (!empty($message['header'])) {
                    $out['header'] = $message['header'];
                }
                if (!empty($message['buttons'])) {
                    $out['buttons'] = $message['buttons'];
                }
                break;

            case 'button':
                $out['button'] = isset($message['button']) ? array_intersect_key($message['button'], array_flip(['text', 'payload'])) : [];
                break;

            case 'interactive':
                $out['interactive'] = $message['interactive'] ?? [];
                break;

            case 'image':
                $out['image'] = self::minimalMediaBlock($message['image'] ?? [], ['caption']);
                if (!empty($message['buttons'])) {
                    $out['buttons'] = $message['buttons'];
                }
                break;

            case 'video':
                $out['video'] = self::minimalMediaBlock($message['video'] ?? [], ['caption']);
                if (!empty($message['buttons'])) {
                    $out['buttons'] = $message['buttons'];
                }
                break;

            case 'audio':
                $out['audio'] = self::minimalMediaBlock($message['audio'] ?? [], ['voice']);
                break;

            case 'document':
                $out['document'] = self::minimalMediaBlock($message['document'] ?? [], ['filename']);
                break;

            case 'sticker':
                $out['sticker'] = self::minimalMediaBlock($message['sticker'] ?? [], []);
                break;

            case 'location':
                // name/address/url تصل حين يشارك المرسل مكاناً مسمّى لا نقطة
                // خام، وهي أوضح للموظف من إحداثيات مجرّدة — ونصوص قصيرة لا
                // تُثقل العمود.
                $out['location'] = isset($message['location'])
                    ? array_intersect_key($message['location'], array_flip(['latitude', 'longitude', 'name', 'address', 'url']))
                    : [];
                break;

            case 'contacts':
                // Strip base64 vcard blobs — UI rebuilds vCards from name/phones/emails.
                // Keeping them easily exceeds MySQL TEXT (65KB) when many contacts are shared.
                $out['contacts'] = array_map(
                    static function ($contact) {
                        if (!is_array($contact)) {
                            return $contact;
                        }
                        unset($contact['vcard']);
                        return $contact;
                    },
                    $message['contacts'] ?? []
                );
                break;

            case 'template':
                $out['template'] = $message['template'] ?? [];
                break;

            case 'unsupported':
                if (!empty($message['errors'])) {
                    $out['errors'] = $message['errors'];
                }
                break;

            default:
                return $message;
        }

        if (array_key_exists('transcript', $message)) {
            $out['transcript'] = $message['transcript'];
        }

        return $out;
    }

    /**
     * استخراج من كتلة ميديا (image/video/audio/document/sticker) المفاتيح المسموح بها فقط.
     * لا نضمّن: id, url, sha256, mime_type (غير مستخدمة في العرض؛ الملف من content.media).
     * إذا كانت القيمة list فارغة [] أو مفاتيح رقمية، نُرجع map بالمفاتيح المسموحة (قيم null)
     * حتى لا تُستقبل كـ list وتُستخدم كـ map فتُسبب خطأ.
     */
    private static function minimalMediaBlock(array $block, array $allowedKeys): array
    {
        $allowed = array_flip($allowedKeys);
        if (empty($block)) {
            return array_fill_keys($allowedKeys, null);
        }
        // إذا كانت list (مفاتيح رقمية فقط) نعاملها كفارغة ونُرجع map
        $keys = array_keys($block);
        if ($keys === array_keys(array_values($block))) {
            return array_fill_keys($allowedKeys, null);
        }
        $filtered = array_intersect_key($block, $allowed);
        // لو ما في أي مفتاح مسموح موجود، نُرجع map بقيم null بدل [] الفارغة
        // لأن json_encode([]) = "[]" (list) وليس "{}" (object)
        return empty($filtered) ? array_fill_keys($allowedKeys, null) : $filtered;
    }
}
