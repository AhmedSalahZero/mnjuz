<?php

namespace App\Services\Messaging\Drivers;

use App\Models\ChannelAccount;
use App\Services\Messaging\Channel;
use App\Services\Messaging\ChannelCapabilities;
use App\Services\Messaging\InboundMedia;

/**
 * Instagram Direct — رسائل حساب أعمال على إنستغرام.
 *
 * يمرّ على نفس Graph API الخاص بميتا، فأغلب المنطق موروث. الفروق:
 *   ١. object = "instagram" ومعرّف المُرسِل IGSID لا PSID.
 *   ٢. أنواع مرفقات إضافية: story_mention و reel و share.
 *   ٣. لا مستندات — صور وفيديو وصوت فقط.
 *   ٤. حدّ الصورة ٨ ميغابايت لا ٢٥.
 */
class InstagramDriver extends MetaDriver
{
    public function channel(): string
    {
        return Channel::INSTAGRAM;
    }

    protected function webhookObject(): string
    {
        return 'instagram';
    }

    public function capabilities(): ChannelCapabilities
    {
        return new ChannelCapabilities(
            windowHours: 24,
            templates: false,
            interactive: false,
            location: false,
            locationRequest: false,
            mediaCaption: false,
            textWithMedia: false,
            multipleAttachments: false,
            deliveryReceipts: false,   // إنستغرام لا تُرسل حدث تسليم
            readReceipts: true,
            blockContact: false,
            reactions: true,
            replyTo: true,
            mediaLimits: [
                'image' => 8 * 1024 * 1024,
                'video' => 25 * 1024 * 1024,
                'audio' => 25 * 1024 * 1024,
            ],
            imageMimeTypes: ['image/jpeg', 'image/png'],
            campaigns: true,
        );
    }

    /**
     * ذكر القصّة والريل يصلان مرفقاً بلا نصّ. عرضهما صورةً أو فيديو بلا
     * سياق يُربك الموظّف، فنُضيف سطراً يوضّح ما هو.
     */
    protected function buildContent(array $message): array
    {
        $type = $message['attachments'][0]['type'] ?? null;

        if ($type === 'story_mention') {
            $url = $message['attachments'][0]['payload']['url'] ?? null;

            return [
                ['type' => 'image', 'image' => ['caption' => __('Mentioned you in their story')]],
                $url ? new InboundMedia(type: 'image', url: $url, caption: __('Mentioned you in their story')) : null,
            ];
        }

        return parent::buildContent($message);
    }

    /* ───────────────────────── التفويض ───────────────────────── */

    protected function oauthScopes(): array
    {
        return [
            'pages_show_list',
            'instagram_basic',
            'instagram_manage_messages',
            'pages_manage_metadata',
            'business_management',
        ];
    }

    /**
     * الصفحات المرتبطة بحساب إنستغرام أعمال فقط.
     *
     * صفحة بلا حساب إنستغرام مرتبط لا تصلح قناةً هنا. عرضها في قائمة
     * الاختيار يُغري المستخدم باختيارها ثم يفشل الربط بلا سبب مفهوم —
     * فنُسقطها من القائمة أصلاً.
     *
     * والمعرّف المحفوظ هو معرّف حساب إنستغرام لا معرّف الصفحة، لأنه ما
     * يصل في `entry[].id` من الـ webhook — وعليه يجري التوجيه.
     */
    protected function accountsFromUserToken(string $userToken): \App\Services\Messaging\Contracts\OAuthResult
    {
        $pages = $this->fetchPages($userToken);

        $accounts = [];

        foreach ($pages as $page) {
            $instagram = $page['instagram_business_account'] ?? null;

            if (empty($instagram['id']) || empty($page['access_token'])) {
                continue;
            }

            $accounts[] = [
                'external_id' => (string) $instagram['id'],
                'name' => $instagram['name'] ?? ($page['name'] ?? null),
                'username' => $instagram['username'] ?? null,
                'avatar' => $instagram['profile_picture_url'] ?? null,
                'credentials' => [
                    // توكن الصفحة هو ما يُرسل رسائل إنستغرام كذلك.
                    'access_token' => $page['access_token'],
                    'page_id' => (string) $page['id'],
                    'verify_token' => bin2hex(random_bytes(16)),
                ],
            ];
        }

        return \App\Services\Messaging\Contracts\OAuthResult::choices($accounts);
    }

    protected function pageFields(): string
    {
        return 'id,name,access_token,instagram_business_account{id,username,name,profile_picture_url}';
    }

    protected function profileFields(): string
    {
        return 'name,username,profile_pic';
    }

    protected function mapProfile(array $data): ?array
    {
        return array_filter([
            'name' => $data['name'] ?? ($data['username'] ?? null),
            'username' => $data['username'] ?? null,
            'avatar' => $data['profile_pic'] ?? null,
        ]) ?: null;
    }

    protected function accountFields(): string
    {
        return 'id,name,username,profile_picture_url';
    }

    protected function subscribedFields(): array
    {
        return [
            'messages',
            'messaging_postbacks',
            'message_reactions',
            'messaging_seen',
        ];
    }

    /**
     * حسابات إنستغرام المربوطة بتسجيل دخول إنستغرام المباشر تستعمل
     * graph.instagram.com بدل graph.facebook.com. نقرأ ذلك من إعداد
     * الحساب فيدعم النمطين بلا سائق ثالث.
     */
    protected function graphHost(): string
    {
        return 'https://graph.facebook.com';
    }

    protected function accessToken(ChannelAccount $account): string
    {
        return (string) $account->credential('access_token', '');
    }
}
