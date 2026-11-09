<?php

namespace App\Services\Messaging\Drivers;

use App\Models\ChannelAccount;
use App\Models\Contact;
use App\Services\Messaging\Channel;
use App\Services\Messaging\ChannelCapabilities;
use App\Services\Messaging\Contracts\ChannelDriver;
use App\Services\Messaging\Contracts\DownloadedMedia;
use App\Services\Messaging\Exceptions\ChannelConfigurationException;
use App\Services\Messaging\InboundMedia;
use App\Services\Messaging\OutboundMedia;
use App\Services\Messaging\SendResult;
use App\Services\Messaging\Support\MessageId;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * المحادثة المباشرة من موقع التاجر.
 *
 * الفرق الجوهري عن بقيّة السائقين: لا منصّة خارجية. الوارد يصل من واجهتنا
 * العامّة (routes/webchat.php) لا من webhook، والصادر لا يُرسَل إلى أحد —
 * يكفي أن يُسجَّل في chats، فيصل الزائرَ عبر البثّ إلى قناته أو حين يفتح
 * المحادثة من جديد.
 *
 * لذلك الإرسال هنا لا يفشل لأسباب شبكية: يُعطي معرّفاً فريداً ويترك
 * التسجيل لـ OutboundMessageRecorder كما يفعل كل سائق.
 */
class WebChatDriver implements ChannelDriver
{
    /** قرص الملفات المؤقّتة لرفع الزائر — خارج public فلا تُخدم مباشرة. */
    public const TEMP_DISK = 'local';
    public const TEMP_DIRECTORY = 'webchat-tmp';

    public function channel(): string
    {
        return Channel::WEBCHAT;
    }

    public function capabilities(): ChannelCapabilities
    {
        return new ChannelCapabilities(
            windowHours: null,          // لا نافذة: الزائر يرى الردّ متى عاد
            templates: false,
            interactive: true,          // الأزرار والقوائم تُرسَم في الـ widget
            location: false,
            locationRequest: false,
            mediaCaption: true,
            textWithMedia: true,
            multipleAttachments: false,
            deliveryReceipts: true,
            readReceipts: true,
            blockContact: false,
            reactions: false,
            replyTo: false,
            mediaLimits: [
                'image' => 10 * 1024 * 1024,
                'video' => 25 * 1024 * 1024,
                'audio' => 16 * 1024 * 1024,
                'document' => 25 * 1024 * 1024,
            ],
            imageMimeTypes: ['image/jpeg', 'image/png', 'image/webp', 'image/gif'],
            // حملة إلى زوّار موقع لا تصل إلا من يفتح الموقع صدفةً — ليست حملة.
            campaigns: false,
        );
    }

    public function canAddress(Contact $contact): bool
    {
        return trim((string) $contact->channel_identifier) !== '';
    }

    /* ───────────────────────── الوارد ─────────────────────────
     * لا webhook لهذه القناة: ChannelWebhookController يرفضها قبل الوصول
     * إلى هنا. الدوال موجودة لأن العقد يطلبها، وتردّ «لا شيء».
     */

    public function verifyWebhook(Request $request, ?ChannelAccount $account = null): bool
    {
        return false;
    }

    public function webhookChallenge(Request $request, ?ChannelAccount $account = null): ?string
    {
        return null;
    }

    public function resolveAccountExternalId(Request $request): ?string
    {
        return null;
    }

    public function parseWebhook(Request $request, ChannelAccount $account): array
    {
        return [];
    }

