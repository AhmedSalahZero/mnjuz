<?php

namespace App\Services\Messaging\Exceptions;

/**
 * بيانات ربط ناقصة أو خاطئة — يراها المستخدم في صفحة الإعدادات.
 * رسالتها موجّهة إليه لا إلى السجلّ، فتُكتب بلغة مفهومة.
 */
class ChannelConfigurationException extends ChannelException
{
}
