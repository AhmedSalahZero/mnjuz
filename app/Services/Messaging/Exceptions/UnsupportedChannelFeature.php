<?php

namespace App\Services\Messaging\Exceptions;

use App\Services\Messaging\Channel;

/**
 * طُلبت عملية لا تدعمها القناة (قالب على تيليجرام، فيديو على تيك توك…).
 *
 * نرميه بدل أن نُرسل ونفشل: خطأ المنصّة يصل بعد رحلة شبكة وبرسالة غامضة،
 * وهذا يصل فوراً وبرسالة يفهمها الموظّف.
 */
class UnsupportedChannelFeature extends ChannelException
{
    public static function for(string $channel, string $feature): self
    {
        return new self(__(':feature is not supported on :channel.', [
            'feature' => $feature,
            'channel' => Channel::label($channel),
        ]));
    }
}