    /**
     * ملف رفعه الزائر: محفوظ مؤقّتاً على القرص الخاصّ وقت الطلب، ومساره في
     * extra['temp_path']. لا نحذفه هنا: إن فشل التخزين النهائي أُعيدت
     * المهمّة ووجدته. التنظيف لأمر webchat:prune.
     */
    public function downloadMedia(ChannelAccount $account, InboundMedia $media): ?DownloadedMedia
    {
        $path = (string) ($media->extra['temp_path'] ?? '');

        if (!$this->isOwnTempPath($account, $path)) {
            Log::warning('Web chat media: temp path outside the widget directory', [
                'account_id' => $account->id,
                'path' => $path,
            ]);

            return null;
        }

        $disk = Storage::disk(self::TEMP_DISK);

        if (!$disk->exists($path)) {
            return null;
        }

        $contents = $disk->get($path);

        return new DownloadedMedia(
            contents: $contents,
            mimeType: $media->mimeType ?: 'application/octet-stream',
            fileName: $media->fileName,
            size: strlen($contents),
        );
    }

    /** مجلّد مؤقّت لكل widget — يمنع مساراً مصنوعاً من قراءة ملف غيره. */
    public static function tempDirectory(ChannelAccount $account): string
    {
        return self::TEMP_DIRECTORY . '/' . $account->organization_id . '/' . $account->id;
    }

    private function isOwnTempPath(ChannelAccount $account, string $path): bool
    {
        if ($path === '' || str_contains($path, '..') || str_contains($path, "\0")) {
            return false;
        }

        return str_starts_with($path, self::tempDirectory($account) . '/');
    }

    public function fetchProfile(ChannelAccount $account, string $externalId): ?array
    {
        return null;
    }

    /* ───────────────────────── الصادر ───────────────────────── */

    public function sendText(ChannelAccount $account, Contact $contact, string $body, array $options = []): SendResult
    {
        if (!$this->canAddress($contact)) {
            return SendResult::failure(__('This contact has no :channel identifier.', ['channel' => Channel::label(Channel::WEBCHAT)]));
        }

        return SendResult::success($this->newMessageId($account, $contact), 'sent');
    }

    public function sendMedia(ChannelAccount $account, Contact $contact, OutboundMedia $media, array $options = []): SendResult
    {
        if (!$this->canAddress($contact)) {
            return SendResult::failure(__('This contact has no :channel identifier.', ['channel' => Channel::label(Channel::WEBCHAT)]));
        }

        // الزائر يفتح الملف من رابطه، فلا معنى لرسالة وسائط بلا رابط.
        if (!$media->hasUrl()) {
            return SendResult::failure(__('The file has no public link.'), 'media_url_missing');
        }

        if (!$this->capabilities()->supportsMedia($media->type)) {
            return SendResult::failure(__(':feature is not available on :channel.', [
                'feature' => $media->type,
                'channel' => Channel::label(Channel::WEBCHAT),
            ]), 'unsupported_media');
        }

        return SendResult::success($this->newMessageId($account, $contact), 'sent');
    }

    public function sendLocation(ChannelAccount $account, Contact $contact, array $location, array $options = []): SendResult
    {
        return SendResult::failure(__(':feature is not available on :channel.', [
            'feature' => __('Location'),
            'channel' => Channel::label(Channel::WEBCHAT),
        ]), 'unsupported');
    }

    /**
     * معرّف رسالة صادرة: فريد عالمياً، ويحمل الـ widget والزائر فيُعرف
     * صاحبه من النظر إليه. ULID لا رقم تسلسلي: لا يتصادم بين عاملين.
     */
    private function newMessageId(ChannelAccount $account, Contact $contact): string
    {
        return MessageId::namespaced(
            Channel::WEBCHAT,
            (string) $account->external_id,
            'o' . strtolower((string) Str::ulid()),
            (string) $contact->channel_identifier
        );
    }

    /* ───────────────────────── الحساب ─────────────────────────
     * الـ widget لا يُربط ببيانات اعتماد من منصّة: يُنشأ من صفحة إعداداته
     * بمفتاح نولّده نحن.
     */

    public function verifyCredentials(array $credentials): array
    {
        throw new ChannelConfigurationException(__('Web chat is set up from its own settings page.'));
    }

    public function registerWebhook(ChannelAccount $account): bool
    {
        return true;
    }

    public function unregisterWebhook(ChannelAccount $account): bool
    {
        return true;
    }
}
