<?php

namespace App\Services\ChatImport;

use App\Services\SubscriptionService;
use Illuminate\Support\Facades\DB;

/**
 * هل تتّسع باقة المنشأة لما سيُضيفه الاستيراد؟
 *
 * الرسائل أشدّ من جهات الاتصال: بلوغ حدّ الرسائل بالضبط يكفي ليقف الردّ
 * الآلي ورسائل خارج الدوام ويُقفل نموذج الإرسال (count >= limit في
 * isSubscriptionFeatureLimitReached). فاستيرادٌ يملأ الحدّ إلى آخره يوقف
 * عمل المنشأة كلّه — نرفضه. أمّا جهات الاتصال فبلوغ حدّها يمنع الإضافة
 * فقط، فيُسمح بالوصول إليه لا بتجاوزه.
 */
final class ChatImportLimits
{
    /** @return string|null رسالة الرفض، أو null إن اتّسعت الباقة. */
    public static function check(int $organizationId, int $newContacts, int $newMessages): ?string
    {
        if (!SubscriptionService::isSubscriptionActive((string) $organizationId)) {
            return __('Your subscription is not active. Renew it to import conversations.');
        }

        $contactLimit = SubscriptionService::featureLimit($organizationId, 'contacts_limit');
        if ($contactLimit !== null && $newContacts > 0) {
            $current = DB::table('contacts')->where('organization_id', $organizationId)->whereNull('deleted_at')->count();

            if ($current + $newContacts > $contactLimit) {
                return __('This file would create :new new contacts, but your plan allows only :available more (limit :limit). Upgrade your plan or import a smaller file.', [
                    'new' => number_format($newContacts),
                    'available' => number_format(max(0, $contactLimit - $current)),
                    'limit' => number_format($contactLimit),
                ]);
            }
        }

        $messageLimit = SubscriptionService::featureLimit($organizationId, 'message_limit');
        if ($messageLimit !== null && $newMessages > 0) {
            $current = DB::table('chats')->where('organization_id', $organizationId)->whereNull('deleted_at')->count();

            if ($current + $newMessages >= $messageLimit) {
                return __('This file has :new new messages, but your plan allows only :available more (limit :limit). Reaching the limit stops sending and auto-replies. Upgrade your plan or import a smaller file.', [
                    'new' => number_format($newMessages),
                    'available' => number_format(max(0, $messageLimit - $current - 1)),
                    'limit' => number_format($messageLimit),
                ]);
            }
        }

        return null;
    }
}
