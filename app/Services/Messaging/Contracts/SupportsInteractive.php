<?php

namespace App\Services\Messaging\Contracts;

use App\Models\ChannelAccount;
use App\Models\Contact;
use App\Services\Messaging\OutboundInteractive;
use App\Services\Messaging\SendResult;

/**
 * سائق يعرض أزراراً وقوائم يضغطها العميل (المحادثة المباشرة).
 *
 * من لا يطبّقها يصله الخيار نصّاً (OutboundInteractive::fallbackText)،
 * فيكتب العميل عنوان الخيار ويطابقه الـ flow كما يطابق ضغطة الزرّ.
 */
interface SupportsInteractive
{
    public function sendInteractive(ChannelAccount $account, Contact $contact, OutboundInteractive $message, array $options = []): SendResult;
}
