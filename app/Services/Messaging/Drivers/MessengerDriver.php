<?php

namespace App\Services\Messaging\Drivers;

use App\Services\Messaging\Channel;
use App\Services\Messaging\ChannelCapabilities;

/**
 * Facebook Messenger — رسائل صفحة فيسبوك.
 *
 * الأقرب شبهاً بواتساب في نموذجه: نافذة ٢٤ ساعة، وwebhooks، وتوكن صفحة.
 * وهو الوحيد بين منصّات ميتا الذي يُرسل صدى الرسائل (message_echoes)،
 * فيصلنا ما يكتبه الموظّف من صندوق فيسبوك نفسه.
 */
class MessengerDriver extends MetaDriver
{
    public function channel(): string
    {
        return Channel::MESSENGER;
    }

    protected function webhookObject(): string
    {
        return 'page';
    }

    public function capabilities(): ChannelCapabilities
    {
        return new ChannelCapabilities(
            windowHours: 24,
            templates: false,
            interactive: false,
            location: false,
            locationRequest: false,
            // ميتا لا تقبل تعليقاً مع المرفق — نُرسله رسالةً تالية.
            mediaCaption: false,
            textWithMedia: false,
            multipleAttachments: false,
            deliveryReceipts: true,
            readReceipts: true,
            blockContact: false,
            reactions: false,
            replyTo: true,
            mediaLimits: [
                'image' => 25 * 1024 * 1024,
                'video' => 25 * 1024 * 1024,
                'audio' => 25 * 1024 * 1024,
                'document' => 25 * 1024 * 1024,
            ],
            campaigns: true,
        );
    }

    /* ───────────────────────── التفويض ───────────────────────── */

    protected function oauthScopes(): array
    {
        return [
            'pages_show_list',
            'pages_messaging',
            'pages_manage_metadata',
            'business_management',
        ];
    }

    /**
     * كل صفحة يُديرها المستخدم صالحة لأن تكون قناة.
     *
     * توكن الصفحة يأتي داخل نفس الردّ، فلا نداء إضافي لكل صفحة.
     */
    protected function accountsFromUserToken(string $userToken): \App\Services\Messaging\Contracts\OAuthResult
    {
        $pages = $this->fetchPages($userToken);

        $accounts = [];

        foreach ($pages as $page) {
            if (empty($page['id']) || empty($page['access_token'])) {
                continue;
            }

            $accounts[] = [
                'external_id' => (string) $page['id'],
                'name' => $page['name'] ?? null,
                'username' => null,
                'avatar' => data_get($page, 'picture.data.url'),
                'credentials' => [
                    'access_token' => $page['access_token'],
                    // رمز التحقّق يُولَّد عندنا: المستخدم لا يخترعه ولا
                    // يلصقه، ونحن من يُقارنه عند وصول الـ webhook.
                    'verify_token' => bin2hex(random_bytes(16)),
                ],
            ];
        }

        return \App\Services\Messaging\Contracts\OAuthResult::choices($accounts);
    }

    protected function profileFields(): string
    {
        return 'first_name,last_name,profile_pic';
    }

    protected function mapProfile(array $data): ?array
    {
        $name = trim(($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? ''));

        if ($name === '' && empty($data['profile_pic'])) {
            return null;
        }

        return array_filter([
            'name' => $name ?: null,
            'avatar' => $data['profile_pic'] ?? null,
        ]);
    }

    protected function accountFields(): string
    {
        return 'id,name';
    }

    protected function subscribedFields(): array
    {
        return [
            'messages',
            'messaging_postbacks',
            'message_deliveries',
            'message_reads',
            'message_echoes',
        ];
    }
}
