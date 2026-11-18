<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /** «مجمع تجديد الصحة والجمال الطبي» (id 295 على الإنتاج). */
    private const ORGANIZATION_UUID = 'fc83d605-9b9b-4725-a703-701abbf4ea96';

    /** أزرار مسار «ايقاف الاعلانات» عند هذه المنشأة التي يطلب بها العميل العروض. */
    private const RESUBSCRIBE_REPLIES = ['إعادة الإشتراك', 'اعادة الاشتراك', 'أبغى أستقبل عروضكم'];

    /** ما يعني الانسحاب في المسار نفسه: زرّ التأكيد، أو كلمة البدء زرّاً كانت أو نصّاً. */
    private const STOP_REPLIES = ['تأكيد إلغاء الإشتراك', 'ايقاف تلقي العروض', 'إيقاف تلقي العروض'];

    /**
     * إعادة من طلب العودة إلى العروض قبل وجود إجراء «إعادة الاشتراك في العروض».
     *
     * زرّ «إعادة الإشتراك» في مسار المنشأة كان يضيف العميل إلى مجموعة فقط،
     * والإضافة لا تمسح الانسحاب، فبقي خارج كل حملة رغم طلبه. يُمسح الانسحاب
     * هنا لمن كان آخرُ ما اختاره العودة — بعد آخر انسحاب له. ومن تساوى أمره
     * أو سبق انسحابُه الأخير عودتَه يبقى منسحباً: الشكّ لصالح الانسحاب.
     *
     * لا يُضاف إلى أي مجموعة: المنشأة حذفت مجموعاتها وبدأت من جديد.
     */
    public function up(): void
    {
        $organizationId = DB::table('organizations')->where('uuid', self::ORGANIZATION_UUID)->value('id');
        if ($organizationId === null) {
            return;
        }

        $optedOut = DB::table('contacts')
            ->where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->whereNotNull('marketing_opted_out_at')
            ->get(['id', 'marketing_opted_out_at']);

        foreach ($optedOut as $contact) {
            if (!$this->lastChoiceWasToResubscribe($contact->id, $contact->marketing_opted_out_at)) {
                continue;
            }

            DB::table('contacts')
                ->where('id', $contact->id)
                ->where('marketing_opted_out_at', $contact->marketing_opted_out_at)
                ->update(['marketing_opted_out_at' => null]);

            Log::info('Restored marketing for a contact who chose to resubscribe', [
                'organization_id' => $organizationId,
                'contact_id' => $contact->id,
                'opted_out_at' => (string) $contact->marketing_opted_out_at,
            ]);
        }
    }

    private function lastChoiceWasToResubscribe(int $contactId, string $optedOutAt): bool
    {
        $lastResubscribe = null;
        $lastStop = null;

        $replies = DB::table('chats')
            ->where('contact_id', $contactId)
            ->where('type', 'inbound')
            ->where('created_at', '>=', $optedOutAt)
            ->orderBy('id')
            ->get(['id', 'metadata', 'created_at']);

        foreach ($replies as $reply) {
            $metadata = json_decode((string) $reply->metadata, true) ?: [];
            $button = trim((string) ($metadata['interactive']['button_reply']['title'] ?? ''));
            $text = trim((string) ($metadata['text']['body'] ?? ''));

            if (in_array($button, self::RESUBSCRIBE_REPLIES, true)) {
                $lastResubscribe = $reply->id;
            }

            if (in_array($button, self::STOP_REPLIES, true) || in_array($text, self::STOP_REPLIES, true)) {
                $lastStop = $reply->id;
            }
        }

        // ترتيب الرسائل بمعرّفها لا بوقتها: ردّان في الثانية نفسها يتساويان وقتاً.
        return $lastResubscribe !== null && ($lastStop === null || $lastResubscribe > $lastStop);
    }

    /** إعادة الانسحاب غير ممكنة بأمان: لا يُعرف من انسحب بعدها من جديد. */
    public function down(): void
    {
    }
};
